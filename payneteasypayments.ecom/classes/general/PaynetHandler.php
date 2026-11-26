<?php

namespace Paynet\Pay;

use Bitrix\Main\ArgumentNullException;
use Exception;

error_reporting(E_ALL);

class PaynetHandler
{
    private static $logger;

    /**
     * Site host from Bitrix settings (site "Server URL", then main module "server_name").
     * Never taken from the request Host header, which the client controls.
     */
    public static function getSiteHost($siteId = null)
    {
        $host = '';
        if ($siteId === null && defined('SITE_ID')) {
            $siteId = SITE_ID;
        }
        if ($siteId) {
            $site = \Bitrix\Main\SiteTable::getList([
                'filter' => ['=LID' => $siteId],
                'select' => ['SERVER_NAME'],
            ])->fetch();
            $host = $site ? (string)$site['SERVER_NAME'] : '';
        }
        if ($host === '') {
            $host = (string)\Bitrix\Main\Config\Option::get('main', 'server_name', '');
        }
        if ($host === '') {
            throw new \Bitrix\Main\SystemException('PaynetEasy: set the site server URL in the Bitrix site settings');
        }
        return preg_replace('~^https?://~i', '', rtrim($host, '/'));
    }


    public function getPaynetPaymentResponse(
        $order,
        $params,
        $orderId,
        $payment = null
    ) {
        self::$logger = PaynetEasyLogger::getInstance();

        $propertyCollection = $order->getPropertyCollection();
        $orderCreate = $order->getDateInsert()->getTimestamp();
        // Charge this payment of the order (an order may be paid in several payments)
        $amount = number_format((float)($payment ? $payment->getSum() : $order->getPrice()), 2, '.', '');
        $saleCurrency = ($payment && $payment->getField('CURRENCY')) ? $payment->getField('CURRENCY') : $order->getCurrency();

        $email = '';
        if ($propEmail = $propertyCollection->getUserEmail()) {
            $email = $propEmail->getValue();
        } else {
            foreach ($propertyCollection as $orderProperty) {
                if ($orderProperty->getField('CODE') == 'EMAIL') {
                    $email = $orderProperty->getValue();
                    break;
                }
            }
        }
        $phone = '';
        if ($propPhone = $propertyCollection->getPhone()) {
            $phone = $propPhone->getValue();
        } else {
            foreach ($propertyCollection as $orderProperty) {
                if ($orderProperty->getField('CODE') == 'PHONE') {
                    $phone = $orderProperty->getValue();
                    break;
                }
            }
        }

        $address = '';
        if ($propAddress = $propertyCollection->getAddress()) {
            $address = $propAddress->getValue();
        } else {
            foreach ($propertyCollection as $orderProperty) {
                if ($orderProperty->getField('CODE') == 'ADDRESS') {
                    $address = $orderProperty->getValue();
                    break;
                }
            }
        }

        // A payer type may have no zip (or city, country) property at all
        $zip = '';
        if ($propZip = $propertyCollection->getDeliveryLocationZip()) {
            $zip = $propZip->getValue();
        } else {
            foreach ($propertyCollection as $orderProperty) {
                if ($orderProperty->getField('CODE') == 'ZIP') {
                    $zip = $orderProperty->getValue();
                    break;
                }
            }
        }

        $city = '';
        $country = 'US';
        foreach (($order->getPropertyCollection()->getArray()['properties'] ?? []) as $property) {
            $propertyName = $property['NAME'] ?? '';
            $propertyValue = is_array($property['VALUE'] ?? null) ? ($property['VALUE'][0] ?? '') : ($property['VALUE'] ?? '');
            if (!is_scalar($propertyValue) || (string)$propertyValue === '') {
                continue;
            }
            if ($propertyName == 'City' || $propertyName == 'Город') {
                $city = $propertyValue;
            }
            if ($propertyName == 'Country' || $propertyName == 'Страна') {
                $country = $propertyValue;
            }
        }

        global $USER;

        $payneteasy_card_number       = $_POST['credit_card_number']??'';
        $payneteasy_card_expiry_month = $_POST['expire_month']??'';
        $payneteasy_card_expiry_year  = $_POST['expire_year']??'';
        $payneteasy_card_name         = $_POST['card_printed_name']??'';
        $payneteasy_card_cvv          = $_POST['cvv2']??'';

        $card_data = [
            'credit_card_number' => $payneteasy_card_number??'',
            'card_printed_name' => $payneteasy_card_name??'',
            'expire_month' => $payneteasy_card_expiry_month??'',
            'expire_year' => $payneteasy_card_expiry_year??'',
            'cvv2' => $payneteasy_card_cvv??'',
        ];

        // The server callback goes to the result handler. The customer returns through
        // a small script that keeps the session cookie (see payneteasy_return.php);
        // the result handler then checks the payment status and shows the order page.
        // An order may have several payments: the URLs name the one being paid.
        $paymentId = ($payment && (int)$payment->getId() > 0) ? (int)$payment->getId() : 0;
        $paymentQuery = $paymentId > 0 ? '&PAYNET_PAYMENT='.$paymentId : '';
        $callbackUrl = '/bitrix/tools/sale_ps_result.php?ORDER_ID='.(int)$orderId.$paymentQuery;
        $returnUrl = self::ensureReturnScript()
            ? '/bitrix/tools/payneteasy_return.php?ORDER_ID='.(int)$orderId.$paymentQuery
            : $callbackUrl;
        $successUrl = $returnUrl;
        $failUrl = $returnUrl;

        $protocol = (empty($_SERVER['HTTPS']) ? 'http' : 'https');
        $host = self::getSiteHost($order->getSiteId());

        $returnUrl = "{$protocol}://{$host}{$returnUrl}";
        $callbackUrl = "{$protocol}://{$host}{$callbackUrl}";
        $successUrl = "{$protocol}://{$host}{$successUrl}";
        $failUrl = "{$protocol}://{$host}{$failUrl}";

        $data = [
            'client_orderid' => (string)$orderId,
            'order_desc' => 'Order # ' . $orderId,
            'amount' => $amount,
            'currency' => $saleCurrency,
            'address1' => is_scalar($address) ? (string)$address : '',
            'city' => (string)$city,
            'zip_code' => is_scalar($zip) ? (string)$zip : '',
            'country' => (string)$country,
            'phone'      => is_scalar($phone) ? (string)$phone : '',
            'email'      => is_scalar($email) ? (string)$email : '',
            'ipaddress' => $_SERVER['REMOTE_ADDR'],
            'cvv2' => $card_data['cvv2'],
            'credit_card_number' => $card_data['credit_card_number'],
            'card_printed_name' => $card_data['card_printed_name'],
            'expire_month' => $card_data['expire_month'],
            'expire_year' => $card_data['expire_year'],
            'first_name' => $USER->GetFirstName(),
            'last_name'  => $USER->GetLastName(),
            'redirect_success_url' => $successUrl,
            'redirect_fail_url' => $failUrl,
            'redirect_url' => $returnUrl,
            'server_callback_url' => $callbackUrl
        ];
        $data['control'] = $this->signPaymentRequest($data, $params['PAYNET_ENDPOINT_ID'], $params['PAYNET_CONTROL_KEY']);
        $sandbox = false;
        if ($params['PAYNET_SANDBOX'] === 'Y')
            $sandbox = true;

        try {
            $paynetRequest = new PaynetApi(
                $params['PAYNET_LOGIN'],
                $params['PAYNET_CONTROL_KEY'],
                $params['PAYNET_ENDPOINT_ID'],
                $params['PAYNET_PAYMENT_METHOD'],
                $sandbox
            );

            self::$logger->debug(
                __FUNCTION__ . ' > getOrderLink - INPUT: ', [
                'arguments' => [
                    'order_id' => $orderId,
                    'amount' => $amount,
                    'return_url' => $returnUrl,
                    'data' => self::maskCardData($data),
                ]
            ]);

            $actionUrl = $params['PAYNET_LIVE_URL'];
            if ($sandbox)
                $actionUrl = $params['PAYNET_SANDBOX_URL'];

            if ($params['PAYNET_PAYMENT_METHOD'] == 'form') {
                $response = $paynetRequest->saleForm(
                    $data,
                    $params['PAYNET_PAYMENT_METHOD'],
                    $sandbox,
                    $actionUrl,
                    $params['PAYNET_ENDPOINT_ID']
                );
            } elseif ($params['PAYNET_PAYMENT_METHOD'] == 'direct') {
                self::$logger->debug(
                    __FUNCTION__ . ' > direct ', [
                    'arguments' => [
                        'params' => array_diff_key($params, ['PAYNET_CONTROL_KEY' => true]),
                        'data' => self::maskCardData($data),
                        'sandbox' => $sandbox,
                        'actionUrl' => $actionUrl,
                    ]
                ]);
                $response = $paynetRequest->saleDirect(
                    $data,
                    $params['PAYNET_PAYMENT_METHOD'],
                    $sandbox,
                    $actionUrl,
                    $params['PAYNET_ENDPOINT_ID']
                );
                self::$logger->debug(
                    __FUNCTION__ . ' > direct ', [
                    'arguments' => [
                        'response' => $response,
                    ]
                ]);
            }


        } catch (\Exception | PaynetEasyException $e) {
            // Handle exception and log error
            $context = [
                'file_exception' => $e->getFile(),
                'line_exception' => $e->getLine(),
            ];
            if (method_exists($e, 'getContext')) $context = array_merge($e->getContext(), $context);

            self::$logger->error(sprintf(
                __FUNCTION__ . ' > PaynetEasy exception : %s; Order id: %s;',
                $e->getMessage(),
                $orderId ?: ''
            ), $context);

            throw new Exception("Запрос не достиг адресата. Повторите попытку позднее.");
        }

        self::$logger->debug(
            __FUNCTION__ . ' > getOrderLink - OUTPUT: ', [
            'response' => $response
        ]);

        if (isset($response['paynet-order-id']))
        $paynet_order_id = $response['paynet-order-id'];
        if (isset($response['merchant-order-id']))
        $merchant_order_id = $response['merchant-order-id'];

        if (isset($paynet_order_id, $merchant_order_id) && ctype_digit((string)$paynet_order_id) && ctype_digit((string)$merchant_order_id)) {
            // Gateway ids may exceed the 32-bit range: keep them as digit strings
            $paynet_order_id = (string)$paynet_order_id;
            $merchant_order_id = (int)$merchant_order_id;
            self::ensurePaymentsTable();
            $connection = \Bitrix\Main\Application::getConnection();
            // One row per payment attempt: the status is taken from the latest one
            $redirect = isset($response['redirect-url']) ? $connection->getSqlHelper()->forSql(substr(trim((string)$response['redirect-url']), 0, 1024)) : '';
            $attemptPaymentId = $paymentId > 0 ? $paymentId : 'NULL';
            $sql = "INSERT INTO `payneteasy_payments` (`paynet_order_id`, `merchant_order_id`, `payment_id`, `redirect_url`) VALUES ($paynet_order_id, $merchant_order_id, $attemptPaymentId, '$redirect')";

//            $sql = "INSERT INTO payneteasy_payments (paynet_order_id, merchant_order_id)
//SELECT * FROM (SELECT $paynet_order_id, $merchant_order_id) AS tmp
//WHERE NOT EXISTS (
//                SELECT merchant_order_id FROM payneteasy_payments WHERE merchant_order_id = $merchant_order_id
//) LIMIT 1";

            $connection->query($sql);
        }

        return $response;
    }


