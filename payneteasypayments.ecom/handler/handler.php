<?php

namespace Sale\Handlers\PaySystem;

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
use Paynet\Pay\PaynetHandler;
use Paynet\Pay\PaynetEasyLogger;

error_reporting(E_ERROR | E_WARNING | E_PARSE);

if (!Loader::includeModule('payneteasypayments.ecom')) {
    throw new SystemException('payneteasypayments.ecom module not found');
}

class payneteasypaymentsHandler extends ServiceHandler implements IRefund
{
    private $order;

    private $logger;

    private array $params;

    private bool $isRefund = true;


    public function initiatePay(Payment $payment, Request $request = null)
    {
        $shopOrderId = $request['merchant_order']??$payment->getOrderId()??'';
        $paynetOrderId = $request['orderid']??'';
        $status = $request['status']??'';

        $merchant_order = $request['merchant_order']??'';
        $payment_order = $payment->getOrderId()??'';

        $this->order = Order::load($payment->getOrderId());

        if (empty($this->params)) {
            $this->params = $this->getPaymentParams($payment);
        }

        $this->setPayneteasyLogger();

        if (empty($this->params['PAYNET_LOGIN']) || empty($this->params['PAYNET_CONTROL_KEY']) || empty($this->params['PAYNET_ENDPOINT_ID'])) {
            $this->logger->error('Module not configured');
            require __DIR__ . '/configure.php';
        }

        $orderId = $payment->getOrderId();

        $contextRequest = Context::getCurrent()->getRequest();

        $queryData = $contextRequest->getQueryList()->toArray(); // массив get параметров

        $this->logger->setOption('additionalCommonText', 'init-' . rand(1111, 9999));

        $this->logger->debug(
            __FUNCTION__ . ' > init-data: ', [
            'arguments' => [
                'query' => $queryData,
                'paid' => $payment->getField('PAID')
            ]
        ]);

        $PaynetHandler = new PaynetHandler();

        foreach ($this->getCurrencyList() as $getCurrency) {
            if ($this->order->getCurrency() == $getCurrency) {
                $currency = $getCurrency;
            }
        }

        $orderAmount = CurrencyFormat($payment->getSum(), $currency);

        $redirectUrl = '';
        if ($this->params['PAYNET_PAYMENT_METHOD'] == 'form') {
            if (isset($orderId)) {
                $paynetPaymentResponse = $PaynetHandler->getPaynetPaymentResponse(
                    $this->order,
                    $this->params,
                    $orderId
                );
            }
            $redirectUrl = $paynetPaymentResponse['redirect-url'];
        } elseif ($this->params['PAYNET_PAYMENT_METHOD'] == 'direct') {
            $callbackUrl = '/bitrix/tools/sale_ps_result.php?ORDER_ID='.$orderId??$payment->getOrderId();
            $protocol = (empty($_SERVER['HTTPS']) ? 'http' : 'https');
            $host = $_SERVER['HTTP_HOST'];
            $redirectUrl = "{$protocol}://{$host}{$callbackUrl}";
            if ($this->params['PAYNET_THREE_D_SECURE'] == 'Y') {
                $redirectUrl = '/bitrix/modules/payneteasypayments.ecom/3ds.php?orderId='.$orderId??$payment->getOrderId();
                $redirectUrl = "{$protocol}://{$host}{$redirectUrl}";
            }
        }

        $requestError = 'OK';
        if (isset($paynetPaymentResponse['error-code']) && $paynetPaymentResponse['error-code'] > 0) {
            $requestError = 'REQUEST';
        }

        $args = [
            'ACTION_URL' => $redirectUrl,
            'PARAMS' => [],
            'AMOUNT' => $orderAmount,
            'ERROR' => $requestError
        ];

        $args['WINDOW'] = false;
        if ($this->params['PAYNET_NEW_WINDOW'] === 'Y') {
            $args['WINDOW'] = true;
        }

        $this->setExtraParams($args);

        return $this->showTemplate($payment, "template");
    }


