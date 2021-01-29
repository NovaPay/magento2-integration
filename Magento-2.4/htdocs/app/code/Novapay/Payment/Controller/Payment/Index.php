<?php

/**
 * Payment Post controller class.
 * 
 * PHP version 7.X
 * 
 * @category Module
 * @package  Magento2
 * @author   NovaPay <acquiring@novapay.ua>
 * @license  https://github.com/NovaPay/prestashop-integration/blob/master/LICENSE MIT
 * @link     https://novapay.ua/
 */

namespace Novapay\Payment\Controller\Payment;

use Magento\Framework\Controller\ResultFactory;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\TransactionInterface;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Order\Invoice;

use Novapay\Payment\Controller\AbstractCheckoutAction;
use Novapay\Payment\Model\Adminhtml\Source\PaymentType;
use Novapay\Payment\SDK\Schema\Callback;
use Novapay\Payment\SDK\Schema\Client;
use Novapay\Payment\SDK\Schema\Metadata;
use Novapay\Payment\SDK\Schema\Product;
use Novapay\Payment\SDK\Model\Model;
use Novapay\Payment\SDK\Model\Payment;
use Novapay\Payment\SDK\Model\Session;
use Novapay\Payment\SDK\Schema\Response\Error\Error as ResponseError;
use Novapay\Delivery\Gateway\Config as DeliveryConfig;

/**
 * Payment Post controller class.
 * 
 * @category Module
 * @package  Magento2
 * @author   NovaPay <acquiring@novapay.ua>
 * @license  https://github.com/NovaPay/prestashop-integration/blob/master/LICENSE MIT
 * @link     https://novapay.ua/
 */
class Index extends AbstractCheckoutAction
{
    /**
     * Redirect to checkout
     *
     * @return void
     */
    public function execute()
    {
        $order  = $this->getOrder();
        // @todo remove this test
        // $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        // $result->setData(['order' => $order]);
        // return $result;

        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        if (!$order || !$order->getEntityId()) {
            $result->setHttpResponseCode(404);
            $result->setData(['errors' => [__('Order ID not found')]]);
            return $result;
        }

        $payment = $order->getPayment();
        if ($payment->getLastTransId()) {
            $result->setHttpResponseCode(202);
            $result->setData(
                [
                    'messages' => [
                        __('Payment created already %1', $payment->getLastTransId())
                    ]
                ]
            );
            return $result;
        }

        $this->initPaymentModel();

        $session = $this->createSession($order);
        if (!$session instanceof Session) {
            // session is a result with the errors
            $this->restoreCart();
            return $session;
        }

        $transaction = $this->saveOrderSession($session, $order);

        $payment = $this->createPayment($session, $order);
        if (!$payment instanceof Payment) {
            // payment is a result with the errors
            $this->restoreCart();
            return $payment;
        }

        $invoice = $this->createInvoice($order, $transaction);

        $status = $this->getPaymentStatus(Session::STATUS_CREATED);
        $order->setState($status)->setStatus($status);
        $order->addStatusToHistory($status, __('Order status created'));
        $order->save();

        $this->logger->debug(
            __(
                'API requests %1', 
                [json_encode(Model::getLog(), JSON_UNESCAPED_UNICODE)]
            )
        );

        $result->setData(
            [
                'messages'    => [
                    __('Order created. Redirecting to payment')
                ],
                'session'     => $session,
                'payment'     => $payment,
                'transaction' => $transaction,
                'invoice'     => $invoice,
                'items'       => $payment->getRequest()
            ]
        );

        return $result;
    }

    /**
     * Create SDK/API session from the current order.
     * 
     * @param OrderInterface $order Current order.
     * 
     * @return Novapay\Payment\SDK\Model\Session           Session if created
     *        |Magento\Framework\Controller\AbstractResult Result on failure.
     */
    protected function createSession(OrderInterface $order)
    {
        $address = $order->getBillingAddress();
        $telephone = trim($address->getTelephone());
        // if ('0' === substr($telephone, 0, 1)) {
        //     $telephone = '+38' . $telephone;
        // }
        $session = new Session();
        $ok = $session->create(
            $this->getPaymentConfig('merchant_id'),
            new Client(
                $address->getFirstName(),
                $address->getLastName(),
                '', // @todo add middle name?
                $telephone,
                $address->getCustomerEmail()
            ),
            new Metadata(
                [
                    'order_id'      => $order->getEntityId(),
                    'order_real_id' => $order->getRealOrderId()
                ]
            ),
            new Callback(
                // @todo attach controller and real URL
                (isset($_SERVER['HTTPS']) ? 'https://' : 'http://')
                    . $_SERVER['HTTP_HOST'] . '/novapay/payment/postback',
                // 'Controller/Checkout/Postback',
                $this->getPaymentConfig('success_url'),
                $this->getPaymentConfig('fail_url')
            )
        );

        if (!$ok) {
            $res = $session->getResponse();
            $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
            $result->setHttpResponseCode(406);
            $data = [
                'errors' => [
                    $res instanceof ResponseError
                        ? $res->message
                        : __('Cannot create payment session')
                ],
                'method' => 'createSession'
            ];
            if ($this->getPaymentConfig('debug')) {
                $data = array_merge($data, $this->getDebugInfo());
            }
            $result->setData($data);
            return $result;
        }

        return $session;
    }