    /**
     * Status of the order's payment at the gateway.
     *
     * @param array $params Payment system settings
     * @param int $orderId Order id
     * @param int|null $paymentId Bitrix payment id: only its attempts are checked
     * @param bool $legacyRows Whether attempts stored without a payment id may be used
     */
    public static function getOrderInfo(
        $params,
        $orderId,
        $paymentId = null,
        $legacyRows = true
    )
    {
        self::$logger = PaynetEasyLogger::getInstance();

        $response = [];
        if (!ctype_digit((string)$orderId) || (int)$orderId <= 0) {
            return $response;
        }
        $orderId = (int)$orderId;

        $sandbox = false;
        if ($params['PAYNET_SANDBOX'] === 'Y')
            $sandbox = true;

        try {
            $paynetRequest = new PaynetApi(
                $params['PAYNET_LOGIN'],
                $params['PAYNET_CONTROL_KEY'],
                $params['PAYNET_ENDPOINT_ID'],
                $params['PAYNET_PAYMENT_METHOD'],
                $sandbox
            );

            // A customer may start several payment attempts (a declined card, another tab):
            // check all of them, an approved attempt wins, then a processing one
            self::ensurePaymentsTable();
            $sql = 'SELECT `paynet_order_id` FROM `payneteasy_payments` WHERE '.self::getAttemptsCondition($orderId, $paymentId, $legacyRows).' ORDER BY `id` DESC LIMIT 50';
            $result = \Bitrix\Main\Application::getConnection()->query($sql);

            $actionUrl = $params['PAYNET_LIVE_URL'];
            if ($sandbox)
                $actionUrl = $params['PAYNET_SANDBOX_URL'];

            $PaynetHandler = new PaynetHandler;
            $latest = null;
            $processing = null;

            while ($row = $result->fetch()) {
                $data = [
                    'login' => $params['PAYNET_LOGIN'],
                    'client_orderid' => (string)$orderId,
                    'orderid' => $row['paynet_order_id'],
                ];
                $data['control'] = $PaynetHandler->signStatusRequest($data, $params['PAYNET_LOGIN'], $params['PAYNET_CONTROL_KEY']);

                $attempt = $paynetRequest->status(
                    $data,
                    $params['PAYNET_PAYMENT_METHOD'],
                    $sandbox,
                    $actionUrl,
                    $params['PAYNET_ENDPOINT_ID']
                );

                self::$logger->debug(
                    __FUNCTION__ . ' > getOrderInfo - OUTPUT: ', [
                    'order_id' => $orderId,
                    'paynet_order_id' => $row['paynet_order_id'],
                    'response' => $attempt
                ]);

                $attemptStatus = trim((string)($attempt['status'] ?? ''));
                if ($attemptStatus === 'approved' || $attemptStatus === 'refunded') {
                    $response = $attempt;
                    $latest = null;
                    $processing = null;
                    break;
                }
                if ($attemptStatus === 'processing' && $processing === null) {
                    $processing = $attempt;
                }
                if ($latest === null) {
                    $latest = $attempt;
                }
            }

            if ($processing !== null) {
                $response = $processing;
            } elseif ($latest !== null) {
                $response = $latest;
            }

        } catch (\Exception | PaynetEasyException $e) {
            // Handle exception and log error
            $context = [
                'file_exception' => $e->getFile(),
                'line_exception' => $e->getLine(),
            ];
            if (method_exists($e, 'getContext')) $context = array_merge($e->getContext(), $context);

            self::$logger->error(sprintf(
                __FUNCTION__ . ' > PaynetEasy exception : %s; Order id: %s;',
                $e->getMessage(),
                $orderId ?: ''
            ), $context);

            throw new Exception("Запрос не достиг адресата. Повторите попытку позднее.");
        }

        return $response;
    }


