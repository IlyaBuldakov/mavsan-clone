<?php
/**
 * ProtocolController.php
 * Date: 16.05.2017
 * Time: 16:09
 * Author: Maksim Klimenko
 * Email: mavsan@gmail.com
 */
declare(strict_types=1);

namespace Mavsan\LaProtocol\Http\Controllers;

use Auth;
use Exception;
use File;
use Gate;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Log;
use Mavsan\LaProtocol\Http\Controllers\Traits\ImportsCatalog;
use Mavsan\LaProtocol\Http\Controllers\Traits\SharesSale;
use Mavsan\LaProtocol\Interfaces\Import;
use Mavsan\LaProtocol\Interfaces\Info;
use Mavsan\LaProtocol\Model\FileName;
use Session;

class CatalogController extends BaseController
{
    protected Request $request;
    protected string $stepCheckAuth = 'checkauth';
    protected string $stepInit = 'init';

    protected string $stepFile = 'file';
    protected string $stepImport = 'import';
    protected string $stepInfo = 'info';
    protected string $stepDeactivate = 'deactivate';
    protected string $stepComplete = 'complete';

    protected string $stepQuery = 'query';
    protected string $stepSuccess = 'success';

    use SharesSale;
    use ImportsCatalog;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    protected function defaultType()
    {
        return config('protocolExchange1C.defaultType');
    }

    /**
     * Запись в лог данных запроса, если это необходимо
     *
     * @param $type
     * @param $mode
     */
    protected function logRequestData($type, $mode)
    {
        if (config('protocolExchange1C.logCommandsOf1C', false)) {
            Log::debug('Command from 1C type: '.$type.'; mode: '.$mode);
        }

        if (config('protocolExchange1C.logCommandsHeaders', false)) {
            Log::debug('Headers:');
            Log::debug($this->request->header());
        }

        if (config('protocolExchange1C.logCommandsFullUrl', false)) {
            Log::debug('Request: '.$this->request->fullUrl());
        }
    }

    /**
     * @return string|null
     */
    public function catalogIn(): ?string
    {
        $type = (string) $this->request->get('type');
        $mode = (string) $this->request->get('mode');

        if (! $type) {
            $type = $this->defaultType();
        }

        $this->logRequestData($type, $mode);

        if ($type != 'catalog' && $type != 'sale') {
            return $this->failure('invalid request type');
        }

        if (! $this->checkCSRF($mode)) {
            return $this->failure('CSRF token mismatch');
        }

        if (! $this->userLogin()) {
            return $this->failure('wrong username or password');
        } else {
            // после авторизации Laravel меняет id сессии, таким образом
            // при каждом запросе от 1С будет новая сессия и если что-то туда
            // записать то это будет потеряно, поэтому берем ИД сессии, который
            // был отправлен в 1С на этапе авторизации и принудительно устанавливаем
            $cookie = $this->request->header('cookie');
            $sessionName = config('session.cookie');
            if ($cookie
                && preg_match("/$sessionName=([^;\s]+)/", $cookie, $matches)) {
                // если убрать эту строчку и сделать вот так
                // session()->setId($matches[1]), то ИНОГДА o_O это приводит к
                // ошибке - говорит, что ничего не передано, хотя оно есть и
                // передается
                $id = $matches[1];
                session()->setId($id);
            } elseif ($id = $this->request->header($sessionName)) {
                session()->setId($id);
            }
        }

        switch ($mode) {
            case $this->stepCheckAuth:
                return $this->checkAuth($type);

            case $this->stepInit:
                return $this->init($type);

            case $this->stepFile:
                return $this->getFile();

            case $this->stepImport:
                try {
                    return $this->import();
                } catch (Exception $e) {
                    return $this->failure($e->getMessage());
                }

            case $this->stepInfo:
                return $this->getInfoModel()->info();

            case $this->stepDeactivate:
                $startTime = $this->getStartTime();

                return $startTime !== null
                    ? $this->importDeactivate($startTime)
                    : $this->failure('Cannot get start time of session, url: '.$this->request->fullUrl()."\nRegexp: (\d{4}-\d\d-\d\d)_(\d\d:\d\d:\d\d)");

            case $this->stepComplete:
                return $this->importComplete();

            case $this->stepQuery:
                return $this->processQuery();

            case $this->stepSuccess:
                if($type === 'sale') {
                    return $this->saleSuccess();
                }

                return '';
        }

        return $this->failure();
    }

