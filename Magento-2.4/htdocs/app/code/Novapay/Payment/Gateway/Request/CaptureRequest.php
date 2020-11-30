<?php
/**
 * CaptureRequest class.
 * Prepares data for the capture request.
 * 
 * PHP version 7.X
 * 
 * @category Module
 * @package  Magento2
 * @author   NovaPay <acquiring@novapay.ua>
 * @license  https://github.com/NovaPay/prestashop-integration/blob/master/LICENSE MIT
 * @link     https://novapay.ua/
 */
namespace Novapay\Payment\Gateway\Request;

use Magento\Payment\Gateway\ConfigInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;

use Novapay\Payment\Model\Adminhtml\Source\PaymentType;
use Novapay\Payment\SDK\Schema\Product;

use InvalidArgumentException;
use LogicException;

/**
 * CaptureRequest class.
 * Prepares data for the capture request.
 */
class CaptureRequest implements BuilderInterface
{
    /**
     * @var ConfigInterface
     */
    private $config;

    /**
     * @param ConfigInterface $config
     */
    public function __construct(
        ConfigInterface $config
    ) {
        $this->config = $config;
    }

    /**
     * Builds ENV request
     *
     * @param array $buildSubject
     * @return array
     */
    public function build(array $buildSubject)
    {
        if (!isset($buildSubject['payment'])
            || !$buildSubject['payment'] instanceof PaymentDataObjectInterface
        ) {
            throw new InvalidArgumentException('Payment data object should be provided');
        }

        /** @var PaymentDataObjectInterface $paymentDO */
        $paymentDO = $buildSubject['payment'];

        $order = $paymentDO->getOrder();

        $payment = $paymentDO->getPayment();

        if (!$payment instanceof OrderPaymentInterface) {
            throw new LogicException('Order payment should be provided.');
        }

        // $transactions = $this->transactions->create()->addOrderIdFilter($order->getId());
        // return $transactions->getItems();

        // @link Novapay\Payment\SDK\Schema\Request\PaymentPostRequest
        $id = $order->getStoreId();

        $items = [];
        foreach ($order->getAllItems() as $item) {
            $items[] = new Product(
                $item->getName(),
                $item->getPrice(),
                $item->getTotalQty()
            );
        }

        return [
            'merchant_id' => $this->config->getValue('merchant_id', $id),
            'session_id'  => $payment->getTxnId(),
            'external_id' => '',
            'amount'      => $order->getGrandTotalAmount(),
            'products'    => $items,
            'use_hold'    => PaymentType::HOLD == $this->config->getValue('payment_type', $id)
        ];
    }
}