    public function getPaymentIdFromRequest(Request $request)
    {
        $this->setPayneteasyLogger();

        $shopOrderId = self::getRequestOrderId($request);
        $paynetOrderId = $request['orderid']??'';
        $status = $request['status']??'';

        if ($shopOrderId <= 0) {
            return false;
        }

        $order = Order::load($shopOrderId);
        if (!$order) {
            return false;
        }
        $paymentCollection = $order->getPaymentCollection();
        $payment = $paymentCollection->createItem();

        $paySystemService = PaySystem\Manager::getObjectById($order->getPaymentSystemId()[0]);
        $payment->setFields(array(
            'PAY_SYSTEM_ID' => $order->getPaymentSystemId()[0],
            'PAY_SYSTEM_NAME' => $paySystemService->getField("NAME"),
            'SUM'=> $order->getPrice()
        ));

        if (empty($this->params)) {
            $this->params = $this->getPaymentParams($payment);
        }

        $redirectUrl = '/personal/orders/'.$shopOrderId;
        $callbackUrl = '/bitrix/tools/sale_ps_result.php?ORDER_ID='.$shopOrderId;
        $successUrl = '/bitrix/tools/sale_ps_success.php?ORDER_ID='.$shopOrderId;
        $failUrl = '/bitrix/tools/sale_ps_fail.php?ORDER_ID='.$shopOrderId;

        $protocol = (empty($_SERVER['HTTPS']) ? 'http' : 'https');
        $host = $_SERVER['HTTP_HOST'];
        $redirectUrl = "{$protocol}://{$host}{$redirectUrl}";
        $callbackUrl = "{$protocol}://{$host}{$callbackUrl}";
        $successUrl = "{$protocol}://{$host}{$successUrl}";
        $failUrl = "{$protocol}://{$host}{$failUrl}";

        if ($this->params['PAYNET_PAYMENT_METHOD'] == 'direct') {
            $PaynetHandler = new PaynetHandler();
            if (isset($_GET['expire_year']) && isset($_GET['expire_month']) && isset($_GET['credit_card_number']) && isset($_GET['card_printed_name']) && isset($_GET['cvv2'])) {
                $paynetPaymentResponse = $PaynetHandler->getPaynetPaymentResponse(
                    $order,
                    $this->params,
                    $shopOrderId
                );
                $this->logger->debug(
                    __FUNCTION__ . ' > direct: ', [
                    'arguments' => [
                        'paynetPaymentResponse' => $paynetPaymentResponse,
                    ]
                ]);
            }
            $paynetStatusOrder = $this->getOrderStatus($payment, $shopOrderId);
            $this->logger->debug(
                __FUNCTION__ . ' > direct: ', [
                'arguments' => [
                    'paynetStatusOrder' => $paynetStatusOrder,
                ]
            ]);

//            if ($this->params['PAYNET_THREE_D_SECURE'] == 'Y') {
//                $paynetStatusOrder = $this->getOrderStatus($payment, $orderId??$payment->getOrderId());
//                $this->logger->debug(
//                    __FUNCTION__ . ' > direct 3ds: ', [
//                    'arguments' => [
//                        'paynetStatusOrder' => $paynetStatusOrder,
//                    ]
//                ]);
//                echo $paynetStatusOrder['html'];
//            }

            if (isset($paynetStatusOrder['status']) && trim($paynetStatusOrder['status']) == 'approved') {
                if ($order->getField('STATUS_ID') !== 'P' && ($order->getField('STATUS_ID') !== 'VR' || $order->getField('STATUS_ID') !== 'VC')) {
                    $this->setTransaction($payment, 'Y', 'P');
                }
                LocalRedirect($successUrl, true);
            } elseif (isset($paynetStatusOrder['status']) && trim($paynetStatusOrder['status']) == 'processing') {
                // processing is not a final status: keep the payment unpaid, mark as pending
                if (!$order->isPaid() && $order->getField('STATUS_ID') !== 'VP') {
                    $this->setTransaction($payment, 'N', 'VP');
                }
                LocalRedirect($callbackUrl, true);
            } elseif (isset($paynetStatusOrder['status']) && trim($paynetStatusOrder['status']) == 'refunded') {
                if ($order->getField('STATUS_ID') !== 'VR') {
                    $this->setTransaction($payment, 'N', 'VR', 'Y');
                }
                LocalRedirect($failUrl, true);
            } else {
                if ($order->getField('STATUS_ID') !== 'VC') {
                    $this->setTransaction($payment, 'N', 'VC', 'Y');
                }
                LocalRedirect($failUrl, true);
            }

        }


        $paynetStatusOrder = $this->getOrderStatus($payment, $shopOrderId);

        $this->logger->debug(
            __FUNCTION__ . ' > direct: ', [
            'arguments' => [
                'paynetStatusOrder' => $paynetStatusOrder,
            ]
        ]);

        if (isset($paynetStatusOrder['status']) && trim($paynetStatusOrder['status']) == 'approved') {
            if ($order->getField('STATUS_ID') !== 'P' && ($order->getField('STATUS_ID') !== 'VR' || $order->getField('STATUS_ID') !== 'VC')) {
                $this->setTransaction($payment, 'Y', 'P');
            }
            LocalRedirect($successUrl, true);
        } elseif (isset($paynetStatusOrder['status']) && trim($paynetStatusOrder['status']) == 'processing') {
            // processing is not a final status: keep the payment unpaid, mark as pending
            if (!$order->isPaid() && $order->getField('STATUS_ID') !== 'VP') {
                $this->setTransaction($payment, 'N', 'VP');
            }
            LocalRedirect($successUrl, true);
        } elseif (isset($paynetStatusOrder['status']) && trim($paynetStatusOrder['status']) == 'refunded') {
            if ($order->getField('STATUS_ID') !== 'VR') {
                $this->setTransaction($payment, 'N', 'VR', 'Y');
            }
            LocalRedirect($failUrl, true);
        } else {
            if ($order->getField('STATUS_ID') !== 'VC') {
                $this->setTransaction($payment, 'N', 'VC', 'Y');
            }
            LocalRedirect($failUrl, true);
        }
        $paymentId = $request->get('ORDER');

        if (isset($paymentId))
            $paymentId = preg_replace("/^[0]+/","",$paymentId);

        return intval($paymentId);
    }


