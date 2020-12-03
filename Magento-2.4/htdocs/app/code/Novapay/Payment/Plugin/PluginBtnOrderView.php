<?php

namespace Novapay\Payment\Plugin;

use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Order;
use Magento\Sales\Block\Adminhtml\Order\View;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Novapay\Payment\Gateway\Config;
use Novapay\Payment\SDK\Model\Session;

use DateTime;

class PluginBtnOrderView
{
    protected $context;
    protected $config;

    private $_orderFactory;
    private $_order;
    private $_view;

    public function __construct(
        OrderFactory $orderFactory, 
        ContextInterface $context,
        Config $config
    ) {
        $this->_orderFactory = $orderFactory;
        $this->context       = $context;
        $this->config        = $config;
    }

    public function beforeSetLayout(View $subject)
    {
        $this->loadOrder($subject->getOrderId());
        $this->_view = $subject;

        $this->addUpdateButton();
        $this->addCancelButton();
        $this->addConfirmButton();
    }

    protected function loadOrder($orderId)
    {
        $this->_order = $this->_orderFactory->create()->load($orderId);
    }

    public function getOrder()
    {
        return $this->_order;
    }

    public function getView()
    {
        return $this->_view;
    }

    /**
     * Adds an update button on the order view.
     * 
     * @return void
     */
    protected function addUpdateButton()
    {
        $order = $this->getOrder();
        $view  = $this->getView();
        if (!$order || !$view) {
            return;
        }

        if (!$this->canUpdate($order)) {
            return;
        }

        // @todo check if order (transaction) is closed already no need to show this button
        //       or make it disabled
        $url = $this->context->getUrl(
            // '/novapay/checkout/order?id=%s',
            'adminhtml/novapay', 
            [
                'method' => 'update',
                'id' => $order->getEntityId()
            ]
        );
        // @todo use secure hash or Admin/Controller.
        $url = sprintf('/index.php/admin/novapay?method=update&id=%s', $order->getEntityId());
        $view->addButton(
            'updatestatus',
            [
                // @todo visible only for non-final payments
                'label' => __('Check payment status'),
                'onclick' => "setLocation('$url')",
            ]
        );
    }

    /**
     * Adds a cancel button to the order view.
     * 
     * @todo No need to add for canceled orders.
     *       Handle standard cancel event.
     * 
     * @return void
     */
    protected function addCancelButton()
    {
        $order = $this->getOrder();
        $view  = $this->getView();
        if (!$order || !$view) {
            return;
        }

        if (!$this->canCancel($order)) {
            return;
        }

        $message = __('Do you really want to cancel this order?');
        // $url2 = '/novapay/checkout/cancel?id=' . $orderId;

        $url = $this->context->getUrl(
            // '/novapay/checkout/cancel',
            'adminhtml/novapay/payment/cancel', 
            ['id' => $order->getEntityId()]
        );
        // @todo use secure hash or Admin/Controller.
        $url = sprintf('/index.php/admin/novapay?method=cancel&id=%s', $order->getEntityId());

        $view->addButton(
            'cancelpaid',
            [
                // @todo visible only orders possible to cancel
                'label' => __('Cancel payment'),
                'onclick' => "confirmSetLocation('$message', '$url')",
            ]
        );
    }

    /**
     * Adds a confirm hold button to the order view.
     * 
     * @todo No need to add for orders which are not holded.
     * 
     * @return void
     */
    protected function addConfirmButton()
    {
        // novapayConfirmHold
        $order = $this->getOrder();
        $view  = $this->getView();
        if (!$order || !$view) {
            return;
        }

        if (!$this->canConfirmHold($order)) {
            return;
        }

        $url = $this->context->getUrl(
            // '/novapay/checkout/cancel',
            'adminhtml/novapay/payment/cancel', 
            ['id' => $order->getEntityId()]
        );
        // @todo use secure hash or Admin/Controller.
        $url = sprintf(
            '/index.php/admin/novapay?method=confirm&id=%s&amount={amount}', 
            $order->getEntityId()
        );

        $amount = sprintf('%.2f', $order->getGrandTotal());

        $view->addButton(
            'confirmpaid',
            [
                // @todo visible only orders possible to cancel
                'label' => __('Confirm payment'),
                'onclick' => "novapayConfirmHold('$amount', '$url')",
            ]
        );
    }

    /**
     * Check if it is allowed to update current order.
     * 
     * @param Order $order Current order.
     * 
     * @return bool        TRUE if allowed to update, otherwise FALSE.
     */
    protected function canUpdate(Order $order)
    {
        // cancel payment is always shown
        return true;
    }

    /**
     * Check if it is allowed to cancel current order.
     * 
     * @param Order $order Current order.
     * 
     * @return bool        TRUE if allowed to cancel, otherwise FALSE.
     */
    protected function canCancel(Order $order)
    {
        // If payment status <> Expired | Failed | Voiding | Voided
        $invisible = [
            $this->config->getPaymentStatus(Session::STATUS_EXPIRED),
            $this->config->getPaymentStatus(Session::STATUS_FAILED),
            $this->config->getPaymentStatus(Session::STATUS_VOIDING),
            $this->config->getPaymentStatus(Session::STATUS_VOIDED)
        ];
        $status = $order->getStatus();
        if (in_array($status, $invisible)) {
            return false;
        }

        $visibleSameDay = [
            $this->config->getPaymentStatus(Session::STATUS_PAID),
        ];
        // If payment date is more than one day
        $now = new DateTime('NOW');
        $created = new DateTime($order->getCreatedAt());
        if (in_array($status, $visibleSameDay) && $now->format('Ymd') === $created->format('Ymd')) {
            // PAID and the same day
            return true;
        }

        $visibleAnytime = [
            $this->config->getPaymentStatus(Session::STATUS_HOLDED),
            $this->config->getPaymentStatus(Session::STATUS_CONFIRMED),
        ];
        if (in_array($status, $visibleAnytime)) {
            return true;
        }

        return false;
    }

    /**
     * Check if it is allowed to confirm hold in the current order.
     * 
     * @param Order $order Current order.
     * 
     * @return bool        TRUE if allowed to confirm hold, otherwise FALSE.
     */
    protected function canConfirmHold(Order $order)
    {
        // If payment status = Holded
        $invisible = [
            $this->config->getPaymentStatus(Session::STATUS_HOLDED)
        ];
        if (in_array($order->getStatus(), $invisible)) {
            return true;
        }

        return false;
    }
}
