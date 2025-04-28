<?php

\Bitrix\Main\Loader::includeModule('sale');

use Bitrix\Sale\Internals\BusinessValueTable;
use Bitrix\Main\SiteTable;

require_once 'classes/general/PaynetDebug.php';

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

$debugMode = \Bitrix\Sale\BusinessValue::get('PAYNET_DEBUG_MODE');

$debug = new Paynet\Pay\paynetDebug();
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/payneteasypayments.ecom/install/version.php';

$phpRequired = '7.4.33';
$phpCurrent = phpversion();
$phpCheck = version_compare($phpCurrent, $phpRequired, '>=') ? 'success': 'warning';

$bxRequired = '22.300.200';
$bxCurrent = SM_VERSION;
$bxCheck = version_compare($bxCurrent, $bxRequired, '>=') ? 'success': 'warning';

if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
) {
    if (isset($_GET['paynet']) && $_GET['paynet'] === 'delete') {
        LocalRedirect('/bitrix/admin/partner_modules.php', true);
    }

    if (isset($_GET['paynet']) && $_GET['paynet'] === 'report') {
        $debug->spReport();
        LocalRedirect(str_replace('&paynet=report', '', $_SERVER['REQUEST_URI']), true);
    }

    if (isset($_GET['paynet']) && $_GET['paynet'] === 'clear') {
        $debug->spClear();
        LocalRedirect(str_replace('&paynet=clear', '', $_SERVER['REQUEST_URI']), true);
    }
}
?>

<style>
.debug {
    border: 3px solid #a4b9cc;
    border-radius: .25rem;
    background: #f5f9f9;
    padding: .5rem;
    cursor: pointer;
}
#spoiler {
    position: fixed;
    top: -2rem;
    left: -2rem;
}
.spoiler-toggle {
    position: relative;
    width: calc(100% - 1rem);
    background: #a4b9cc;
    padding: 0.5rem;
    display: block;
}
.btn-mimik {
    display: inline-flex;
    align-items: center;
    border-radius: 4px;
    border: none;
    box-shadow: 0 0 1px rgba(0,0,0,.3), 0 1px 1px rgba(0,0,0,.3), inset 0 1px 0 #fff, inset 0 0 1px rgba(255,255,255,.5);
    background-color: #e0e9ec;
    background: linear-gradient(bottom, #d7e3e7, #fff)!important;
    color: #3f4b54;
    cursor: pointer;
    font-family: var(--ui-font-family-primary, var(--ui-font-family-helvetica));
    font-weight: var(--ui-font-weight-bold);
    font-size: 13px;
    height: 29px;
    text-shadow: 0 1px rgba(255,255,255,0.7);
    text-decoration: none;
    position: relative;
    vertical-align: middle;
    -webkit-font-smoothing: antialiased;
    padding: 0 13px 0px;
}
.btn-mimik:hover {
    text-decoration: none;
    background-color: #f3f6f7 !important;
    background-image: linear-gradient(top, #f8f8f9, #f2f6f8)!important;
}
b.required {
    font-size: 1rem;
    font-style: bold;
    color: #f00;
}
</style>

<div class="adm-detail-title">Справка</div>
    <div class="adm-detail-content-item-block">
        <table class="adm-detail-content-table edit-table" id="edit1_edit_table">
            <tbody>
                <tr>
                    <p class="mb-3">Для изменения настроек платежного шлюза перейдите в раздел:</p>
                </tr>
                <tr>
                    <p class="mb-3">
                        <strong>Рабочий стол</strong> &gt; <strong>Магазин</strong> &gt; <strong>Настройки</strong> &gt; <strong>Платёжные системы</strong> (можно перейти по <a href="/bitrix/admin/sale_pay_system.php">ссылке</a>)
                    </p>
                </tr>
                <tr>
                    <p class="mb-3">Выберите обработчик "PaynetEasy Payments" из блока "Системные"</p>
                    <p class="mb-3">
                        И нажмите "Добавить платежную систему".<br><br>
                        В нижней части страницы, в блоке "Настройки ПС" необходимо указать:
                    </p>
                    <ul>
                        <li><strong>Login</strong></li>
                        <li><strong>Control key</strong></li>
                        <li><strong>Endpoint Id</strong></li>
                    </ul>
                    <hr>
                    <p class="mb-3">Получить указанные данные можно после регистрации договора интернет-эквайринга</p>
                </tr>
                <tr>
                    <br>
                </tr>
                <tr>
                    <td colspan="2" id="paysystem-business-value-settings" style="padding: 10px 0">
                    <div class="adm-detail-content-item-block-view-tab">
                        <div id="map">
                            <div class="adm-info-message">
                                В случае возникновения проблем, перед обращением в поддержку скачайте отчет, это поможет проанализировать работу модуля и сайта в более оперативном режиме.
                            </div>
                            <div class="debug">
                                <div class="spoiler-toggle">Отладка</div>
                                <div class="debug-spoiler">
                                    <br>
                                    <div>
                                    <a class="btn-mimik blue" href="/bitrix/admin/settings.php?lang=ru&mid=payneteasypayments.ecom&paynet=report" download>Скачать отчет</a>
                                    <p>Сохранить отчет по состоянию модуля для передачи службе поддержки модуля</p>
                                </div>
                                <div>
                                    <a class="btn-mimik blue" href="/bitrix/admin/settings.php?lang=ru&mid=payneteasypayments.ecom&paynet=clear">Очистить логи</a>
                                    <p>Очистить лог-файлы, перед повторением ошибки</p>
                                </div>
                                <div>
                                    <a class="btn-mimik red" href="/bitrix/admin/settings.php?lang=ru&mid=payneteasypayments.ecom&paynet=delete">Переустановить модуль</a>
                                    <p>Перейти на страницу управления модулями из Маркетплейса, где можно будет удалить и установить модуль снова.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </tr>
            </tbody>
        </table>
    </div>
</div>
<?php
?>