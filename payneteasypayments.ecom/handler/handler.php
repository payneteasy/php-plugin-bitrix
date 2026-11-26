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
            $redirectUrl = PaynetHandler::getOpenPaymentForm($this->params, $orderId, $payment->getId(), $this->useLegacyAttempts($payment));
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
            // The card form names the payment: an order may have several payments
            $formQuery = '?ORDER_ID='.(int)$orderId.'&PAYNET_PAYMENT='.(int)$payment->getId();
            $callbackUrl = '/bitrix/tools/sale_ps_result.php'.$formQuery;
            $protocol = (empty($_SERVER['HTTPS']) ? 'http' : 'https');
            $host = \Paynet\Pay\PaynetHandler::getSiteHost();
            $redirectUrl = "{$protocol}://{$host}{$callbackUrl}";
            // With 3-D Secure the form goes to the module's own script in /bitrix/tools/
            if ($this->params['PAYNET_THREE_D_SECURE'] == 'Y' && PaynetHandler::ensureThreeDSecureScript()) {
                $redirectUrl = '/bitrix/tools/payneteasy_3ds.php'.$formQuery;
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

        if ($shopOrderId <= 0) {
            return false;
        }

        $order = Order::load($shopOrderId);
        if (!$order) {
            return false;
        }
        $payment = self::findRequestPayment($order, $request);
        if ($payment === null) {
            return false;
        }

        if (empty($this->params)) {
            $this->params = $this->getPaymentParams($payment);
        }

        // After the status check the customer sees the payment page with the result
        // (a real page of the shop, it does not depend on the URL rewrite settings)
        $successUrl = '/personal/order/payment/?ORDER_ID='.(int)$shopOrderId
            .'&PAYMENT_ID='.urlencode((string)$payment->getField('ACCOUNT_NUMBER'))
            .'&PAYNET_RESULT=1';
        $failUrl = $successUrl;

        $protocol = (empty($_SERVER['HTTPS']) ? 'http' : 'https');
        $host = \Paynet\Pay\PaynetHandler::getSiteHost();
        $successUrl = "{$protocol}://{$host}{$successUrl}";
        $failUrl = "{$protocol}://{$host}{$failUrl}";

        // The customer return and the gateway callback come at the same time:
        // one request at a time checks and changes an order.
        if (!PaynetHandler::lockOrder($shopOrderId)) {
            $this->logger->error("Order {$shopOrderId}: another request is processing the order, order not changed");
            LocalRedirect($failUrl, true);
            return false;
        }

        $status = '';
        $html = '';
        try {
            // The order may have been changed while waiting for the lock: read it again
            $paymentId = $payment->getId();
            $order = Order::load($shopOrderId);
            $payment = $order ? $order->getPaymentCollection()->getItemById($paymentId) : null;
            if (!$payment) {
                return false;
            }

            $isDirect = $this->params['PAYNET_PAYMENT_METHOD'] == 'direct';

            if ($isDirect) {
                $PaynetHandler = new PaynetHandler();
                $hasCard = isset($_POST['expire_year']) && isset($_POST['expire_month']) && isset($_POST['credit_card_number']) && isset($_POST['card_printed_name']) && isset($_POST['cvv2']);
                // Only the customer of the order may start a payment with a card
                if ($hasCard && !PaynetHandler::isOrderOwnerRequest($order)) {
                    $this->logger->error("Order {$shopOrderId}: card payment request not from the order customer, ignored");
                    $hasCard = false;
                }
                // A repeated submit of the card form must not send another sale
                if ($hasCard) {
                    $priorStatus = $payment->isPaid() ? 'approved' : trim((string)($this->getOrderStatus($payment, $shopOrderId)['status'] ?? ''));
                    if ($priorStatus === 'approved' || $priorStatus === 'processing') {
                        $this->logger->error("Order {$shopOrderId}: a payment attempt is already {$priorStatus}, the card form is not sent again");
                        $hasCard = false;
                    }
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
            }

            $paynetStatusOrder = $this->getOrderStatus($payment, $shopOrderId);

            $this->logger->debug(
                __FUNCTION__ . ' > direct: ', [
                'arguments' => [
                    'paynetStatusOrder' => $paynetStatusOrder,
                ]
            ]);

            $status = $this->applyPaymentStatus($order, $payment, $paynetStatusOrder, $shopOrderId);

            // The gateway asks for 3-D Secure: show its page, it returns the customer here
            if ($isDirect && $status === 'processing' && !empty($paynetStatusOrder['html'])) {
                $html = $paynetStatusOrder['html'];
            }
        } finally {
            PaynetHandler::unlockOrder($shopOrderId);
        }

        if ($html !== '') {
            echo $html;
            die();
        }

        // Show the result page (for a payment in progress: "being processed";
        // redirecting back to this handler would loop while the gateway keeps the status)
        LocalRedirect(in_array($status, ['approved', 'processing'], true) ? $successUrl : $failUrl, true);

        return false;
    }


    /**
     * The payment of the order this request is about: the one named by the
     * plugin's own URLs, otherwise the order's unpaid PaynetEasy payment.
     */
    private static function findRequestPayment($order, Request $request)
    {
        $collection = $order->getPaymentCollection();

        $requested = $request->get('PAYNET_PAYMENT');
        if (is_scalar($requested) && ctype_digit((string)$requested)) {
            $item = $collection->getItemById((int)$requested);
            if ($item && self::isPaynetPayment($item)) {
                return $item;
            }
        }

        // Use the order's existing PaynetEasy payment; creating a new one on every
        // return or callback left the original payment unpaid and added duplicates
        $payment = null;
        foreach ($collection as $item) {
            if (self::isPaynetPayment($item)) {
                $payment = $item;
                if (!$item->isPaid()) {
                    break;
                }
            }
        }

        return $payment;
    }


    private static function isPaynetPayment($payment): bool
    {
        return $payment->getPaySystem() && $payment->getPaySystem()->getField('ACTION_FILE') === 'payneteasypayments';
    }


    /**
     * Applies the gateway status to the payment and the order.
     * Called under the order lock, with the order just read from the database.
     *
     * @return string approved, processing, refunded or declined; empty when there is no final answer
     */
    private function applyPaymentStatus($order, $payment, $paynetStatusOrder, $shopOrderId): string
    {
        $status = isset($paynetStatusOrder['status']) ? trim((string)$paynetStatusOrder['status']) : '';

        if ($status === 'approved' && $this->isPaidInFull($payment, $paynetStatusOrder)) {
            // A declined attempt (VC) does not block a later approved payment;
            // a paid payment and the order status set since then are left as they are
            if (!$payment->isPaid() && $order->getField('STATUS_ID') !== 'VR') {
                $this->setTransaction($payment, 'Y', 'P');
            }
            return 'approved';
        }

        if ($status === 'processing') {
            // processing is not a final status: keep the payment unpaid, mark as pending
            if (!$order->isPaid() && $order->getField('STATUS_ID') !== 'VP') {
                $this->setTransaction($payment, 'N', 'VP');
            }
            return 'processing';
        }

        if ($status === 'refunded') {
            if ($order->getField('STATUS_ID') !== 'VR') {
                $this->setTransaction($payment, 'N', 'VR', 'Y');
            }
            return 'refunded';
        }

        if (in_array($status, ['declined', 'error', 'filtered'], true)) {
            if (!$order->isPaid() && $order->getField('STATUS_ID') !== 'VC') {
                // Declined attempt: the order stays open for another payment
                $this->setTransaction($payment, 'N', 'VC');
            }
            return 'declined';
        }

        // status query failed or returned no final status: leave the order untouched
        $this->logger->error("Order {$shopOrderId}: no final status from gateway, order not changed");

        return '';
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

        if (!PaynetHandler::lockOrder($shopOrderId)) {
            $this->logger->error("Order {$shopOrderId}: another request is processing the order, order not changed");
            return 'ok';
        }

        try {
            // The order may have been changed while waiting for the lock: read it again
            $order = Order::load($shopOrderId);
            $payment = $order ? $order->getPaymentCollection()->getItemById($payment->getId()) : null;
            if ($payment) {
                $paynetStatusOrder = $this->getOrderStatus($payment, $shopOrderId);
                $this->applyPaymentStatus($order, $payment, $paynetStatusOrder, $shopOrderId);
            }
        } finally {
            PaynetHandler::unlockOrder($shopOrderId);
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
        $refund = PaynetHandler::setPaymentRefund($this->params, $payment->getOrderId(), $sum, $payment->getField('CURRENCY'), $payment->getId());

        if (isset($refund['error-code'])) {
            $result->addError(new Error((string)$refund['error-message']));
            return $result;
        }

        // The gateway accepted the request but has not confirmed the refund yet.
        // This is not an error (the request must not be sent again) and not a
        // completed refund: the payment stays paid, the order is marked for the admin.
        if (($refund['refund-state'] ?? '') !== 'approved') {
            $this->logger->error("Order {$payment->getOrderId()}: refund accepted by the gateway, result unknown");
            $this->markRefundPending($payment);
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


    /**
     * Tells the admin (a marker on the order) that the refund result is unknown.
     */
    private function markRefundPending(Payment $payment): void
    {
        try {
            $warning = new \Bitrix\Sale\Result();
            $warning->addWarning(new \Bitrix\Sale\ResultWarning(
                'PaynetEasy: the refund request was accepted by the gateway, the result is not known yet.'
                . ' Do not send it again: check the transaction in the PaynetEasy back office.',
                'PAYNET_REFUND_RESULT_UNKNOWN'
            ));
            \Bitrix\Sale\EntityMarker::addMarker($payment->getCollection()->getOrder(), $payment, $warning);
            $payment->setField('MARKED', 'Y');
        } catch (\Throwable $e) {
            $this->logger->error("Order {$payment->getOrderId()}: the refund notice was not added: " . $e->getMessage());
        }
    }


    /**
     * Payment attempts stored by earlier versions have no payment id. They are
     * not used for this payment when another PaynetEasy payment of the order
     * is paid: the stored attempt may be the one that paid it.
     */
    private function useLegacyAttempts($payment): bool
    {
        foreach ($payment->getCollection() as $item) {
            if ($item->getId() != $payment->getId() && $item->isPaid() && self::isPaynetPayment($item)) {
                return false;
            }
        }

        return true;
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
        
        // Only the attempts of this payment: an order may have several payments
        $response = PaynetHandler::getOrderInfo(
            $this->params,
            $orderId,
            $payment->getId(),
            $this->useLegacyAttempts($payment)
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
        // An approved reversal is not a payment
        if (in_array(strtolower(trim((string)($status['transaction-type'] ?? ''))), ['reversal', 'chargeback'], true)) {
            $this->logger->error("Order {$payment->getOrderId()}: the approved transaction is a reversal, not marked paid");
            return false;
        }
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