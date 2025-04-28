<?php
use Bitrix\Main\Localization\Loc;

$email = \COption::GetOptionString("main", "email_from");

IncludeModuleLangFile(__FILE__);

?>
<p class="payneteasy-error mb-2">
    <?=Loc::getMessage('PAYNET_WARNING_PLEASE_CHECK_PARAMETRS')?>
</p>
<p class="payneteasy-error mb-2">
    <?=Loc::getMessage('PAYNET_WARNING_IF_SITUATION_AS_REPEATED')?> <a href="mailto:<?=$email?>"><?=Loc::getMessage('PAYNET_WARNING_ADMIN_MAIL')?></a>.
</p>
<?php die;