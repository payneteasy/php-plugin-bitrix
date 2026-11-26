<?php
/*
 * PaynetEasy: target of the card form for payments with 3-D Secure.
 *
 * Scripts inside /bitrix/modules/ are closed for web requests, so the entry
 * point is here. The request is handled by the payment system handler, the
 * same way as a card form without 3-D Secure: it checks that the request
 * comes from the customer of the order, sends the sale once, shows the
 * 3-D Secure page of the gateway and sets the result of the payment.
 */
$orderId = '';
foreach ([$_GET['ORDER_ID'] ?? '', $_POST['ORDER_ID'] ?? '', $_GET['orderId'] ?? ''] as $value) {
    if (is_scalar($value) && ctype_digit((string)$value) && (int)$value > 0) {
        $orderId = (string)$value;
        break;
    }
}
if ($orderId === '') {
    http_response_code(400);
    exit;
}

// The handler reads the order id from ORDER_ID
$_GET['ORDER_ID'] = $_POST['ORDER_ID'] = $_REQUEST['ORDER_ID'] = $orderId;

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/tools/sale_ps_result.php';