    /**
     * Sends a refund of the approved sale and waits briefly for its result.
     *
     * The returned array has 'error-code' when the refund failed, otherwise
     * 'refund-state': 'approved' (the gateway confirmed the reversal) or
     * 'processing' (the request is accepted, the result is not known yet).
     */
    public static function setPaymentRefund($params, $orderId, $amount = null, $currency = null, $paymentId = null)
    {
        self::$logger = PaynetEasyLogger::getInstance();

        if (!ctype_digit((string)$orderId) || (int)$orderId <= 0) {
            return ['error-code' => 1, 'error-message' => 'Invalid order id'];
        }
        $orderId = (int)$orderId;

        $sandbox = false;
        if ($params['PAYNET_SANDBOX'] === 'Y')
            $sandbox = true;

        try {

            self::$logger->debug(
                __FUNCTION__ . ' > setRefunds - INPUT: ', [
                'arguments' => [
                    'order_id' => $orderId
                ]
            ]);

            self::ensurePaymentsTable();
            // Refund the approved attempt (the latest one may be a later unpaid form)
            $approved = self::getOrderInfo($params, $orderId, $paymentId);
            $sql = 'SELECT `paynet_order_id` FROM `payneteasy_payments` WHERE '.self::getAttemptsCondition($orderId, $paymentId)
                .(isset($approved['paynet-order-id']) && ctype_digit(trim((string)$approved['paynet-order-id'])) ? ' AND paynet_order_id='.trim((string)$approved['paynet-order-id']) : '')
                .' ORDER BY `id` DESC LIMIT 1';
            $result = \Bitrix\Main\Application::getConnection()->query($sql);
            $paynet_order_id = $result->fetch();

            if (empty($paynet_order_id['paynet_order_id'])) {
                return ['error-code' => 1, 'error-message' => 'PaynetEasy payment not found'];
            }

            // Refund the given sum, by default the whole approved sale
            if ($amount === null || $currency === null) {
                $amount = $approved['amount'] ?? null;
                $currency = $approved['currency'] ?? null;
            }
            if (!is_numeric($amount) || (float)$amount <= 0 || !preg_match('/^[A-Z]{3}$/', strtoupper(trim((string)$currency)))) {
                return ['error-code' => 1, 'error-message' => 'Refund amount or currency is missing'];
            }

            $data = [
                'login' => $params['PAYNET_LOGIN'],
                'client_orderid' => (string)$orderId,
                'orderid' => (string)$paynet_order_id['paynet_order_id'],
                'amount' => number_format((float)$amount, 2, '.', ''),
                'currency' => strtoupper(trim((string)$currency)),
                'comment' => 'Refund'
            ];

            $PaynetHandler = new PaynetHandler();

            $data['control'] = $PaynetHandler->signReturnRequest($data, $params['PAYNET_LOGIN'], $params['PAYNET_CONTROL_KEY']);

            $actionUrl = $params['PAYNET_LIVE_URL'];
            if ($sandbox)
                $actionUrl = $params['PAYNET_SANDBOX_URL'];

            $paynetRequest = new PaynetApi(
                $params['PAYNET_LOGIN'],
                $params['PAYNET_CONTROL_KEY'],
                $params['PAYNET_ENDPOINT_ID'],
                $params['PAYNET_PAYMENT_METHOD'],
                $sandbox
            );

            // A refund sent earlier is still being processed: another request
            // would be another refund, only its result is asked for.
            $pending = trim((string)($approved['status'] ?? '')) === 'processing'
                && strtolower(trim((string)($approved['transaction-type'] ?? ''))) === 'reversal';

            if ($pending) {
                $refund = ['type' => 'async-response', 'paynet-order-id' => (string)$paynet_order_id['paynet_order_id']];
                self::$logger->error("Order {$orderId}: a refund is already being processed, not sent again");
            } else {
                $refund = $paynetRequest->return(
                    $data,
                    $params['PAYNET_PAYMENT_METHOD'],
                    $sandbox,
                    $actionUrl,
                    $params['PAYNET_ENDPOINT_ID']
                );

                // Logging output
                self::$logger->debug(
                    __FUNCTION__ . ' > setRefunds - OUTPUT: ', [
                    'response' => $refund
                ]);

                if (in_array(trim((string)($refund['type'] ?? '')), ['error', 'validation-error'], true) || trim((string)($refund['status'] ?? '')) === 'error') {
                    return ['error-code' => $refund['error-code'] ?? 1, 'error-message' => trim((string)($refund['error-message'] ?? 'Refund refused'))];
                }
            }

            // The refund is processed asynchronously: wait briefly for the final
            // answer. Only an approved reversal is a completed refund.
            $refund['refund-state'] = 'processing';
            for ($i = 0; $i < 5; $i++) {
                $statusData = [
                    'login' => $params['PAYNET_LOGIN'],
                    'client_orderid' => (string)$orderId,
                    'orderid' => (string)$paynet_order_id['paynet_order_id'],
                ];
                $statusData['control'] = $PaynetHandler->signStatusRequest($statusData, $params['PAYNET_LOGIN'], $params['PAYNET_CONTROL_KEY']);
                try {
                    $state = $paynetRequest->status($statusData, $params['PAYNET_PAYMENT_METHOD'], $sandbox, $actionUrl, $params['PAYNET_ENDPOINT_ID']);
                } catch (\Exception $e) {
                    // The request is already accepted: a failed status query leaves the result unknown
                    self::$logger->error("Order {$orderId}: refund status query failed: " . $e->getMessage());
                    $state = [];
                }
                $final = trim((string)($state['status'] ?? ''));
                if (in_array($final, ['declined', 'error', 'filtered'], true)) {
                    return ['error-code' => $state['error-code'] ?? 1, 'error-message' => trim((string)($state['error-message'] ?? '')) ?: 'Refund declined'];
                }
                if ($final === 'approved' && strtolower(trim((string)($state['transaction-type'] ?? ''))) === 'reversal') {
                    $refund['refund-state'] = 'approved';
                    break;
                }
                sleep(1);
            }

        } catch (\Exception | PaynetEasyException $e) {
            // Handle exception and log error
            $context = [
                'file_exception' => $e->getFile(),
                'line_exception' => $e->getLine(),
            ];
            if (method_exists($e, 'getContext')) $context = array_merge($e->getContext(), $context);

            self::$logger->error(sprintf(
                __FUNCTION__ . ' > PaynetEasy exception : %s; Order id: %s;',
                $e->getMessage(),
                $orderId ?: ''
            ), $context);

            throw $e;
        }

        return $refund;
    }


