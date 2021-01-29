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
    protected $urlBuilder;

    private $_orderFactory;
    private $_order;
    private $_view;

    public function __construct(
        OrderFactory $orderFactory, 
        ContextInterface $context,
        Config $config,
        \Magento\Framework\UrlInterface $urlBuilder
    ) {
        $this->_orderFactory = $orderFactory;
        $this->context       = $context;
        $this->config        = $config;
        $this->urlBuilder    = $urlBuilder;
    }

    public function beforeSetLayout(View $subject)
    {
        $this->loadOrder($subject->getOrderId());
        $this->_view = $subject;

        $this->addUpdateButton();
        $this->addCancelButton();
        if ($this->config->isSecureDeliveryInOrder($this->_order)) {
            // secure delivery, try to add confirm delivery hold button
            $this->addConfirmDeliveryButton();
        } else {
            // try to add confirm payment hold button
            $this->addConfirmPaymentButton();
        }
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
        $query = [
            'method' => 'update',
            'id' => $order->getEntityId()
        ];
        $url = $this->urlBuilder->getUrl('novapay', $query);
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
        $query = [
            'method' => 'cancel',
            'id' => $order->getEntityId()
        ];
        $url = $this->urlBuilder->getUrl('novapay', $query);

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
     * Adds a confirm payment hold button to the order view.
     * 
     * @todo No need to add for orders which are not holded.
     * 
     * @return void
     */
    protected function addConfirmPaymentButton()
    {
        // novapayConfirmPaymentHold
        $order = $this->getOrder();
        $view  = $this->getView();
        if (!$order || !$view) {
            return;
        }

        if (!$this->canConfirmPaymentHold($order)) {
            return;
        }

        $query = [
            'method' => 'confirm',
            'id' => $order->getEntityId(),
            'amount' => '-amount-'
        ];
        $url = $this->urlBuilder->getUrl('novapay', $query);

        $amount = sprintf('%.2f', $order->getGrandTotal());

        $view->addButton(
            'confirmpaid',
            [
                'label' => __('Confirm payment'),
                'onclick' => "novapayConfirmPaymentHold('$amount', '$url')",
            ]
        );
    }

    /**
     * Adds a confirm delivery hold button to the order view.
     * 
     * @todo No need to add for orders which are not holded.
     * 
     * @return void
     */
    protected function addConfirmDeliveryButton()
    {
        // novapayConfirmDeliveryHold
        $order = $this->getOrder();
        $view  = $this->getView();
        if (!$order || !$view) {
            return;
        }

        if (!$this->canConfirmDeliveryHold($order)) {
            return;
        }

        $query = [
            'method' => 'confirm_delivery',
            'id' => $order->getEntityId(),
        ];
        $url = $this->urlBuilder->getUrl('novapay', $query);

        $view->addButton(
            'confirmshipping',
            [
                // @todo visible only orders possible to confirm delivery
                'label' => __('Confirm delivery'),
                'onclick' => "novapayConfirmDeliveryHold('$url')",
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
     * Check if it is allowed to confirm payment hold in the current order.
     * 
     * @param Order $order Current order.
     * 
     * @return bool        TRUE if allowed to confirm hold, otherwise FALSE.
     */
    protected function canConfirmPaymentHold(Order $order)
    {
        // If payment status = Holded
        $visible = [
            $this->config->getPaymentStatus(Session::STATUS_HOLDED)
        ];
        if (in_array($order->getStatus(), $visible)) {
            return true;
        }

        return false;
    }

    /**
     * Check if it is allowed to confirm delivery hold in the current order.
     * 
     * @param Order $order Current order.
     * 
     * @return bool        TRUE if allowed to confirm hold, otherwise FALSE.
     */
    protected function canConfirmDeliveryHold(Order $order)
    {
        // If payment status = Holded
        $visible = [
            $this->config->getPaymentStatus(Session::STATUS_HOLDED)
        ];
        if (!in_array($order->getStatus(), $visible)) {
            return false;
        }

        $shipments = $order->getShipmentsCollection();
        if (!$shipments) {
            return true;
        }
        foreach ($shipments->getItems() as $shipment) {
            $tracks = $shipment->getAllTracks();
            foreach ($tracks as $track) {
                if ('novapay' == $track->getCarrierCode()) {
                    // already created
                    return false;
                }
            }
        }
        return true;
    }
}
