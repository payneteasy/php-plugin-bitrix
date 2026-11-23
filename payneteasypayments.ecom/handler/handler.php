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


    public function initiatePay(Payment $payment, ?Request $request = null)
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

        // Return from the payment form: show the payment result in the site layout
        $isResultPage = $contextRequest->getQuery('PAYNET_RESULT') === '1';

        if ($payment->isPaid()) {
            // Paid: show the status, do not start another payment
            $this->setExtraParams([
                'ERROR' => 'PAID',
                'ORDER_ID' => $orderId,
                'AMOUNT' => CurrencyFormat($payment->getSum(), $this->order->getCurrency()),
                'WINDOW' => $isResultPage,
            ]);
            return $this->showTemplate($payment, "template");
        }

        $queryData = $contextRequest->getQueryList()->toArray(); // GET parameters

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

        // The payment is still being processed by the gateway: do not start another one
        if ($isResultPage && $this->order->getField('STATUS_ID') === 'VP') {
            $this->setExtraParams([
                'ERROR' => 'PROCESSING',
                'NOTICE' => 'PROCESSING',
                'ORDER_ID' => $orderId,
                'WINDOW' => true,
            ]);
            return $this->showTemplate($payment, "template");
        }

        $redirectUrl = '';
        if ($this->params['PAYNET_PAYMENT_METHOD'] == 'form') {
            // The form of the previous attempt still waits for the card: use it,
            // do not start another sale for the same order
            $redirectUrl = PaynetHandler::getOpenPaymentForm($this->params, $orderId);
            if ($redirectUrl === null && isset($orderId)) {
                $paynetPaymentResponse = $PaynetHandler->getPaynetPaymentResponse(
                    $this->order,
                    $this->params,
                    $orderId,
                    $payment
                );
                $redirectUrl = $paynetPaymentResponse['redirect-url'] ?? '';
            }
        } elseif ($this->params['PAYNET_PAYMENT_METHOD'] == 'direct') {
            $callbackUrl = '/bitrix/tools/sale_ps_result.php?ORDER_ID='.$orderId??$payment->getOrderId();
            $protocol = (empty($_SERVER['HTTPS']) ? 'http' : 'https');
            $host = \Paynet\Pay\PaynetHandler::getSiteHost();
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
        if ($this->params['PAYNET_NEW_WINDOW'] === 'Y' || $isResultPage) {
            $args['WINDOW'] = true;
        }

        // Back from the payment form, but the order is not paid
        if ($isResultPage) {
            $args['NOTICE'] = $this->order->getField('STATUS_ID') === 'VP' ? 'PROCESSING' : 'NOT_PAID';
            $args['ORDER_ID'] = $orderId;
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
        // Use the order's existing PaynetEasy payment; creating a new one on every
        // return or callback left the original payment unpaid and added duplicates
        $payment = null;
        foreach ($order->getPaymentCollection() as $item) {
            if ($item->getPaySystem() && $item->getPaySystem()->getField('ACTION_FILE') === 'payneteasypayments') {
                $payment = $item;
                if (!$item->isPaid()) {
                    break;
                }
            }
        }
        if ($payment === null) {
            return false;
        }

        if (empty($this->params)) {
            $this->params = $this->getPaymentParams($payment);
        }

        $redirectUrl = '/personal/orders/'.(int)$shopOrderId;
        $callbackUrl = '/bitrix/tools/sale_ps_result.php?ORDER_ID='.$shopOrderId;
        // After the status check the customer sees the payment page with the result
        // (a real page of the shop, it does not depend on the URL rewrite settings)
        $successUrl = '/personal/order/payment/?ORDER_ID='.(int)$shopOrderId
            .'&PAYMENT_ID='.urlencode((string)$payment->getField('ACCOUNT_NUMBER'))
            .'&PAYNET_RESULT=1';
        $failUrl = $successUrl;

        $protocol = (empty($_SERVER['HTTPS']) ? 'http' : 'https');
        $host = \Paynet\Pay\PaynetHandler::getSiteHost();
        $redirectUrl = "{$protocol}://{$host}{$redirectUrl}";
        $callbackUrl = "{$protocol}://{$host}{$callbackUrl}";
        $successUrl = "{$protocol}://{$host}{$successUrl}";
        $failUrl = "{$protocol}://{$host}{$failUrl}";

        if ($this->params['PAYNET_PAYMENT_METHOD'] == 'direct') {
            $PaynetHandler = new PaynetHandler();
            $hasCard = isset($_POST['expire_year']) && isset($_POST['expire_month']) && isset($_POST['credit_card_number']) && isset($_POST['card_printed_name']) && isset($_POST['cvv2']);
            // Only the customer of the order may start a payment with a card
            if ($hasCard && !PaynetHandler::isOrderOwnerRequest($order)) {
                $this->logger->error("Order {$shopOrderId}: card payment request not from the order customer, ignored");
                $hasCard = false;
            }
            if ($hasCard) {
                $paynetPaymentResponse = $PaynetHandler->getPaynetPaymentResponse(
                    $order,
                    $this->params,
                    $shopOrderId,
                    $payment
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

            if (isset($paynetStatusOrder['status']) && trim($paynetStatusOrder['status']) == 'approved' && $this->isPaidInFull($payment, $paynetStatusOrder)) {
                // A declined attempt (VC) does not block a later approved payment
                if ($order->getField('STATUS_ID') !== 'P' && $order->getField('STATUS_ID') !== 'VR') {
                    $this->setTransaction($payment, 'Y', 'P');
                }
                LocalRedirect($successUrl, true);
            } elseif (isset($paynetStatusOrder['status']) && trim($paynetStatusOrder['status']) == 'processing') {
                // processing is not a final status: keep the payment unpaid, mark as pending
                if (!$order->isPaid() && $order->getField('STATUS_ID') !== 'VP') {
                    $this->setTransaction($payment, 'N', 'VP');
                }
                // The gateway asks for 3-D Secure: show its page, it returns the customer here
                if (!empty($paynetStatusOrder['html'])) {
                    echo $paynetStatusOrder['html'];
                    die();
                }
                // Otherwise show the result page ("being processed"); redirecting back
                // to this handler would loop while the gateway keeps the status
                LocalRedirect($successUrl, true);
            } elseif (isset($paynetStatusOrder['status']) && trim($paynetStatusOrder['status']) == 'refunded') {
                if ($order->getField('STATUS_ID') !== 'VR') {
                    $this->setTransaction($payment, 'N', 'VR', 'Y');
                }
                LocalRedirect($failUrl, true);
            } elseif (isset($paynetStatusOrder['status']) && in_array(trim($paynetStatusOrder['status']), ['declined', 'error', 'filtered'], true)) {
                if (!$order->isPaid() && $order->getField('STATUS_ID') !== 'VC') {
                    // Declined attempt: the order stays open for another payment
                    $this->setTransaction($payment, 'N', 'VC');
                }
                LocalRedirect($failUrl, true);
            } else {
                // status query failed or returned no final status: leave the order untouched
                $this->logger->error("Order {$shopOrderId}: no final status from gateway, order not changed");
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

        if (isset($paynetStatusOrder['status']) && trim($paynetStatusOrder['status']) == 'approved' && $this->isPaidInFull($payment, $paynetStatusOrder)) {
            // A declined attempt (VC) does not block a later approved payment
                if ($order->getField('STATUS_ID') !== 'P' && $order->getField('STATUS_ID') !== 'VR') {
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
        } elseif (isset($paynetStatusOrder['status']) && in_array(trim($paynetStatusOrder['status']), ['declined', 'error', 'filtered'], true)) {
            if (!$order->isPaid() && $order->getField('STATUS_ID') !== 'VC') {
                // Declined attempt: the order stays open for another payment
                    $this->setTransaction($payment, 'N', 'VC');
            }
            LocalRedirect($failUrl, true);
        } else {
            // status query failed or returned no final status: leave the order untouched
            $this->logger->error("Order {$shopOrderId}: no final status from gateway, order not changed");
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
        $orderId = self::getRequestOrderId($request);
        if ($orderId <= 0) {
            return false;
        }

        $order = Order::load($orderId);
        if (!$order) {
            return false;
        }

        return in_array((int)$paySystemId, array_map('intval', $order->getPaymentSystemId()), true);
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

        if (isset($paynetStatusOrder['status']) && trim($paynetStatusOrder['status']) == 'approved' && $this->isPaidInFull($payment, $paynetStatusOrder)) {
            // A declined attempt (VC) does not block a later approved payment
                if ($order->getField('STATUS_ID') !== 'P' && $order->getField('STATUS_ID') !== 'VR') {
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
        } elseif (isset($paynetStatusOrder['status']) && in_array(trim($paynetStatusOrder['status']), ['declined', 'error', 'filtered'], true)) {
            if (!$order->isPaid() && $order->getField('STATUS_ID') !== 'VC') {
                // Declined attempt: the order stays open for another payment
                    $this->setTransaction($payment, 'N', 'VC');
            }
        } else {
            // status query failed or returned no final status: leave the order untouched
            $this->logger->error("Order {$shopOrderId}: no final status from gateway, order not changed");
        }
        return 'ok';
    }


    /**
     * Called by Bitrix from Payment::setReturn('P'): Bitrix itself marks the
     * payment unpaid when MONEY_LEAVING is returned and the caller saves the order.
     */
    public function refund(Payment $payment, $refundableSum): ServiceResult
    {
        $this->setPayneteasyLogger();
        $this->logger->setOption('additionalCommonText', 'refund-' . rand(1111, 9999));
        $result = new ServiceResult();
        if (empty($this->params)) {
            $this->params = $this->getParamsBusValue($payment);
        }

        $sum = (float)$refundableSum > 0 ? $refundableSum : $payment->getSum();
        $refund = PaynetHandler::setPaymentRefund($this->params, $payment->getOrderId(), $sum, $payment->getField('CURRENCY'));

        if (isset($refund['error-code'])) {
            $result->addError(new Error((string)$refund['error-message']));
            return $result;
        }

        $result->setOperationType(ServiceResult::MONEY_LEAVING);

        // VR keeps the order from being marked paid again by the approved reversal status
        $order = $payment->getCollection()->getOrder();
        if ($order->getField('STATUS_ID') !== 'VR') {
            $order->setField('STATUS_ID', 'VR');
        }

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


    /**
     * True if the approved sale covers this payment: same amount and currency.
     * Otherwise the payment is not marked paid (the order may have been changed).
     */
    private function isPaidInFull($payment, array $status): bool
    {
        $amount = trim((string)($status['amount'] ?? ''));
        $currency = strtoupper(trim((string)($status['currency'] ?? '')));
        $paid = is_numeric($amount)
            && $currency === strtoupper((string)($payment->getField('CURRENCY') ?: $payment->getCollection()->getOrder()->getCurrency()))
            && (int)round((float)$amount * 100) === (int)round((float)$payment->getSum() * 100);
        if (!$paid) {
            $this->logger->error("Order {$payment->getOrderId()}: approved {$amount} {$currency} does not match the payment {$payment->getSum()}, not marked paid");
        }
        return $paid;
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
            $host = \Paynet\Pay\PaynetHandler::getSiteHost();
            $failUrl = "{$protocol}://{$host}{$failUrl}";
            LocalRedirect($failUrl, true);
        } else {
            $this->logger->error("Order {$orderId} not paid");
        }
    }

}