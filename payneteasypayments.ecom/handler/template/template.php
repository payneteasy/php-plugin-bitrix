<?php
use Bitrix\Main\Localization\Loc;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

IncludeModuleLangFile(__FILE__);

if ($params['WINDOW']) {
    require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
}

if (!empty($params['NOTICE'])) { ?>
    <p class="payneteasy-notice mb-2"><b><?= Loc::getMessage('PAYNET_TEMPLATE_NOTICE_' . $params['NOTICE'], ['#ORDER_ID#' => (int)$params['ORDER_ID']]) ?></b></p>
<?php }

switch ($params['ERROR']) {
    case 'PAID': ?>
    <div class="payneteasy-result mb-2">
        <p><b><?= Loc::getMessage('PAYNET_TEMPLATE_ORDER_PAID', ['#ORDER_ID#' => (int)$params['ORDER_ID']]) ?></b></p>
        <p><?= Loc::getMessage('PAYNET_TEMPLATE_PAID_AMOUNT') ?> <strong><?= $params['AMOUNT'] ?></strong></p>
        <p><a href="/personal/"><?= Loc::getMessage('PAYNET_TEMPLATE_PERSONAL_LINK') ?></a></p>
    </div>
<?php break; case 'OK': ?>
    <form action="<?= htmlspecialcharsbx($params['ACTION_URL']); ?>" method="<?= $params['PAYNET_PAYMENT_METHOD'] == 'direct' ? 'post' : 'get' ?>" class="payneteasy-form mb-2">
        <?php if ($params['PAYNET_PAYMENT_METHOD'] == 'direct') { ?>
            <div class="paynet_direct_form">
                <div class="" style="padding: 5px;">
                    <label for="credit_card_number"><?=Loc::getMessage('PAYNET_TEMPLATE_PAYMENT_CARD_NUMBER')?></label>
                    <input id="credit_card_number" name="credit_card_number" type="text" autocomplete="cc-number" required="required">
                </div>
                <div class="" style="padding: 5px;">
                    <label for="card_printed_name"><?=Loc::getMessage('PAYNET_TEMPLATE_PAYMENT_PRINTED_NAME')?></label>
                    <input id="card_printed_name" name="card_printed_name" type="text" autocomplete="cc-name" placeholder="<?=Loc::getMessage('PAYNET_TEMPLATE_PAYMENT_PRINTED_NAME_PLACEHOLDER')?>" required="required">
                </div>
                <div class="" style="padding: 5px;">
                    <label for="expire_month"><?=Loc::getMessage('PAYNET_TEMPLATE_PAYMENT_EXPIRY_MONTH')?></label>
                    <input minlength="2" maxlength="2" name="expire_month" id="expire_month" type="text" autocomplete="off" placeholder="MM" style="max-width: 50%" required="required">
                    <label for="expire_year" class="required"><?=Loc::getMessage('PAYNET_TEMPLATE_PAYMENT_EXPIRY_YEAR')?></label>
                    <input minlength="4" maxlength="4" name="expire_year" id="expire_year" type="text" autocomplete="off" placeholder="YYYY" style="max-width: 50%" required="required">
                </div>
                <div class="" style="padding: 5px;">
                    <label for="cvv2"><?=Loc::getMessage('PAYNET_TEMPLATE_PAYMENT_CVC')?></label>
                    <input minlength="3" maxlength="4" name="cvv2" id="cvv2" autocomplete="cc-csc" type="password" style="max-width: 50%" required="required">
                </div>
            </div>
            <input type="hidden" name="ORDER_ID" value="<?= htmlspecialcharsbx($_GET['ORDER_ID'] ?? '') ?>">
            <?php } ?>
        <?php foreach ($params['PARAMS'] as $name => $value): ?>
            <input type="hidden" name="<?= htmlspecialcharsbx($name) ?>" value="<?= htmlspecialcharsbx($value) ?>">
        <?php endforeach; ?>
        <p class="payneteasy-titlemb-2"><?=Loc::getMessage('PAYNET_TEMPLATE_PAYMENT_MADE_BY')?> "<b><?=Loc::getMessage('PAYNET_TEMPLATE_HANDLER_NAME')?></b>".</p>
        <p class="payneteasy-amount mb-2"><?=Loc::getMessage('PAYNET_TEMPLATE_AMOUNT_TO_PAY')?> <strong><?= $params['AMOUNT'] ?></strong></p>
        <input type="submit" value="<?=Loc::getMessage('PAYNET_TEMPLATE_NEXT_BUTTON')?>" class="payneteasy-btn btn btn-primary btn-sm mb-2">
        <p class="payneteasy-notice"><?=Loc::getMessage('PAYNET_TEMPLATE_REDIRECT_DESCRIPTION')?></p>
    </form>
<?php break; case 'CREDENTAIL': ?>
    <p class="payneteasy-error m-0"><?=Loc::getMessage('PAYNET_TEMPLATE_ERROR_CREDENTIAL')?></p>
<?php break; case 'REQUEST': ?>
    <p class="payneteasy-error m-0"><?=Loc::getMessage('PAYNET_TEMPLATE_ERROR_REQUEST')?></p>
<?php break; case 'GLOBAL': ?>
    <p class="payneteasy-error m-0"><?=Loc::getMessage('PAYNET_TEMPLATE_ERROR_GLOBAL')?></p>
<?php break; default: ?>
    <p class="payneteasy-error m-0"><?=Loc::getMessage('PAYNET_TEMPLATE_ERROR_DEFAULT')?></p>
<?php break;
}

if ($params['WINDOW']) {
    require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");
}