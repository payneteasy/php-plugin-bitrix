<?php

namespace Paynet\Pay;

use Bitrix\Main\ArgumentNullException;
use Protobuf\Exception;

error_reporting(E_ALL);

class PaynetHandler
{
    private static $logger;


    public function getPaynetPaymentResponse(
        $order,
        $params,
        $orderId
    ) {
        self::$logger = PaynetEasyLogger::getInstance();

        $propertyCollection = $order->getPropertyCollection();
        $orderCreate = $order->getDateInsert()->getTimestamp();
        $amount = $order->getPrice();

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

        $returnUrl = '/personal/orders/'.$orderId;
        $callbackUrl = '/bitrix/tools/sale_ps_result.php?ORDER_ID='.$orderId;
        $successUrl = '/bitrix/tools/sale_ps_success.php?ORDER_ID='.$orderId;
        $failUrl = '/bitrix/tools/sale_ps_fail.php?ORDER_ID='.$orderId;

        $protocol = (empty($_SERVER['HTTPS']) ? 'http' : 'https');
        $host = $_SERVER['HTTP_HOST'];

        $returnUrl = "{$protocol}://{$host}{$returnUrl}";
        $callbackUrl = "{$protocol}://{$host}{$callbackUrl}";
        $successUrl = "{$protocol}://{$host}{$successUrl}";
        $failUrl = "{$protocol}://{$host}{$failUrl}";

        $data = [
            'client_orderid' => (string)$orderId,
            'order_desc' => 'Order # ' . $orderId,
            'amount' => $amount,
            'currency' => $order->getCurrency(),
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
                    'data' => $data,
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
                        'params' => $params,
                        'data' => $data,
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
            $paynet_order_id = (int)$paynet_order_id;
            $merchant_order_id = (int)$merchant_order_id;
            $connection = \Bitrix\Main\Application::getConnection();
            $sql = "INSERT IGNORE INTO `payneteasy_payments` (`paynet_order_id`, `merchant_order_id`) VALUES ($paynet_order_id, $merchant_order_id)";

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

            $sql = 'SELECT `paynet_order_id` FROM `payneteasy_payments` WHERE merchant_order_id='.$orderId;
            $result = \Bitrix\Main\Application::getConnection()->query($sql);
            $paynet_order_id = $result->fetch();

            if (isset($paynet_order_id['paynet_order_id'])) {
                self::$logger->debug(
                    __FUNCTION__ . ' > getOrderInfo - INPUT: ', [
                    'arguments' => [
                        'order_id' => $orderId,
                        'paynet_order_id' => $paynet_order_id['paynet_order_id']
                    ]
                ]);

                $data = [
                    'login' => $params['PAYNET_LOGIN'],
                    'client_orderid' => (string)$orderId,
                    'orderid' => $paynet_order_id['paynet_order_id'],
                ];
                $PaynetHandler = new PaynetHandler;
                $data['control'] = $PaynetHandler->signStatusRequest($data, $params['PAYNET_LOGIN'], $params['PAYNET_CONTROL_KEY']);

                $actionUrl = $params['PAYNET_LIVE_URL'];
                if ($sandbox)
                    $actionUrl = $params['PAYNET_SANDBOX_URL'];

                $response = $paynetRequest->status(
                    $data,
                    $params['PAYNET_PAYMENT_METHOD'],
                    $sandbox,
                    $actionUrl,
                    $params['PAYNET_ENDPOINT_ID']
                );

                self::$logger->debug(
                    __FUNCTION__ . ' > getOrderInfo - OUTPUT: ', [
                    'response' => $response
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

        return $response;
    }


    public static function setPaymentRefund($params, $orderId)
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

            $sql = 'SELECT `paynet_order_id` FROM `payneteasy_payments` WHERE merchant_order_id='.$orderId;
            $result = \Bitrix\Main\Application::getConnection()->query($sql);
            $paynet_order_id = $result->fetch();

            $data = [
                'login' => $params['PAYNET_LOGIN'],
                'client_orderid' => $orderId,
                'orderid' => $paynet_order_id['paynet_order_id'],
                'comment' => 'Order cancel '
            ];

            $PaynetHandler = new PaynetHandler();

            $data['control'] = $PaynetHandler->signPaymentRequest($data, $params['PAYNET_ENDPOINT_ID'], $params['PAYNET_CONTROL_KEY']);

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


    private function signStatusRequest($requestFields, $login, $merchantControl)
    {
        $base = '';
        $base .= $login;
        $base .= $requestFields['client_orderid'];
        $base .= $requestFields['orderid'];

        return $this->signString($base, $merchantControl);
    }


    private function signPaymentRequest($data, $endpointId, $merchantControl)
    {
        $base = '';
        $base .= $endpointId;
        $base .= $data['client_orderid'];
        $base .= $data['amount'] * 100;
        $base .= $data['email'];

        return $this->signString($base, $merchantControl);
    }


    private function signString($s, $merchantControl)
    {
        return sha1($s . $merchantControl);
    }
}