    protected function getStartTime()
    {
        foreach (array_keys($this->request->all()) as $item) {
            if(preg_match("/(\d{4}-\d\d-\d\d)_(\d\d:\d\d:\d\d)/", $item, $matches)) {
                return "$matches[1] $matches[2]";
            }
        }

        return null;
    }

    /**
     * Проверка SCRF
     *
     * @return bool
     */
    protected function checkCSRF($mode)
    {
        if (!config('protocolExchange1C.isBitrixOn1C', false)
            || $mode === $this->stepCheckAuth) {
            return true;
        }

        // 1С-Битрикс пихает CSRF в любое место запроса, поэтому только перебором
        if (array_key_exists(Session::token(), $this->request->all())) {
            return true;
        }

        return false;
    }

    /**
     * Сообщение об ошибке
     *
     * @param string $details - детали, строки должны быть разделены /n
     *
     * @return string
     */
    protected function failure(string $details = '')
    {
        $return = "failure".(empty($details) ? '' : "\n$details");

        return $this->answer($return);
    }

    /**
     * Ответ серверу
     *
     * @param $answer
     *
     * @return string
     */
    protected function answer($answer): string
    {
        return iconv('UTF-8', 'windows-1251', $answer);
    }

    /**
     * Попытка входа
     * @return bool
     */
    protected function userLogin(): bool
    {
        if (Auth::getUser() === null) {
            $user = \Request::getUser();
            $pass = \Request::getPassword();

            $attempt = Auth::attempt(['email' => $user, 'password' => $pass]);

            if (! $attempt) {
                return false;
            }

            $gates = config('protocolExchange1C.gates', []);
            if (! is_array($gates)) {
                $gates = [$gates];
            }

            foreach ($gates as $gate) {
                if (Gate::has($gate) && Gate::denies($gate, Auth::user())) {
                    Auth::logout();

                    return false;
                }
            }

            return true;
        }

        return true;
    }

    /**
     * Авторизация 1с в системе
     *
     * @param string $type sale или catalog
     *
     * @return string
     */
    protected function checkAuth(string $type): string
    {
        $cookieName = config('session.cookie');

        if (! empty(config('protocolExchange1C.sessionID'))) {
            $cookieID = config('protocolExchange1C.sessionID');
            Session::setId($cookieID);
            Session::flush();
            Session::regenerateToken();
        } else {
            $cookieID = Session::getId();
        }

        $answer = "success\n$cookieName\n$cookieID";

        if (config('protocolExchange1C.isBitrixOn1C', false)) {
            if ($type === 'catalog') {
                $answer .= "\n".csrf_token()."\n".date('Y-m-d_H:i:s');
            } elseif ($type === 'sale') {
                $answer .= "\n".csrf_token();
            }
        }

        return $this->answer($answer);
    }

    /**
     * Инициализация соединения
     *
     * @param string $type sale или catalog
     *
     * @return string
     */
    protected function init(string $type): string
    {
        $zip = "zip=".($this->canUseZip() ? 'yes' : 'no');
        $limit = config('protocolExchange1C.maxFileSize');
        $answer = "$zip\nfile_limit=$limit";

        if (config('protocolExchange1C.isBitrixOn1C', false)) {
            if ($type === 'catalog' || $type === 'sale') {
                $answer .=
                    "\n".Session::getId().
                    "\n".config('protocolExchange1C.catalogXmlVersion');
            }
        }

        return $this->answer($answer);
    }

    /**
     * Можно ли использовать ZIP
     * @return bool
     */
    protected function canUseZip(): bool
    {
        return class_exists('ZipArchive');
    }

