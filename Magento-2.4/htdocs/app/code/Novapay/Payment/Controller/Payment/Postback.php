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

// @todo change Custom into PaymentType
// use Novapay\Payment\Model\Config\Source\Custom as PaymentType;

use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\Controller\ResultFactory;
use Magento\Sales\Model\Order;

use Novapay\Payment\Controller\AbstractCheckoutAction;
use Novapay\Payment\SDK\Model\Model;
use Novapay\Payment\SDK\Model\Session;
use Novapay\Payment\SDK\Model\Postback as PostbackSDK;
use Novapay\Payment\SDK\Schema\HTTP\Render\Curl;
use Novapay\Payment\SDK\Schema\Request\PostbackPostRequest;

/**
 * Payment Post controller class.
 * 
 * @category Module
 * @package  Magento2
 * @author   NovaPay <acquiring@novapay.ua>
 * @license  https://github.com/NovaPay/prestashop-integration/blob/master/LICENSE MIT
 * @link     https://novapay.ua/
 */
class Postback extends AbstractCheckoutAction implements CsrfAwareActionInterface
{
    /**
     * @param RequestInterface $request
     * 
     * @return InvalidRequestException|null
     */
    public function createCsrfValidationException(
        RequestInterface $request
    ): ?InvalidRequestException {
        return null;
    }

    /**
     * @param RequestInterface $request
     * 
     * @return bool|null
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * Redirect to checkout
     *
     * @return void
     */
    public function execute()
    {
        $this->initPaymentModel();

        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);

        // post data
        $input    = file_get_contents('php://input');
        $headers  = apache_request_headers();
        $url      = sprintf(
            '%s%s%s', 
            isset($_SERVER['HTTPS']) ? 'https://' : 'http://',
            $_SERVER['HTTP_HOST'],
            $_SERVER['REQUEST_URI']
        );
        $method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $status   = $_SERVER['REDIRECT_STATUS'] ?? 200;
        $protocol = $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1';

        $postback = new PostbackSDK(
            $input,
            $headers,
            $method,
            $status,
            $protocol
        );

        $request = $postback->getRequest();
        $request->setUrl($url)->setMethod($method);
        $response = $postback->getResponse();
        $response->setUrl($url)->setMethod($method);

        $curl = new Curl();

        $fp = fopen($_SERVER['DOCUMENT_ROOT'] . '/var/log/novapay-postback.log', 'a+');
        fputs(
            $fp, 
            sprintf(
                "%s\n\n%s\n",
                $curl->render($request),
                $curl->render($response)
                // "%s\n--\n%s %s %s\n%s\n--\n\n", 
                // date('c'), 
                // $protocol,
                // $status,
                // $method,
                // implode(
                //     "\n\n", 
                //     [
                //         json_encode($headers, JSON_UNESCAPED_UNICODE), 
                //         $input,
                //     ]
                // )
            )
        );
        fclose($fp);

        // @todo enable signature verification
        if (false && !$postback->verify()) {
            // incorrect signature
            $this->logger->critical(
                __(
                    'Incorrect signature on postback %1', 
                    [json_encode(Model::getLog(), JSON_UNESCAPED_UNICODE)]
                )
            );
            $result->setHttpResponseCode(401);
            $result->setData(
                [
                    'messages' => [__('Incorrect signature on postback')],
                    'request'  => $curl->render($request),
                    'response' => $curl->render($response),
                    'headers'  => $response->getHeaders()
                ]
            );
            return $result;
        }

        // $request->id; // session id
        // $request->status;
        // $request->amount;
        // $request->metadata->order_id;
        // $request->metadata->order_real_id;

        $order = $this->getOrder($request->metadata->order_real_id);
        if (!$order) {
            $msg = __('Order %1 not found', $request->metadata->order_real_id);
            $result->setHttpResponseCode(404);
            $result->setData(
                [
                    'messages' => [$msg],
                    'request'  => $request
                ]
            );
            $this->logger->error($msg);
            return $result;
        }

        $status = $this->getPaymentConfig('status_' . $request->status);
        $order->setState($status)->setStatus($status);
        $order->addStatusToHistory($status, __('Order status updated'));
        $order->save();

        $this->logger->debug(__('Order %1 status updated', $order->getEntityId()));

        $session = $postback->getSession();

        $transaction = $this->storeTransaction(
            $order, 
            $request, 
            $session->isClosed()
        );

        // if ($session->isClosed() || $session->status === Session::STATUS_PAID) {
        $invoice = $this->createInvoice($order);
        // }

        $result->setData(
            [
                'messages'     => [__('Order status updated')],
                'order_status' => $order->getState(),
                // 'post'         => $request,
                'transaction'  => [
                    'id' => $transaction->getId(),
                    'session' => $session,
                    'payment' => $transaction->getPayment()->getTxnId(),
                    'invoice' => $invoice->getEntityType()
                ]
            ]
        );

        return $result;
    }

    /**
     * Stores transaction for current 
     * 
     * @param Order               $order   Order instance.
     * @param PostbackPostRequest $request Postback request.
     * 
     * @return [type]
     */
    protected function storeTransaction(
        Order $order, 
        PostbackPostRequest $request,
        $closed = false
    ) {
        $payment = $order->getPayment();
        $payment->setCcTransId($request->id);
        $payment->setLastTransId($request->id);

        // set transaction
        $payment->setTransactionId($request->id);

        // Prepare transaction
        $transaction = $this->transactionBuilder->setPayment($payment)
            ->setOrder($order)
            ->setTransactionId($request->id)
            ->build($this->getTransactionType($request->status));

        $transaction->setCustomAttribute('session_id', $request->id);
        $transaction->setCustomAttribute('postback', $request);
        $transaction->setIsClosed($closed);

        $transaction->save();

        $payment->addTransactionCommentsToOrder(
            $transaction, 
            __('Status updated by postback to %1', $request->status)
        );

        return $transaction;
    }

    /**
     * Creates an invoice for order.
     * 
     * @param Order $order Order instance.
     * 
     * @return Invoice Invoice instance.
     */
    public function createInvoice(Order $order)
    {
        /** @var Invoice $invoice */
        $invoice = $order->prepareInvoice();
        $payment = $order->getPayment();

        $invoice->register();
        if ($payment->getMethodInstance()->canCapture()) {
            $invoice->capture();
        }

        $order->addRelatedObject($invoice);
        return $invoice;
    }
}
