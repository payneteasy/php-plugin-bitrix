<?php

namespace Paynet\Pay;

use Bitrix\Main\Config\Option;

class paynetDebug {
    private $salePath;
    private $decline = 'default';

    public function __construct() {
        // Clear static cache
        clearstatcache();

        $this->salePath = Option::get('sale', 'path2user_ps_files');
        if ($this->salePath != '/bitrix/php_interface/include/sale_payment/') {
            $this->decline = 'custom';
        }
    }

    /**
     * @param $short
     * @param $plain
     * @return array|bool
     */
    public function spChmod($short = 0, $plain = false)
    {
        $shortState = 0;

        $salePayments = [
            '/bitrix/',
            '/bitrix/modules/sale/',
            '/bitrix/modules/sale/handlers/',
            '/bitrix/modules/sale/sale_payment/',
            '/bitrix/images/',
            '/bitrix/images/sale/',
            '/bitrix/images/sale/sale_payments/',
        ];

        $buff = [];
        foreach ($salePayments as $sp) {
            $perm = $this->perm($_SERVER['DOCUMENT_ROOT'] .$sp, $plain);
            $buff[$sp] = $perm;

            if ($perm['char'] == 'drwxr-xr-x' || $perm['chmod'] == '755') {
                $shortState++;
            }
        }

        if ($this->decline === 'custom') {
            return ['custom' => 'custom'];
        }

        if ($shortState) {
            return true;
        }

        return $buff;
    }

    /**
     * @param $short
     * @param $plain
     * @return array|bool|null
     */
    public function spDirs($short = 0, $plain = false)
    {
        $shortState = 0;

        $salePayment = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/sale/';
        if (file_exists($salePayment)) {
            $spDirs = @array_diff(@scandir($salePayment), ['.','..']);
            if (!empty($spDirs) && is_array($spDirs)) {
                $buff = [];
                foreach ($spDirs as $sp) {
                    $perm = $this->perm($salePayment . $sp, $plain);
                    $buff[$sp] = $perm;

                    if ($perm['char'] == 'drwxr-xr-x' || $perm['chmod'] == '755') {
                        $shortState++;
                    }
                }
            } else {
                $buff[$spDirs] = $this->perm($salePayment . $spDirs, $plain);
            }

            if ($this->decline === 'custom') {
                return ['custom' => 'custom'];
            }

            if ($shortState) {
                return true;
            }

            return $buff;
        } else {
            return null;
        }
    }

    /**
     * @param $short
     * @param $plain
     * @return array|string[]|true|void|null
     */
    public function spLinks($short = 0, $plain = false)
    {
        $shortState = 0;

        $salePayment = $_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/modules/sale/handlers/payneteasypayments/';
        if (file_exists($salePayment)) {
            $spFolder = @array_diff(@scandir($salePayment), ['.','..']);
            if (!empty($spFolder)) {
                $buff = [];
                foreach ($spFolder as $sp) {
                    $perm = $this->perm($salePayment . $sp, $plain);
                    $buff[$sp] = $perm;

                    if ($perm['char'] == 'drwxr-xr-x' || $perm['chmod'] == '755') {
                        $shortState++;
                    }
                }

                if ($this->decline === 'custom') {
                    return ['custom' => 'custom'];
                }

                if ($shortState) {
                    return true;
                }

                return $buff;
            }
        } else {
            return null;
        }
    }

    /**
     * @param $target
     * @param $plain
     * @return array
     */
    private function perm($target, $plain = false): array
    {
        $perms = fileperms($target);
        switch ($perms & 0xF000) {
            case 0xC000: $info = 's'; break; // socket
            case 0xA000: $info = 'l'; break; // symbolic link
            case 0x8000: $info = 'r'; break; // regular file
            case 0x6000: $info = 'b'; break; // block device
            case 0x4000: $info = 'd'; break; // directory
            case 0x2000: $info = 'c'; break; // character device
            case 0x1000: $info = 'p'; break; // FIFO pipe
            default: $info = 'u'; // unknown
        }

        $chmod = sprintf('%o', $perms & 0777);


        // Owner
        $info .= (($perms & 0x0100) ? 'r' : '-');
        $info .= (($perms & 0x0080) ? 'w' : '-');
        $info .= (($perms & 0x0040) ? (($perms & 0x0800) ? 's' : 'x' ) : (($perms & 0x0800) ? 'S' : '-'));

        // Group
        $info .= (($perms & 0x0020) ? 'r' : '-');
        $info .= (($perms & 0x0010) ? 'w' : '-');
        $info .= (($perms & 0x0008) ? (($perms & 0x0400) ? 's' : 'x' ) : (($perms & 0x0400) ? 'S' : '-'));

        // Others
        $info .= (($perms & 0x0004) ? 'r' : '-');
        $info .= (($perms & 0x0002) ? 'w' : '-');
        $info .= (($perms & 0x0001) ? (($perms & 0x0200) ? 't' : 'x' ) : (($perms & 0x0200) ? 'T' : '-'));


        if (!$plain) {
            $infoClass = 'warning';
            if ($info == 'drwxr-xr-x' || $info == 'rrw-r--r--') {
                $infoClass = 'success';
            }

            $info = "<b class=\"$infoClass\">{$info}</b>";

            $chmodClass = 'warning';
            if ($chmod == 644 || $chmod == 755) {
                $chmodClass = 'success';
            }

            $chmod = "<b class=\"$chmodClass\">{$chmod}</b>";
        }

        return ['char' => $info, 'chmod' => $chmod];
    }