    /**
     * Creates payment transaction and save the session id as transaction id.
     * 
     * @param Session        $session SDK/API session.
     * @param OrderInterface $order   Current order.
     * 
     * @return Magento\Sales\Model\Order\Payment\Transaction Payment transaction.
     */
    protected function saveOrderSession(Session $session, OrderInterface $order)
    {
        $payment = $order->getPayment();
        $payment->setCcTransId($session->id);
        $payment->setLastTransId($session->id);

        // set transaction
        $payment->setTransactionId($session->id);

        // Prepare transaction
        $transaction = $this->transactionBuilder->setPayment($payment)
            ->setOrder($order)
            ->setTransactionId($session->id)
            ->build(TransactionInterface::TYPE_CAPTURE);

        $transaction->setCustomAttribute('session_id', $session->id);
        $transaction->setIsClosed(false);

        $transaction->save();

        return $transaction;
    }

    /**
     * Post SDK/API payment in session from the current order.
     * 
     * @param Session        $session SDK session.
     * @param OrderInterface $order   Current order.
     * 
     * @return Novapay\Payment\SDK\Model\Payment           Payment if posted
     *        |Magento\Framework\Controller\AbstractResult Result on failure.
     */
    protected function createPayment(Session $session, OrderInterface $order)
    {
        $payment = new Payment();
        $delivery = $this->getShippingDelivery($order);

        $total = $order->getSubtotal();

        $items = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $items[] = new Product(
                $item->getName(),
                $item->getRowTotalInclTax(),
                $item->getQtyOrdered()
            );
        }

        // Secure delivery version adding shipping amount on payment processing side
        if (!$delivery && $order->getShippingAmount() > 0) {
            $items[] = new Product(
                $order->getShippingDescription(),
                $order->getShippingAmount(),
                1
            );
            $total += $order->getShippingAmount();
        }

        $ok = $payment->create(
            $this->getPaymentConfig('merchant_id'),
            $session->id,
            $items,
            $total,
            PaymentType::HOLD == $this->getPaymentConfig('payment_type'),
            $order->getRealOrderId(),
            $delivery
        );

        if (!$ok) {
            $res = $payment->getResponse();
            $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
            $result->setHttpResponseCode(406);
            $data = [
                'errors' => [
                    $res instanceof ResponseError
                        ? $res->message
                        : __('Cannot post a payment')
                ],
                'method' => 'createPayment',
                'shipping' => $delivery
            ];
            if ($this->getPaymentConfig('debug')) {
                $data = array_merge($data, $this->getDebugInfo());
            }
            $result->setData($data);
            return $result;
        }
        return $payment;
    }

    protected function getDebugInfo()
    {
        return [
            'options' => [
                'title' => $this->getPaymentConfig('title'),
                'mode'  => $this->getPaymentConfig('mode'),
                'is_live' => $this->getPaymentConfig('is_live'),
            ],
            'log' => Model::getLog()
        ];
    }

    /**
     * Restores shopping cart items.
     * 
     * @return void
     */
    protected function restoreCart()
    {
        $id = $this->checkoutSession->getLastQuoteId();
        $quote = $this->quoteFactory->create()->loadByIdWithoutStore($id);
        $result = [
            'quote_id' => $quote->getId()
        ];
        if (!$quote->getId()) {
            return $result;
        }
        $quote->setIsActive(true)->setReservedOrderId(null)->save();
        $this->checkoutSession->replaceQuote($quote);

        return $result;
    }

    /**
     * Creates invoice for the current order and transaction.
     * 
     * @param OrderInterface $order       Current order.
     * @param Transaction    $transaction Current transaction
     * 
     * @return Magento\Sales\Model\Order\Invoice|null Created invoice.
     */
    protected function createInvoice(OrderInterface $order, Transaction $transaction)
    {
        if (!$order->canInvoice()) {
            return null;
        }

        // @todo fix it
        // throwing an exception, might be because of Gateway process with SDK
        return null;

        $invoice = $this->invoiceService->prepareInvoice($order);
        $invoice->setRequestedCaptureCase(Invoice::CAPTURE_ONLINE);
        $invoice->register();

        $transaction->addObject($invoice)->addObject($invoice->getOrder());

        $transaction->save();

        return $invoice;
    }

    /**
     * Returns Delivery schema object for current order.
     *
     * @param OrderInterface $order Order object.
     *
     * @return Novapay\Payment\SDK\Schema\Delivery|null The delivery schema object.
     */
    protected function getShippingDelivery(OrderInterface $order)
    {
        $method = $order->getShippingMethod();
        if ('novapay_novapay' != $method) {
            return null;
        }
        if (!class_exists(DeliveryConfig::class)) {
            return null;
        }

        // wrong way of using factory, just because of dependency on another module
        $config = new DeliveryConfig($this->scopeConfig, $this->getObjectManager());
        $ship = $config->getDeliveryForOrder($order);
        return $ship->delivery;
    }
}
