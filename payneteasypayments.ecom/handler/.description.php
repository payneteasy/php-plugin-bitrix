<?php

use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc,
    Bitrix\Sale\Tax;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

IncludeModuleLangFile(__FILE__);

$orderStatuses = [];
$getOrderStatuses = CSaleStatus::GetList();
while ($status = $getOrderStatuses->Fetch()) {
    $orderStatuses[$status['ID']] = "[{$status['ID']}] {$status['NAME']}";
}

$paymentMethods = [
    'form' => 'Form',
    'direct' => 'Direct'
];

$data = [
    'NAME' => Loc::getMessage('PAYNET_PAYMENT_NAME'),
    'CODES' => [
        'PAYNET_SANDBOX' => [
            'NAME' => Loc::getMessage('PAYNET_SANDBOX_TITLE'),
            'DESCRIPTION' => Loc::getMessage('PAYNET_SANDBOX_DESCRIPTION'),
            'SORT' => 100,
            'GROUP' => Loc::getMessage('PAYNET_PAYMENT_GROUP'),
            'INPUT' => [
                'TYPE' => 'Y/N',
                'DEFAULT' => 'Y'
            ]
        ],
        'PAYNET_DEBUG_MODE' => [
            'NAME' => Loc::getMessage('PAYNET_DEBUG_TITLE'),
            'DESCRIPTION' => Loc::getMessage('PAYNET_DEBUG_DESCRIPTION'),
            'SORT' => 101,
            'GROUP' => Loc::getMessage('PAYNET_PAYMENT_GROUP'),
            'INPUT' => [
                'TYPE' => 'Y/N',
                'DEFAULT' => 'Y'
            ]
        ],
        'PAYNET_LOGIN' => [
            'NAME' => Loc::getMessage('PAYNET_LOGIN_TITLE'),
            'DESCRIPTION' => Loc::getMessage('PAYNET_LOGIN_DESCRIPTION'),
            'SORT' => 102,
            'GROUP' => Loc::getMessage('PAYNET_PAYMENT_GROUP'),
            'DEFAULT' => [
                'PROVIDER_KEY' => 'VALUE',
                'PROVIDER_VALUE' => ''
            ]
        ],
        'PAYNET_CONTROL_KEY' => [
            'NAME' => Loc::getMessage('PAYNET_CONTROL_KEY_TITLE'),
            'DESCRIPTION' => Loc::getMessage('PAYNET_CONTROL_KEY_DESCRIPTION'),
            'SORT' => 103,
            'GROUP' => Loc::getMessage('PAYNET_PAYMENT_GROUP'),
            'DEFAULT' => [
                'PROVIDER_KEY' => 'VALUE',
                'PROVIDER_VALUE' => ''
            ]
        ],
        'PAYNET_ENDPOINT_ID' => [
            'NAME' => Loc::getMessage('PAYNET_ENDPOINT_ID_TITLE'),
            'DESCRIPTION' => Loc::getMessage('PAYNET_ENDPOINT_ID_DESCRIPTION'),
            'SORT' => 104,
            'GROUP' => Loc::getMessage('PAYNET_PAYMENT_GROUP'),
            'DEFAULT' => [
                'PROVIDER_KEY' => 'VALUE',
                'PROVIDER_VALUE' => ''
            ]
        ],
        'PAYNET_ORDER_STATUS' => [
            'NAME' => Loc::getMessage('PAYNET_ORDER_STATUS_TITLE'),
            'DESCRIPTION' => Loc::getMessage('PAYNET_ORDER_STATUS_DESCRIPTION'),
            'SORT' => 105,
            'GROUP' => Loc::getMessage('PAYNET_PAYMENT_GROUP'),
            'INPUT' => [
                'TYPE' => 'ENUM',
                'OPTIONS' => $orderStatuses,
            ]
        ],
        'PAYNET_PAYMENT_METHOD' => [
            'NAME' => Loc::getMessage('PAYNET_PAYMENT_METHOD_TITLE'),
            'DESCRIPTION' => Loc::getMessage('PAYNET_PAYMENT_METHOD_DESCRIPTION'),
            'SORT' => 106,
            'GROUP' => Loc::getMessage('PAYNET_PAYMENT_GROUP'),
            'INPUT' => [
                'TYPE' => 'ENUM',
                'OPTIONS' => $paymentMethods
            ]
        ],
        'PAYNET_THREE_D_SECURE' => [
            'NAME' => Loc::getMessage('PAYNET_THREE_D_SECURE_TITLE'),
            'DESCRIPTION' => Loc::getMessage('PAYNET_THREE_D_SECURE_DESCRIPTION'),
            'SORT' => 107,
            'GROUP' => Loc::getMessage('PAYNET_PAYMENT_GROUP'),
            'INPUT' => [
                'TYPE' => 'Y/N',
                'DEFAULT' => 'N'
            ]
        ],
        'PAYNET_LIVE_URL' => [
            'NAME' => Loc::getMessage('PAYNET_LIVE_URL_TITLE'),
            'DESCRIPTION' => Loc::getMessage('PAYNET_LIVE_URL_DESCRIPTION'),
            'SORT' => 108,
            'GROUP' => Loc::getMessage('PAYNET_PAYMENT_GROUP'),
            'DEFAULT' => [
                'PROVIDER_KEY' => 'VALUE',
                'PROVIDER_VALUE' => ''
            ]
        ],
        'PAYNET_SANDBOX_URL' => [
            'NAME' => Loc::getMessage('PAYNET_SANDBOX_URL_TITLE'),
            'DESCRIPTION' => Loc::getMessage('PAYNET_SANDBOX_URL_DESCRIPTION'),
            'SORT' => 108,
            'GROUP' => Loc::getMessage('PAYNET_PAYMENT_GROUP'),
            'DEFAULT' => [
                'PROVIDER_KEY' => 'VALUE',
                'PROVIDER_VALUE' => ''
            ]
        ],
    ]
];

return $data;