    private static function maskCardData(array $data): array
    {
        if (!empty($data['credit_card_number'])) {
            $data['credit_card_number'] = '****' . substr((string)$data['credit_card_number'], -4);
        }
        foreach (['cvv2', 'expire_month', 'expire_year', 'card_printed_name'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = '***';
            }
        }

        return $data;
    }


    private function signStatusRequest($requestFields, $login, $merchantControl)
    {
        $base = '';
        $base .= $login;
        $base .= $requestFields['client_orderid'];
        $base .= $requestFields['orderid'];

        return $this->signString($base, $merchantControl);
    }


    private function signReturnRequest($data, $login, $merchantControl)
    {
        return $this->signString(
            $login . $data['client_orderid'] . $data['orderid'] . (int)round($data['amount'] * 100) . $data['currency'],
            $merchantControl
        );
    }


    private function signPaymentRequest($data, $endpointId, $merchantControl)
    {
        $base = '';
        $base .= $endpointId;
        $base .= $data['client_orderid'];
        $base .= (int)round($data['amount'] * 100);
        $base .= $data['email'];

        return $this->signString($base, $merchantControl);
    }


    private function signString($s, $merchantControl)
    {
        return sha1($s . $merchantControl);
    }

    /**
     * Brings the payments table of earlier versions up to date:
     * a key for the attempt order and 64-bit gateway ids.
     */
    public static function ensurePaymentsTable()
    {
        static $checked = false;
        if ($checked) {
            return;
        }

        $connection = \Bitrix\Main\Application::getConnection();
        $columns = [];
        $result = $connection->query('SHOW COLUMNS FROM `payneteasy_payments`');
        while ($row = $result->fetch()) {
            $columns[$row['Field']] = strtolower((string)$row['Type']);
        }

        if (!isset($columns['id'])) {
            $connection->query('ALTER TABLE `payneteasy_payments` ADD `id` int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST');
        }
        if (isset($columns['paynet_order_id']) && strpos($columns['paynet_order_id'], 'bigint') !== 0) {
            $connection->query('ALTER TABLE `payneteasy_payments` MODIFY `paynet_order_id` bigint unsigned NOT NULL');
        }
        if (!isset($columns['id'])) {
            $connection->query('ALTER TABLE `payneteasy_payments` ADD INDEX `merchant_order_id` (`merchant_order_id`)');
        }
        if (!isset($columns['redirect_url'])) {
            $connection->query('ALTER TABLE `payneteasy_payments` ADD `redirect_url` varchar(1024) DEFAULT NULL');
        }
        if (!isset($columns['payment_id'])) {
            // The Bitrix payment of the attempt: an order may have several payments
            $connection->query('ALTER TABLE `payneteasy_payments` ADD `payment_id` int DEFAULT NULL');
            try {
                // Existing attempts of an order with a single PaynetEasy payment belong to that payment
                $connection->query(
                    'UPDATE `payneteasy_payments` pp INNER JOIN ('
                    .'SELECT p.ORDER_ID, MIN(p.ID) AS PAYMENT_ID FROM b_sale_order_payment p'
                    .' INNER JOIN b_sale_pay_system_action a ON a.ID = p.PAY_SYSTEM_ID'
                    ." WHERE a.ACTION_FILE = 'payneteasypayments' GROUP BY p.ORDER_ID HAVING COUNT(*) = 1"
                    .') x ON x.ORDER_ID = pp.merchant_order_id SET pp.payment_id = x.PAYMENT_ID WHERE pp.payment_id IS NULL'
                );
            } catch (\Exception $e) {
                // Not required: attempts without a payment id are still found by the order
            }
        }

        $checked = true;
    }

