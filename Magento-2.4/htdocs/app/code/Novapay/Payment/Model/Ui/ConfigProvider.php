<?php
/**
 * ConfigProvider which manages config data from the Admin UI.
 * 
 * PHP version 7.X
 * 
 * @category Module
 * @package  Magento2
 * @author   NovaPay <acquiring@novapay.ua>
 * @license  https://github.com/NovaPay/prestashop-integration/blob/master/LICENSE MIT
 * @link     https://novapay.ua/
 */
namespace Novapay\Payment\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Novapay\Payment\Gateway\Http\Client\ClientMock;

/**
 * ConfigProvider which manages config data from the Admin UI.
 */
final class ConfigProvider implements ConfigProviderInterface
{
    const CODE = 'novapayment_gateway';

    /**
     * Retrieve assoc array of checkout configuration
     *
     * @return array
     */
    public function getConfig()
    {
        return [
            'payment' => [
                self::CODE => [
                    'transactionResults' => [
                        ClientMock::SUCCESS => __('Success payment'),
                        ClientMock::FAILURE => __('Fraud payment')
                    ]
                ]
            ]
        ];
    }
}
