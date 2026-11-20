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

        $city = '';
        $country = 'US';
        foreach ($order->getPropertyCollection()->getArray()['properties'] as $property) {
            if ($property['NAME'] == 'City' || $property['NAME'] == 'Город') {
                $city = $property['VALUE'][0];
            }
            if ($property['NAME'] == 'Country' || $property['NAME'] == 'Страна') {
                $country = $property['VALUE'][0];
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
        $callbackUrl = '/bitrix/tools/sale_ps_result.php?ORDER_ID='.(int)$orderId;
        $returnUrl = self::ensureReturnScript()
            ? '/bitrix/tools/payneteasy_return.php?ORDER_ID='.(int)$orderId
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
            'address1' => $address,
            'city' => $city,
            'zip_code' => $propertyCollection->getDeliveryLocationZip()->getValue(),
            'country' => $country,
            'phone'      => $phone,
            'email'      => $email,
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
            $sql = "INSERT INTO `payneteasy_payments` (`paynet_order_id`, `merchant_order_id`, `redirect_url`) VALUES ($paynet_order_id, $merchant_order_id, '$redirect')";

//            $sql = "INSERT INTO payneteasy_payments (paynet_order_id, merchant_order_id)
//SELECT * FROM (SELECT $paynet_order_id, $merchant_order_id) AS tmp
//WHERE NOT EXISTS (
//                SELECT merchant_order_id FROM payneteasy_payments WHERE merchant_order_id = $merchant_order_id
//) LIMIT 1";

            $connection->query($sql);
        }

        return $response;
    }


    public static function getOrderInfo(
        $params,
        $orderId
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
            $sql = 'SELECT `paynet_order_id` FROM `payneteasy_payments` WHERE merchant_order_id='.$orderId.' ORDER BY `id` DESC LIMIT 50';
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


    public static function setPaymentRefund($params, $orderId, $amount = null, $currency = null)
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
            $approved = self::getOrderInfo($params, $orderId);
            $sql = 'SELECT `paynet_order_id` FROM `payneteasy_payments` WHERE merchant_order_id='.$orderId
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

            // The refund is processed asynchronously: wait briefly for the final answer
            for ($i = 0; $i < 5; $i++) {
                $statusData = [
                    'login' => $params['PAYNET_LOGIN'],
                    'client_orderid' => (string)$orderId,
                    'orderid' => (string)$paynet_order_id['paynet_order_id'],
                ];
                $statusData['control'] = $PaynetHandler->signStatusRequest($statusData, $params['PAYNET_LOGIN'], $params['PAYNET_CONTROL_KEY']);
                $state = $paynetRequest->status($statusData, $params['PAYNET_PAYMENT_METHOD'], $sandbox, $actionUrl, $params['PAYNET_ENDPOINT_ID']);
                $final = trim((string)($state['status'] ?? ''));
                if (in_array($final, ['declined', 'error', 'filtered'], true)) {
                    return ['error-code' => $state['error-code'] ?? 1, 'error-message' => trim((string)($state['error-message'] ?? 'Refund declined'))];
                }
                if ($final !== 'processing') {
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

        $checked = true;
    }

    /**
     * Copies the customer return script to /bitrix/tools/ for modules installed
     * by earlier versions.
     *
     * @return bool TRUE if the script is in place.
     */
    public static function ensureReturnScript()
    {
        $source = dirname(__DIR__, 2) . '/install/bitrix/tools/payneteasy_return.php';
        $target = \Bitrix\Main\Application::getDocumentRoot() . '/bitrix/tools/payneteasy_return.php';

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
    public static function getOpenPaymentForm($params, $orderId)
    {
        if (!ctype_digit((string)$orderId) || (int)$orderId <= 0) {
            return null;
        }
        self::ensurePaymentsTable();

        $row = \Bitrix\Main\Application::getConnection()->query(
            'SELECT `paynet_order_id`, `redirect_url` FROM `payneteasy_payments` WHERE merchant_order_id='.(int)$orderId.' ORDER BY `id` DESC LIMIT 1'
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