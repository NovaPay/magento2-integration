<?php

// http://magento-dev.sprinterra.com/index.php/admin/novapay/payment/index/index

namespace Novapay\Payment\Controller\Adminhtml\Index;
 
use Magento\Framework\Controller\ResultFactory;
use Magento\Sales\Model\Order;

// use Magento\Framework\App\Action\Action;
use Novapay\Payment\Controller\AbstractCheckoutAction;
use Novapay\Payment\SDK\Model\Session;
use Novapay\Payment\SDK\Model\Payment;

class Index extends AbstractCheckoutAction
{
    protected $resultPageFactory;

    /**
     * Index controller action.
     * Different controllers with routing did not work out.
     * 
     * @todo move all the functions to different controllers.
     * 
     * @return Magento\Framework\Controller\ResultInterface Redirect to the order view or JSON response.
     */
    public function execute()
    {
        switch ($this->getRequest()->get('method')) {
            case 'update':
                return $this->update($this->getRequest()->get('id'));
            case 'cancel':
                return $this->cancel($this->getRequest()->get('id'));
            case 'confirm':
                return $this->confirm(
                    $this->getRequest()->get('id'), 
                    $this->getRequest()->get('amount')
                );
        }

        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        // $result->setUrl($this->_redirect->getRefererUrl());
        $result->setData(
            [
                'method' => $this->getRequest()->get('method'),
                'id'     => $this->getRequest()->get('id'),
                'error'  => 'Undefined methods'
            ]
        );
        return $result;
    }

    /**
     * Updates the order status.
     * 
     * @param mixed $id Order id.
     * 
     * @return Magento\Framework\Controller\ResultInterface Redirect to the order view.
     */
    protected function update($id)
    {
        list($order, $transaction) = $this->prepareBeforeAction($id);

        if (!$transaction || !$transaction->getTxnId()) {
            $this->messageManager->addErrorMessage(__('Transaction not found'));
            return $this->goBack($order);
        }

        $session = new Session();
        $session->id = $transaction->getTxnId();
        $ok = $session->status($this->getPaymentConfig('merchant_id'));

        if (!$ok) {
            $this->messageManager->addErrorMessage(__('Cannot update status'));
            return $this->goBack($order);
        }
        $status = $this->getPaymentConfig('status_' . $session->status);

        if ($order->getStatus() !== $status) {
            $order->setState($status)->setStatus($status);
            $order->addStatusToHistory($status, __('Order status updated manually'));
            $order->save();
            $this->messageManager->addSuccessMessage(
                __('Order status updated manually')
            );
            return $this->goBack($order);
        }
        $this->messageManager->addNoticeMessage(__('Order status was not changed'));
        return $this->goBack($order);
    }

    /**
     * Cancels the payment of an order.
     * 
     * @param mixed $id Order id.
     * 
     * @return Magento\Framework\Controller\ResultInterface Redirect to the order view.
     */
    protected function cancel($id)
    {
        list($order, $transaction) = $this->prepareBeforeAction($id);

        if (!$transaction || !$transaction->getTxnId()) {
            $this->messageManager->addErrorMessage(__('Transaction not found'));
            return $this->goBack($order);
        }

        $payment = new Payment();
        $ok = $payment->cancel(
            $this->getPaymentConfig('merchant_id'), 
            $transaction->getTxnId()
        );

        if (!$ok) {
            $res = $payment->getResponse();
            if ($res && $res->error) {
                $this->messageManager->addErrorMessage($res->error);
            } else {
                $this->messageManager->addErrorMessage(
                    __('Cannot cancel the payment')
                );
            }
            return $this->goBack($order);
        }

        $status = $this->getPaymentConfig('status_' . Session::STATUS_VOIDING);
        if ($order->getStatus() !== $status) {
            $order->setState($status)->setStatus($status);
            $order->addStatusToHistory(
                $status, __('Order payment canceling')
            );
            $order->save();
            $this->messageManager->addSuccessMessage(__('Order payment canceling'));
            return $this->goBack($order);
        }
        $this->messageManager->addNoticeMessage(__('Order status was not changed'));
        return $this->goBack($order);
    }

    /**
     * Confirms hold amount of an order.
     * 
     * @param int   $id     Order id.
     * @param float $amount Amount to confirm.
     * 
     * @return Magento\Framework\Controller\ResultInterface Redirect to the order view.
     */
    protected function confirm($id, $amount)
    {
        list($order, $transaction) = $this->prepareBeforeAction($id);

        if (!$transaction || !$transaction->getTxnId()) {
            $this->messageManager->addErrorMessage(__('Transaction not found'));
            return $this->goBack($order);
        }
        $amount = floatval($amount);
        if ($amount <= 0) {
            return $this->goBack($order);
        }

        // $session = new Session();
        // $session->id = $transaction->getTxnId();
        // $session->status($this->getPaymentConfig('merchant_id'));

        $payment = new Payment();
        $ok = $payment->complete(
            $this->getPaymentConfig('merchant_id'), 
            $transaction->getTxnId(),
            $amount
        );
        if (!$ok) {
            $res = $payment->getResponse();
            if ($res) {
                $this->messageManager->addErrorMessage($res->getMessage());
            } else {
                $this->messageManager->addErrorMessage(
                    __('Cannot confirm hold')
                );
            }
            return $this->goBack($order);
        }

        $status = $order->getStatus();
        $order->addStatusToHistory(
            $status, __('Confirmed hold by %1', $amount)
        );
        $order->save();

        $this->messageManager->addSuccessMessage(
            __('Payment confirm hold processing')
        );
        return $this->goBack($order);
    }

    /**
     * Prepares sdk model, loads order and find last transaction.
     * Returns [Order, Transaction].
     * 
     * @param int $id Order id.
     * 
     * @return array  [Order, Transaction].
     */
    protected function prepareBeforeAction($id)
    {
        $this->initPaymentModel();

        $order = $this->loadOrder($id);

        // Magento\Sales\Api\Data\TransactionSearchResultInterfaceFactory $transactions
        $transactions = $this->transactionSearch->create()->addOrderIdFilter($order->getId());
        $items = $transactions->getItems();
        $transaction = end($items);

        return [$order, $transaction];
    }

    /**
     * Redirects to a view page of the provided order.
     * 
     * @param Order $order Order to redirect.
     * 
     * @return Magento\Framework\Controller\ResultInterface Redirect to the order view.
     */
    protected function goBack(Order $order)
    {
        $result = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $result->setPath('sales/order/view', ['order_id' => $order->getId()]);
        return $result;
    }
}