    public function getCurrencyList()
    {
        return [
            'RUB',
            'EUR',
            'USD'
        ];
    }


    private static function getRequestOrderId(Request $request): int
    {
        $orderId = $request->get('merchant_order') ?? $request->get('ORDER_ID') ?? '';
        if (!ctype_digit((string)$orderId)) {
            return 0;
        }

        return (int)$orderId;
    }


    public static function isMyResponse(Request $request, $paySystemId)
    {
        return true;
    }


    public function processRequest(Payment $payment, Request $request)
    {
        if (empty($this->params)) {
            $this->params = $this->getPaymentParams($payment);
        }

        $this->setPayneteasyLogger();
        $this->logger->setOption('additionalCommonText', 'webhook-' . rand(1111, 9999));

        $shopOrderId = self::getRequestOrderId($request) ?: (int)$payment->getOrderId();

        $order = Order::load($shopOrderId);

        $paynetStatusOrder = $this->getOrderStatus($payment, $shopOrderId);

        if (isset($paynetStatusOrder['status']) && trim($paynetStatusOrder['status']) == 'approved') {
            if ($order->getField('STATUS_ID') !== 'P' && ($order->getField('STATUS_ID') !== 'VR' || $order->getField('STATUS_ID') !== 'VC')) {
                $this->setTransaction($payment, 'Y', 'P');
            }
        } elseif (isset($paynetStatusOrder['status']) && trim($paynetStatusOrder['status']) == 'processing') {
            // processing is not a final status: keep the payment unpaid, mark as pending
            if (!$order->isPaid() && $order->getField('STATUS_ID') !== 'VP') {
                $this->setTransaction($payment, 'N', 'VP');
            }
        } elseif (isset($paynetStatusOrder['status']) && trim($paynetStatusOrder['status']) == 'refunded') {
            if ($order->getField('STATUS_ID') !== 'VR') {
                $this->setTransaction($payment, 'N', 'VR', 'Y');
            }
        } else {
            if ($order->getField('STATUS_ID') !== 'VC') {
                $this->setTransaction($payment, 'N', 'VC', 'Y');
            }
        }
        return 'ok';
    }


    public function refund(Payment $payment, $refundableSum): ServiceResult
    {
        $this->setPayneteasyLogger();
        $this->logger->setOption('additionalCommonText', 'refund-' . rand(1111, 9999));
        $result = new ServiceResult();
        if (empty($this->params)) {
            $this->params = $this->getParamsBusValue($payment);
        }
        $refund = PaynetHandler::setPaymentRefund($this->params, $payment->getOrderId());
        $order = Order::load($payment->getOrderId());
        $statusOrder = $this->getOrderStatus($payment, $payment->getOrderId());
        if (isset($refund['error-code'])) {
            $order->setField('COMMENTS', $refund['error-message']);
            $result->addError(new Error($refund['error-message']));
        } else {
//            if (isset($statusOrder['object']['transactions']['refunds'])) {
                $result->setOperationType(ServiceResult::MONEY_LEAVING);
                if ($order->getField('STATUS_ID') != 'VR') {
                    $this->setTransaction($payment, 'N', 'VR', 'Y');
                    $payment->setPaid('N');
                    $order->setFieldNoDemand('PAYED', 'N');
                    $return = $payment->setReturn('P');
                    if (!$return->isSuccess())
                    {
                        $order->setField('COMMENTS', $return->getErrorMessages());
                        $result->addError(new Error($return->getErrorMessages()));
                    }
                }
//            }
        }
        $order->save();
        return $result;
    }