    /**
     * Получение файла(ов)
     * @return string
     */
    protected function getFile(): string
    {
        $modelFileName = new FileName($this->request->input('filename'));
        $fileName = $modelFileName->getFileName();

        if (empty($fileName)) {
            return $this->failure('Mode: '.$this->stepFile
                                  .', parameter filename is empty');
        }

        $fullPath = $this->getFullPathToFile($fileName, true);

        $fData = $this->getFileGetData();

        if (empty($fData)) {
            return $this->failure('Mode: '.$this->stepFile
                                  .', input data is empty.');
        }

        if ($file = fopen($fullPath, 'ab')) {
            $dataLen = mb_strlen($fData, 'latin1');
            $result = fwrite($file, $fData);

            if ($result === $dataLen) {
                // файлы, требующие распаковки
                $files = [];

                if ($this->canUseZip()) {
                    $files = session('inputZipped', []);
                    $files[$fileName] = $fullPath;
                }

                session(['inputZipped' => $files]);

                return $this->success();
            }

            $this->failure('Mode: '.$this->stepFile
                           .', can`t wrote data to file: '.$fullPath);
        } else {
            return $this->failure('Mode: '.$this->stepFile.', cant open file: '
                                  .$fullPath.' to write.');
        }

        return $this->failure('Mode: '.$this->stepFile.', unexpected error.');
    }

    /**
     * Формирование полного пути к файлу
     *
     * @param string $fileName
     * @param bool $clearOld
     *
     * @return string
     */
    protected function getFullPathToFile(string $fileName, bool $clearOld = false)
    {
        $workDirName = $this->checkInputPath();

        if ($clearOld) {
            $this->clearInputPath($workDirName);
        }

        $path = config('protocolExchange1C.inputPath');

        return $path.'/'.$workDirName.'/'.$fileName;
    }

    /**
     * Формирование имени папки, куда будут сохранятся принимаемые файлы
     * @return string
     */
    protected function checkInputPath()
    {
        $folderName = session('inputFolderName');

        if (empty($folderName)) {
            $folderName = date('Y-m-d_H-i-s').'_'.md5((string)time());

            $fullPath =
                config('protocolExchange1C.inputPath').DIRECTORY_SEPARATOR
                .$folderName;

            if (! File::isDirectory($fullPath)) {
                File::makeDirectory($fullPath, 0755, true);
            }

            session(['inputFolderName' => $folderName]);
        }

        return $folderName;
    }

    /**
     * Очистка папки, где хранятся входящие файлы от предыдущих принятых файлов
     *
     * @param $currentFolder
     */
    protected function clearInputPath($currentFolder)
    {
        $storePath = config('protocolExchange1C.inputPath');

        foreach (File::directories($storePath) as $path) {
            if (File::basename($path) != $currentFolder) {
                File::deleteDirectory($path);
            }
        }
    }

    /**
     * получение контента файла
     *
     * @return string
     */
    protected function getFileGetData()
    {
        /*if (function_exists("file_get_contents")) {
            $fData = file_get_contents("php://input");
        } elseif (isset($GLOBALS["HTTP_RAW_POST_DATA"])) {
            $fData = &$GLOBALS["HTTP_RAW_POST_DATA"];
        } else {
            $fData = '';
        }

        if (\App::environment('testing')) {
            $fData = \Request::getContent();
        }

        return $fData;
        */

        return \Request::getContent();
    }

    /**
     * Отправка ответа, что все в порядке
     * @return string
     */
    protected function success()
    {
        return $this->answer('success');
    }

    protected function getInfoModel()
    {
        $modelCLass = config('protocolExchange1C.infoModel');
        // проверка модели
        if (empty($modelCLass)) {
            return $this->failure('Mode: '.$this->stepInfo
                .', please set model to import data in infoModel key.');
        }

        /** @var Info $model */
        $model = App::make($modelCLass);
        if (! $model instanceof Info) {
            return $this->failure('Mode: '.$this->stepInfo.' model '
                .$modelCLass
                .' must implement \Mavsan\LaProtocol\Interfaces\Info');
        }

        return $model;
    }
}