    /**
     * SQL condition for the stored attempts of one payment of an order.
     *
     * Attempts stored by earlier versions may have no payment id: they are
     * used while the payment has no attempts of its own.
     */
    private static function getAttemptsCondition($orderId, $paymentId = null, $legacyRows = true)
    {
        $condition = 'merchant_order_id='.(int)$orderId;
        if ((int)$paymentId <= 0) {
            return $condition;
        }

        self::ensurePaymentsTable();
        $own = $condition.' AND payment_id='.(int)$paymentId;
        $row = \Bitrix\Main\Application::getConnection()->query('SELECT `id` FROM `payneteasy_payments` WHERE '.$own.' LIMIT 1')->fetch();
        if ($row || !$legacyRows) {
            return $own;
        }

        return $condition.' AND payment_id IS NULL';
    }

    /**
     * Lock of one order: the return of the customer and the gateway callback
     * check and change the same order at the same time.
     *
     * @return bool FALSE if another request still holds the lock after the timeout.
     */
    public static function lockOrder($orderId, $timeout = 30)
    {
        $connection = \Bitrix\Main\Application::getConnection();
        $name = 'payneteasy_order_'.(int)$orderId;

        if (method_exists($connection, 'lock')) {
            return (bool)$connection->lock($name, (int)$timeout);
        }

        // Older Bitrix versions: the same MySQL lock, named for this database
        $name = md5($connection->getDatabase()).'_'.$name;
        $row = $connection->query("SELECT GET_LOCK('".$connection->getSqlHelper()->forSql($name)."', ".(int)$timeout.") AS L")->fetch();

        return $row && (string)$row['L'] === '1';
    }

