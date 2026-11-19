<?php

use Bitrix\Main\Application;
use Bitrix\Main\Localization\Loc;

IncludeModuleLangFile(__FILE__);

class payneteasypayments_ecom extends CModule
{
    var $MODULE_ID = 'payneteasypayments.ecom';
    var $MODULE_SALE = 'payneteasypayments';
    var $MODULE_VERSION;
    var $MODULE_VERSION_DATE;
    var $MODULE_GROUP_RIGHTS = 'N';
    var $MODULE_DEBUG = 'Y';
    var $MODULE_STATUSES;

    function __construct()
    {
        $arModuleVersion = [];

        // If BX_ROOT is not defined
        if (!defined('BX_ROOT')) {
            if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/bx_root.php')) {
                require '/bitrix/modules/main/bx_root.php';
            } else {
                define('BX_ROOT', '/bitrix');
            }
        }

        include __DIR__ . '/version.php';

        if (is_array($arModuleVersion) && array_key_exists('VERSION', $arModuleVersion)) {
            $this->MODULE_VERSION = $arModuleVersion['VERSION'];
            $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
        }

        $this->MODULE_NAME = Loc::getMessage('PAYNET_MODULE_NAME');
        $this->MODULE_DESCRIPTION = Loc::getMessage('PAYNET_MODULE_DESCRIPTION');

        $this->PARTNER_NAME = Loc::getMessage('PAYNET_MODULE_AUTHOR');
        $this->PARTNER_URI = Loc::getMessage('PAYNET_MODULE_LINK');