    /**
     * @return array|string[]
     */
    public function spSearch(): array
    {
        $paths = [
            BX_ROOT . '/modules/payneteasypayments.ecom',
            BX_ROOT . '/modules/sale',
            BX_ROOT . '/modules/sale/handlers',
            BX_ROOT . '/modules/sale/handlers/paysystem',
            BX_ROOT . '/modules/sale/handlers/paysystem/payneteasypayments',
            BX_ROOT . '/modules/sale/handlers/paysystem/payneteasypayments/.description.php',
            BX_ROOT . '/modules/sale/handlers/paysystem/payneteasypayments/handler.php',
            BX_ROOT . '/modules/sale/handlers/paysystem/payneteasypayments/template/notify.php',
            BX_ROOT . '/modules/sale/handlers/paysystem/payneteasypayments/template/template.php',
            BX_ROOT . '/modules/sale/payment',
            BX_ROOT . '/modules/sale/payment/payneteasypayments',
            BX_ROOT . '/modules/sale/payment/payneteasypayments/.description.php',
            BX_ROOT . '/modules/sale/payment/payneteasypayments/payment.php',
            BX_ROOT . '/modules/sale/payment/payneteasypayments/result_rec.php',
            BX_ROOT . '/images',
            BX_ROOT . '/images/sale',
            BX_ROOT . '/images/sale/sale_payments',
            BX_ROOT . '/images/sale/sale_payments/payneteasypayments.png',
        ];

        require_once 'PaynetSearcher.php';
        $search = new PaynetSearcher(true);

        $buff = [];
        foreach ($paths as $target => $path) {
            $check = $search->stepSearch($path);

            if ($target < 10) {
                $target = '0' . $target;
            }

            if ($check) {
                $buff[$target] = [
                    'found' => 'true',
                    'valid' => $path,
                ];
            } else {
                $buff[$target] = [
                    'found' => 'false',
                    'valid' => $path,
                ];
            }
        }

        if ($this->decline === 'custom') {
            return ['custom' => 'custom'];
        }

        return $buff;
    }

    /**
     * @param $short
     * @return array|int
     */
    public function spSites($short = 0)
    {
        $shortState = 0;
        $sites = \CSite::GetList(...['ID']);
        $buff = [];

        while ($site = $sites->Fetch()) {
            $buff[] = $site;
            if ($short) {
                $shortState++;
            }
        }

        if ($this->decline === 'custom') {
            return ['custom' => 'custom'];
        }

        if ($short) {
            return $shortState;
        }

        return $buff;
    }

    /**
     * @return array|null
     */
    public function spLogs(): ?array
    {
        $root = $_SERVER['DOCUMENT_ROOT'];
        $path = '/bitrix/modules/payneteasypayments.ecom/logs';
        if (file_exists($root . $path)) {
            $files = @array_diff(@scandir($root . $path), ['.','..']);

            $buff = [];
            foreach($files as $file) {
                $buff["{$path}/{$file}"] = "<a href=\"{$path}/{$file}\" download class=\"\">{$file}</a>";
            }

            return $buff;
        } else {
            return null;
        }
    }

    /**
     * @param $data
     * @param $method
     * @return void
     */
    private function paynetLoggerDebug($data = null, $method = null): void
    {
        $date = date('[Y-m-d H:i:s]');
        $string = "{$date} ";
        if (is_string($data)) {
            $string .= $data;
        } else if (is_array($data) || is_object($data)) {
            $string .= json_encode($data, JSON_UNESCAPED_UNICODE);
        } else {
            $string .= 'data is empty';
        }

        if (!empty($method)) {
            $string .= " {$method}";
        }

        $logFile = $_SERVER['DOCUMENT_ROOT'] . BX_ROOT . "/modules/payneteasypayments.ecom/logs/debug.log";
        file_put_contents($logFile, $string . PHP_EOL, FILE_APPEND);
    }