    private function setPayneteasyLogger(): void
    {
        $logger = PaynetEasyLogger::getInstance();
        $this->logger = $logger
            ->setOption('showBacktrace', true)
            ->setOption('maskSensitiveData', true)
            ->setLogFilePath(dirname(__DIR__, 1) . '/logs/payneteasy-' . date('d-m-Y') . '.log')
            ->setCustomRecording(function($message) use ($logger) {
                if (
                    isset($this->params['PAYNET_DEBUG_MODE']) &&
                    $this->params['PAYNET_DEBUG_MODE'] === 'Y'
                ) $logger->writeToFile($message);
            }, PaynetEasyLogger::LOG_LEVEL_DEBUG);
    }


    private function getPaymentParams(Payment $payment): array
    {
        if (empty($this->params)) {
            $this->params = $this->getParamsBusValue($payment);
        }
        $service = $this->service->getFieldsValues();

        return [
            'PAYNET_DEBUG_MODE' => $this->params['PAYNET_DEBUG_MODE'],
            'PAYNET_LOGIN' => $this->params['PAYNET_LOGIN'],
            'PAYNET_CONTROL_KEY' => $this->params['PAYNET_CONTROL_KEY'],
            'PAYNET_ENDPOINT_ID' => $this->params['PAYNET_ENDPOINT_ID'],
            'PAYNET_SANDBOX' => $this->params['PAYNET_SANDBOX'],
            'PAYNET_ORDER_STATUS' => $this->params['PAYNET_ORDER_STATUS'],
            'PAYNET_PAYMENT_METHOD' => $this->params['PAYNET_PAYMENT_METHOD'],
            'PAYNET_THREE_D_SECURE' => $this->params['PAYNET_THREE_D_SECURE'],
            'PAYNET_LIVE_URL' => $this->params['PAYNET_LIVE_URL'],
            'PAYNET_SANDBOX_URL' => $this->params['PAYNET_SANDBOX_URL'],
            'PAYNET_NEW_WINDOW' => $service['NEW_WINDOW'],
        ];
    }


    private function getOrderStatus($payment, $orderId)
    {
        $this->setPayneteasyLogger();

        if (empty($orderId)) {
            $orderId = $payment->getField('ORDER_ID');
        }

        if (empty($this->params)) {
            $this->params = $this->getPaymentParams($payment);
        }
        
        $response = PaynetHandler::getOrderInfo(
            $this->params,
            $orderId
        );

        return $response;
    }


    private function setTransaction($payment, $isPaid, $statusId, $isCanceled = null)
    {
        $this->logger->debug(
            __FUNCTION__ . " Status for order {$payment->getOrderId()} change to {$statusId}", );

        // Complete transaction, change payment status and mark order to paid
        $payment->setPaid($isPaid);
        $payment->setField('PAID', $isPaid);
        if ($isCanceled == 'Y') $payment->getOrder()->setField('CANCELED', $isCanceled);
        $payment->getOrder()->setField('STATUS_ID', $statusId);
        $payment->getOrder()->save();
    }


    private function checkPayGatewayAnswer($orderStatus, $orderId)
    {
        if (isset($orderStatus['error-message'])) {
            $this->logger->error("Order {$orderId} take error code: {$orderStatus['error-code']}");
            $failUrl = '/bitrix/tools/sale_ps_fail.php?ORDER_ID='.$orderId;
            $protocol = (empty($_SERVER['HTTPS']) ? 'http' : 'https');
            $host = $_SERVER['HTTP_HOST'];
            $failUrl = "{$protocol}://{$host}{$failUrl}";
            LocalRedirect($failUrl, true);
        } else {
            $this->logger->error("Order {$orderId} not paid");
        }
    }

}