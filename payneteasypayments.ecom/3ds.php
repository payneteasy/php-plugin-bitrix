<?php
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/sale/lib/internals/entity.php");
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/sale/lib/orderbase.php");
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/sale/lib/order.php");
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/payneteasypayments.ecom/classes/general/PaynetApi.php");
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/payneteasypayments.ecom/classes/general/PaynetDebug.php");
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/payneteasypayments.ecom/classes/general/PaynetEasyException.php");
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/payneteasypayments.ecom/classes/general/PaynetEasyLogger.php");
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/payneteasypayments.ecom/classes/general/PaynetHandler.php");
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/payneteasypayments.ecom/classes/general/PaynetSearcher.php");
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Context;
use Bitrix\Main\Request;
use Bitrix\Main\SystemException;
use Bitrix\Sale\Order;
use Bitrix\Sale\Payment;
use Bitrix\Sale\PaySystem\ServiceHandler;
use Bitrix\Sale\PaySystem\ServiceResult;
use Bitrix\Sale\PaySystem\IRefund;
use Bitrix\Sale\PaySystem;
use Bitrix\Sale;
use Paynet\Pay\PaynetHandler;
use Paynet\Pay\PaynetEasyLogger;

$orderId = $_GET['orderId']??$_GET['ORDER_ID']??'';
if (!ctype_digit((string)$orderId) || (int)$orderId <= 0) {
    die();
}
$orderId = (int)$orderId;
$order = Order::load($orderId);
if (!$order) {
    die();
}
$paymentCollection = $order->getPaymentCollection();
$payment = $paymentCollection->load($order);

$login = \Bitrix\Sale\BusinessValue::get('PAYNET_LOGIN', 'PAYSYSTEM_'.$order->getPaymentSystemId()[0]);
$three_d_secure = \Bitrix\Sale\BusinessValue::get('PAYNET_THREE_D_SECURE', 'PAYSYSTEM_'.$order->getPaymentSystemId()[0]);
$sandbox = \Bitrix\Sale\BusinessValue::get('PAYNET_SANDBOX', 'PAYSYSTEM_'.$order->getPaymentSystemId()[0]);
$live_url = \Bitrix\Sale\BusinessValue::get('PAYNET_LIVE_URL', 'PAYSYSTEM_'.$order->getPaymentSystemId()[0]);
$sandbox_url = \Bitrix\Sale\BusinessValue::get('PAYNET_SANDBOX_URL', 'PAYSYSTEM_'.$order->getPaymentSystemId()[0]);
$payment_method = \Bitrix\Sale\BusinessValue::get('PAYNET_PAYMENT_METHOD', 'PAYSYSTEM_'.$order->getPaymentSystemId()[0]);
$endpoint_id = \Bitrix\Sale\BusinessValue::get('PAYNET_ENDPOINT_ID', 'PAYSYSTEM_'.$order->getPaymentSystemId()[0]);
$control_key = \Bitrix\Sale\BusinessValue::get('PAYNET_CONTROL_KEY', 'PAYSYSTEM_'.$order->getPaymentSystemId()[0]);

$params = [
    'PAYNET_LOGIN' => $login,
    'PAYNET_THREE_D_SECURE' => $three_d_secure,
    'PAYNET_SANDBOX' => $sandbox,
    'PAYNET_LIVE_URL' => $live_url,
    'PAYNET_SANDBOX_URL' => $sandbox_url,
    'PAYNET_PAYMENT_METHOD' => $payment_method,
    'PAYNET_ENDPOINT_ID' => $endpoint_id,
    'PAYNET_CONTROL_KEY' => $control_key,
];

$test = false;
if ($sandbox == 'Y')
    $test = true;

$actionUrl = $live_url;
if ($test)
    $actionUrl = $sandbox_url;

$url = 'paynet/api/v2/';

$PaynetHandler = new PaynetHandler();
$getPaynetPaymentResponse = $PaynetHandler->getPaynetPaymentResponse(
    $order,
    $params,
    $orderId
);

$sql = 'SELECT `paynet_order_id` FROM `payneteasy_payments` WHERE merchant_order_id='.$orderId;
$result = \Bitrix\Main\Application::getConnection()->query($sql);
$paynet_order_id = $result->fetch();

$data = [
    'login' => $login,
    'client_orderid' => (string)$orderId,
    'orderid' => $paynet_order_id['paynet_order_id'],
];

$data['control'] = signStatusRequest($data, $login, $control_key);

$status = status(
    $data,
    $payment_method,
    $test,
    $actionUrl,
    $endpoint_id
);

function signStatusRequest($requestFields, $login, $merchantControl)
{
    $base = '';
    $base .= $login;
    $base .= $requestFields['client_orderid'];
    $base .= $requestFields['orderid'];

    return signString($base, $merchantControl);
}

function signString($s, $merchantControl)
{
    return sha1($s . $merchantControl);
}

function status($data, $integration_method, $type, $action_url, $endpoint)
{
    return execute('status/' . $endpoint, $data, 'POST', $integration_method, $type, $action_url);
}

function execute($action, $data, $method, $integration_method, $type, $action_url) {
    return curlRequestHandler($action, $data, $method, $integration_method, $type, $action_url);
}

function curlRequestHandler($action, $data, $method, $integration_method, $type, $action_url)
{
    $url = 'paynet/api/v2/';

    $curl = curl_init($action_url.$url.$action);

    curl_setopt_array($curl, array
    (
        CURLOPT_HEADER         => 0,
        CURLOPT_USERAGENT      => 'Payneteasy-Client/1.0',
        CURLOPT_POST           => 1,
        CURLOPT_RETURNTRANSFER => 1
    ));

    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));

    $response = curl_exec($curl);

    if(curl_errno($curl))
    {
        $error_message  = 'Error occurred: ' . curl_error($curl);
        $error_code     = curl_errno($curl);
    }
    elseif(curl_getinfo($curl, CURLINFO_HTTP_CODE) != 200)
    {
        $error_code     = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error_message  = "Error occurred. HTTP code: '{$error_code}'";
    }

    curl_close($curl);

    if (!empty($error_message))
    {
        throw new \Exception($error_message, $error_code);
    }

    if(empty($response))
    {
        throw new \Exception('Host response is empty');
    }

    $responseFields = array();

    parse_str($response, $responseFields);

    if (isset($responseFields['html']))
    echo $responseFields['html'];
//    return $responseFields;

}