    /**
     * @return void
     */
    public function spReport(): void
    {
        require $_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/modules/payneteasypayments.ecom/install/version.php';

        $salePath = file_exists($_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/modules/sale/handlers');

        $phpRequired = '7.4.33';
        $phpCurrent  = phpversion();
        $phpCheck    = version_compare($phpCurrent, $phpRequired, '>=') ? 'success': 'warning';

        $bxRequired  = '22.300.200';
        $bxCurrent   = SM_VERSION;
        $bxCheck     = version_compare($bxCurrent, $bxRequired, '>=') ? 'success': 'warning';

        $dirState    = $this->spDirs(1,1)  ? 'Да':'Нет';
        $linkState   = $this->spLinks(1,1) ? 'Да':'Нет';
        $fileState   = $this->spChmod(1,1) ? 'Да':'Нет';
        $psState     = $this->spLinks(1,1) ? 'Да':'Нет';
        $siteCount   = $this->spSites(1);
        $date        = date('d.m.Y');

        $buff        = "PHP: {$phpCurrent} | {$phpCheck}\nBTRX: {$bxCurrent} | $bxCheck\nMODULE: {$arModuleVersion['VERSION']}\nPATHS: {$dirState}\nLINKS: {$linkState}\nFILES: {$fileState}\nPS: {$psState}\nSITES: {$siteCount}\nDATE: {$date}\nSALE: {$salePath}" . PHP_EOL . PHP_EOL;


        if ($this->decline !== 'custom') {
            foreach($this->spSearch() as $key => $value) {
                $buff .= "{$key} | {$value['valid']} | {$value['found']}" . PHP_EOL;
            }
            $buff .= PHP_EOL;
            foreach($this->spChmod(0,1) as $key => $value) {
                $buff .= "{$key} | {$value['char']} | {$value['chmod']}" . PHP_EOL;
            }
            $buff .= PHP_EOL;
            foreach($this->spDirs(0,1) as $key => $value) {
                $buff .= "{$key} | {$value['char']} | {$value['chmod']}" . PHP_EOL;
            }
            $buff .= PHP_EOL;
            foreach($this->spLinks(0,1) as $key => $value) {
                $buff .= "{$key} | {$value['char']} | {$value['chmod']}" . PHP_EOL;
            }
        }

        $logPath = $_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/modules/payneteasypayments.ecom/logs';
        $logs = array_diff(scandir($logPath), ['.','..']);

        if (!empty($logs)) {
            foreach($logs as $log) {
                $buff .= PHP_EOL . $log . PHP_EOL . file_get_contents($logPath . DIRECTORY_SEPARATOR . $log);
            }
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="paynet.report.' . $arModuleVersion['VERSION'] . '.log"');
        die($buff);
    }

    /**
     * @return void
     */
    public function spSend(): void
    {
        $lock = file_exists($_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/tmp/paynet.lock') ?
                file_get_contents($_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/tmp/paynet.lock') : false;
        if (($lock + 43200) > time()) {
            echo \CAdminMessage::ShowMessage([
                'MESSAGE' => 'Отправка логов',
                'TYPE' => 'ERROR',
                'DETAILS' => 'Повторно отправить логи можно будет через 12 часов с момента предыдущей отправки',
                'HTML' => true,
            ]);
        } else {
            $subject = "CMS-PLUGIN-REPORT";
            $message = '1C BITRIX';
            $to = "it@пейнетизи.рф";
            try { $host = PaynetHandler::getSiteHost(); } catch (\Throwable $e) { $host = "localhost"; }
            $from = "noreply@{$host}";

            $headers = [
                "From" => $from,
                "Reply-To" => $from,
                "Content-Type" => "text/html; charset=utf-8"
            ];

            $logsPath = $_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/modules/payneteasypayments.ecom/logs';
            $logs = array_diff(scandir($logsPath), ['.','..']);
            if (!empty($logs)) {
                foreach ($logs as $log) {
                    $message .= PHP_EOL . $log . PHP_EOL;
                    $message .= file_get_contents($logsPath . DIRECTORY_SEPARATOR . $log);
                }
            }

            // lock many sends
            file_put_contents($_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/tmp/paynet.lock', time());

            // Send the email
            if(mail($to, $subject, $message, $headers)) {
                echo \CAdminMessage::ShowMessage([
                    'MESSAGE' => 'Отправка логов',
                    'TYPE' => 'OK',
                    'DETAILS' => 'Данные успешно отправлены',
                    'HTML' => true,
                ]);
            } else {
                echo \CAdminMessage::ShowMessage([
                    'MESSAGE' => 'Отправка логов',
                    'TYPE' => 'ERROR',
                    'DETAILS' => 'На сайте есть ограничения на использование PHP функции mail(), письмо не удается отправить',
                    'HTML' => true,
                ]);
            }
        }
    }

    /**
     * @return void
     */
    public function spClear(): void
    {
        $logsPath = $_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/modules/payneteasypayments.ecom/logs';
        $logs = array_diff(scandir($logsPath), ['.','..']);
        if (!empty($logs)) {
            foreach ($logs as $log) {
                file_put_contents($logsPath . DIRECTORY_SEPARATOR . $log, '');
            }
        }
    }
}