        $this->MODULE_STATUSES = [
            [
                'ID' => 'VP', // PAYNET Pending status
                'SORT' => 100,
                'LANG' => [
                    [
                        'LID' => 'en',
                        'NAME' => 'PAYNET: Pending'
                    ], [
                        'LID' => 'ru',
                        'NAME' => Loc::getMessage('PAYNET_ORDER_STATUS_VP')
                    ]
                ]
            ], [
                'ID' => 'VR', // PAYNET Refund
                'SORT' => 101,
                'LANG' => [
                    [
                        'LID' => 'en',
                        'NAME' => 'PAYNET: Refund'
                    ], [
                        'LID' => 'ru',
                        'NAME' => Loc::getMessage('PAYNET_ORDER_STATUS_VR')
                    ]
                ]
            ], [
                'ID' => 'VC', // PAYNET Refund
                'SORT' => 102,
                'LANG' => [
                    [
                        'LID' => 'en',
                        'NAME' => 'PAYNET: Cancel'
                    ], [
                        'LID' => 'ru',
                        'NAME' => Loc::getMessage('PAYNET_ORDER_STATUS_VC')
                    ]
                ]
            ]
        ];
    }

    function DoInstall()
    {
        global $APPLICATION;

        if (!IsModuleInstalled($this->MODULE_ID)) {
            RegisterModule($this->MODULE_ID);

            $this->InstallFiles();

            $this->InstallOrderStatus();

            $connection = \Bitrix\Main\Application::getConnection();
            $sql = "CREATE TABLE IF NOT EXISTS payneteasy_payments (id int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, paynet_order_id bigint unsigned NOT NULL, merchant_order_id int NOT NULL, redirect_url varchar(1024) DEFAULT NULL, INDEX merchant_order_id (merchant_order_id))";
            $connection->query($sql);

            $APPLICATION->ThrowException(Loc::getMessage("PAYNET_SUCCESS_INSTALL"));

            $this->paynetLoggerInstall('Module register', 'DoInstall');
        } else {
            $APPLICATION->ThrowException(Loc::getMessage("PAYNET_ERROR_INSTALL"));

            $this->paynetLoggerInstall('Module do not register', 'DoInstall');
        }
    }

    function InstallFiles($arParams = [])
    {
        // HANDLER MOVED
        $psLinkInstallComplete = CopyDirFiles(__DIR__ . BX_ROOT, Application::getDocumentRoot() . BX_ROOT, true, true);
        $this->paynetLoggerInstall('Files copying: ' . $psLinkInstallComplete, 'InstallFiles()');
        if (!$psLinkInstallComplete) {
            echo \CAdminMessage::ShowMessage(Loc::getMessage('PAYNET_MODULE_INSTALL_HANDLER_ERROR'));
        }

        CopyDirFiles($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/payneteasypayments.ecom/install/images", $_SERVER["DOCUMENT_ROOT"]."/bitrix/images/sale/sale_payments");

        // MEDIA MOVED
        $psLinkMediaComplete = CopyDirFiles(__DIR__ . "/install/images", $this->MODULE_MEDIA_PATH, true, true);
        $this->paynetLoggerInstall('Media copying: ' . $psLinkMediaComplete, 'InstallFiles()');
        if (!$psLinkMediaComplete) {
            echo \CAdminMessage::ShowMessage(Loc::getMessage('PAYNET_MODULE_INSTALL_MEDIA_ERROR'));
        }

        $this->paynetLoggerInstall('Method finish', 'InstallFiles');
    }

    function InstallOrderStatus()
    {
        if (!CModule::IncludeModule("sale")) {
            $this->paynetLoggerInstall('Sale module not included', 'InstallOrderStatus');
            return false;
        }

        foreach ($this->MODULE_STATUSES as $status) {
            CSaleStatus::Add($status);
            $this->paynetLoggerInstall($status, 'InstallOrderStatus');
        }
    }

    function DoUninstall()
    {
        UnRegisterModule($this->MODULE_ID);

        $this->UnInstallFiles();

        $this->UnInstallStatuses();

        $this->UnInstallBussVal();

        $connection = \Bitrix\Main\Application::getConnection();
        $sql = "DROP TABLE `payneteasy_payments`;";
        $connection->query($sql);

        $this->paynetLoggerInstall('Module unregister', 'DoUninstall');

        return true;
    }

    function UnInstallFiles()
    {
        // Handlers
        DeleteDirFilesEx($_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/' . $this->MODULE_SALE . '/');
        // Logotype
        DeleteDirFilesEx($_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/' . $this->MODULE_SALE . '.png');
        // Customer return script
        DeleteDirFilesEx(BX_ROOT . '/tools/payneteasy_return.php');

        return true;
    }

    function UnInstallStatuses()
    {
        if (!CModule::IncludeModule("sale")) {
            return false;
        }

        foreach ($this->MODULE_STATUSES as $status) {
            CSaleStatus::Delete($status['ID']);
            $this->paynetLoggerInstall($status, 'UnInstallStatuses');
        }
    }

    function UnInstallBussVal()
    {
        $optionList = [
            'PAYNET_LOGIN',
            'PAYNET_CONTROL_KEY',
            'PAYNET_ENDPOINT_ID',
            'PAYNET_SANDBOX',
            'PAYNET_ORDER_STATUS',
            'PAYNET_PAYMENT_METHOD',
            'PAYNET_THREE_D_SECURE',
            'PAYNET_LIVE_URL',
            'PAYNET_SANDBOX_URL',
        ];
        foreach ($optionList as $option) {
            COption::RemoveOption('payneteasypayments.ecom', $option);
        }

        $this->paynetLoggerInstall('BusinessValue removed', 'UnInstallBussVal');
    }

    private function paynetLoggerInstall($data = null, $method = null)
    {
        $date = date('[Y-m-d H:i:s]');
        $string = "{$date} ";

        if (!empty($method)) {
            $string .= "{$method} ";
        }

        if (is_string($data)) {
            $string .= $data;
        } else if (is_array($data) || is_object($data)) {
            $string .= json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $string .= 'data is empty';
        }

        if (!file_exists($_SERVER['DOCUMENT_ROOT'] . BX_ROOT . "/modules/payneteasypayments.ecom/logs")) {
            mkdir($_SERVER['DOCUMENT_ROOT'] . BX_ROOT . "/modules/payneteasypayments.ecom/logs", 0755, true);
        }

        $logFile = $_SERVER['DOCUMENT_ROOT'] . BX_ROOT . "/modules/payneteasypayments.ecom/logs/install.log";
        file_put_contents($logFile, $string . PHP_EOL, FILE_APPEND);
    }
}