    public static function unlockOrder($orderId)
    {
        $connection = \Bitrix\Main\Application::getConnection();
        $name = 'payneteasy_order_'.(int)$orderId;

        if (method_exists($connection, 'unlock')) {
            return (bool)$connection->unlock($name);
        }

        $name = md5($connection->getDatabase()).'_'.$name;
        $row = $connection->query("SELECT RELEASE_LOCK('".$connection->getSqlHelper()->forSql($name)."') AS L")->fetch();

        return $row && (string)$row['L'] === '1';
    }

    /**
     * Copies the customer return script to /bitrix/tools/ for modules installed
     * by earlier versions.
     *
     * @return bool TRUE if the script is in place.
     */
    public static function ensureReturnScript()
    {
        return self::ensureToolsScript('payneteasy_return.php');
    }

    /**
     * Copies the card form target used with 3-D Secure to /bitrix/tools/
     * (the module directory itself is closed for web requests).
     *
     * @return bool TRUE if the script is in place.
     */
    public static function ensureThreeDSecureScript()
    {
        return self::ensureToolsScript('payneteasy_3ds.php');
    }

    private static function ensureToolsScript($name)
    {
        $source = dirname(__DIR__, 2) . '/install/bitrix/tools/' . $name;
        $target = \Bitrix\Main\Application::getDocumentRoot() . '/bitrix/tools/' . $name;

        if (!is_file($source)) {
            return is_file($target);
        }
        if (!is_file($target) || md5_file($source) !== md5_file($target)) {
            @copy($source, $target);
        }

        return is_file($target) && md5_file($source) === md5_file($target);
    }

