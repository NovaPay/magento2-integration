<?php
/**
 * AuthorizationRequest class.
 * Prepares data for the authorization request.
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

/**
 * AuthorizationRequest class.
 * Prepares data for the authorization request.
 */
class AuthorizationRequest implements BuilderInterface
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
            throw new \InvalidArgumentException('Payment data object should be provided');
        }

        /** @var PaymentDataObjectInterface $payment */
        $payment = $buildSubject['payment'];
        $order   = $payment->getOrder();
        // $address = $order->getShippingAddress();
        $address = $order->getBillingAddress();

        // @link Novapay\Payment\SDK\Schema\Request\SessionPostRequest
        $id = $order->getStoreId();

        return [
            'merchant_id'       => $this->config->getValue('merchant_id', $id),
            'client_first_name' => $address->getFirstname(),
            'client_last_name'  => $address->getLastname(),
            'client_patronymic' => $address->getMiddlename(),
            'client_phone'      => $address->getTelephone(),
            'callback_url'      => $this->getPostbackUrl(),
            'success_url'       => $this->config->getValue('success_url', $id),
            'fail_url'          => $this->config->getValue('fail_url', $id),
            'metadata'          => [
                'order_id'      => $order->getOrderIncrementId(),
                'order_uid'     => $order->getEntityId(),
                'grand_total'   => $order->getGrandTotalAmount(),
                'email'         => $address->getEmail()
            ]
        ];
    }

    private function getPostbackUrl()
    {
        // @todo use another way
        return (isset($_SERVER['HTTPS']) ? 'https://' : 'http://')
                . $_SERVER['HTTP_HOST'] . '/novapay/payment/postback';
    }
}
