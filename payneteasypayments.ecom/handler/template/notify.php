<?php
use Bitrix\Main\Localization\Loc;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

IncludeModuleLangFile(__FILE__);

?>
<script>
    let notifyContent;
    let notifyContent = "<img src='/bitrix/images/sale/sale_payments/payneteasypayments.png' width='14'> <?=CUtil::JSEscape(htmlspecialcharsbx($params['text']))?>";
    if (BX('.sale-order-detail-payment-options-methods-info-title-status-success')) {
        let notifyContent = "<img src='/bitrix/images/sale/sale_payments/payneteasypayments.png' width='14'> <?=CUtil::JSEscape(htmlspecialcharsbx($params['text']))?>";
    } else {
        let notifyContent = "<img src='/bitrix/images/sale/sale_payments/payneteasypayments.png' width='14'> <?=CUtil::JSEscape(htmlspecialcharsbx($params['text']))?>";
    }
    BX.UI.Notification.Center.notify({
        content: notifyContent
    });
</script>