    /**
     * The payment form of the latest attempt, if the gateway still waits for the card.
     * A new sale is not started while that form can be paid.
     *
     * @return string|null
     */
    public static function getOpenPaymentForm($params, $orderId, $paymentId = null, $legacyRows = true)
    {
        if (!ctype_digit((string)$orderId) || (int)$orderId <= 0) {
            return null;
        }
        self::ensurePaymentsTable();

        $row = \Bitrix\Main\Application::getConnection()->query(
            'SELECT `paynet_order_id`, `redirect_url` FROM `payneteasy_payments` WHERE '.self::getAttemptsCondition($orderId, $paymentId, $legacyRows).' ORDER BY `id` DESC LIMIT 1'
        )->fetch();

        if (empty($row['redirect_url']) || strpos($row['redirect_url'], 'https://') !== 0) {
            return null;
        }

        $sandbox = $params['PAYNET_SANDBOX'] === 'Y';
        $data = [
            'login' => $params['PAYNET_LOGIN'],
            'client_orderid' => (string)(int)$orderId,
            'orderid' => $row['paynet_order_id'],
        ];
        $PaynetHandler = new PaynetHandler;
        $data['control'] = $PaynetHandler->signStatusRequest($data, $params['PAYNET_LOGIN'], $params['PAYNET_CONTROL_KEY']);

        try {
            $paynetRequest = new PaynetApi($params['PAYNET_LOGIN'], $params['PAYNET_CONTROL_KEY'], $params['PAYNET_ENDPOINT_ID'], $params['PAYNET_PAYMENT_METHOD'], $sandbox);
            $status = $paynetRequest->status($data, $params['PAYNET_PAYMENT_METHOD'], $sandbox, $sandbox ? $params['PAYNET_SANDBOX_URL'] : $params['PAYNET_LIVE_URL'], $params['PAYNET_ENDPOINT_ID']);
        } catch (\Exception $e) {
            return null;
        }

        return trim((string)($status['status'] ?? '')) === 'processing' ? $row['redirect_url'] : null;
    }

    /**
     * TRUE if the current request comes from the customer of the order
     * (the signed-in owner, with the Bitrix session token).
     */
    public static function isOrderOwnerRequest($order)
    {
        global $USER;

        return is_object($USER) && $USER->IsAuthorized()
            && (int)$order->getUserId() > 0
            && (int)$order->getUserId() === (int)$USER->GetID()
            && function_exists('check_bitrix_sessid') && check_bitrix_sessid();
    }
}