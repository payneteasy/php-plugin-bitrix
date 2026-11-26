<?php
/*
 * PaynetEasy: return of the customer from the payment form.
 *
 * The gateway returns the customer with a cross-site POST, which carries no
 * SameSite=Lax session cookie: Bitrix would start a new session and sign the
 * customer out. This script starts no session: it re-issues the request as a
 * GET to the payment result handler, which then gets the customer's cookie.
 */
$query = array_merge($_GET, $_POST);

// Only the order id and the gateway return fields are passed on
$allowed = ['ORDER_ID', 'PAYNET_PAYMENT', 'status', 'merchant_order', 'client_orderid', 'orderid', 'control', 'error_message', 'error_code', 'descriptor', 'processor-tx-id'];
$query = array_intersect_key($query, array_flip($allowed));
$query = array_filter($query, 'is_scalar');

$orderId = isset($query['ORDER_ID']) ? (string)$query['ORDER_ID'] : '';
if (!ctype_digit($orderId)) {
    http_response_code(400);
    exit;
}

header('Location: /bitrix/tools/sale_ps_result.php?' . http_build_query($query), true, 303);
exit;
