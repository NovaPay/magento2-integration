<?php
/**
 * Novapay shipping carrier model.
 * 
 * PHP Version 7.X
 *
 * @category Module
 * @package  Magento2
 * @author   NovaPay <acquiring@novapay.ua>
 * @license  https://github.com/NovaPay/prestashop-integration/blob/master/LICENSE MIT
 * @link     https://novapay.ua/
 */
namespace Novapay\Delivery\Model\Carrier;

use Magento\Framework\Controller\ResultFactory;
use Magento\Quote\Model\Quote\Address\RateRequest;
// use Magento\Shipping\Model\Carrier\AbstractCarrierOnline;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Psr\Log\LoggerInterface;
use Magento\Shipping\Model\Rate\ResultFactory as RateResultFactory;
use Magento\Catalog\Model\Product;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\ObjectManager;

// use Novapay\Delivery\Model\Result\CityFactory;
use Novapay\Payment\SDK\Schema\Response\Error\Error;
use Novapay\Payment\SDK\Model\Delivery;
use Novapay\Delivery\Api\ShipmentEstimationInterface;
use Novapay\Delivery\Gateway\Config;

/**
 * Novapay shipping carrier model.
 * 
 * @category Module
 * @package  Magento2
 * @author   NovaPay <acquiring@novapay.ua>
 * @license  https://github.com/NovaPay/prestashop-integration/blob/master/LICENSE MIT
 * @link     https://novapay.ua/
 */
class Novapay extends AbstractCarrier implements CarrierInterface, ShipmentEstimationInterface
{
    /**
     * @var string
     */
    protected $_code = 'novapay';

    /**
     * @var bool
     */
    protected $_isFixed = true;

    /**
     * Error message.
     *
     * @var string
     */
    private $_error = '';

    /**
     * @var \Magento\Shipping\Model\Rate\ResultFactory
     */
    protected $rateResultFactory;

    /**
     * @var \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory
     */
    protected $rateMethodFactory;

    /**
     * @var \Magento\Framework\Controller\ResultFactory
     */
    protected $resultFactory;
    /**
     * @var \Magento\Checkout\Model\Session
     */
    protected $session;
    /**
     * @var \Novapay\Delivery\Gateway\Config $config
     */
    protected $config;
    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    protected $objectManager;
    /**
     * @var \Magento\Shipping\Model\Tracking\Result\StatusFactory
     */
    protected $trackStatusFactory;

    /**
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Magento\Shipping\Model\Rate\ResultFactory $rateResultFactory
     * @param \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory $rateMethodFactory
     * @param \Magento\Framework\Controller\ResultFactory $resultFactory
     * @param \Magento\Checkout\Model\Session $session
     * @param \Novapay\Delivery\Gateway\Config $config
     * @param \Magento\Framework\ObjectManagerInterface $objectManager
     * @param \Magento\Shipping\Model\Tracking\Result\StatusFactory $trackStatusFactory
     * @param array $data
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ErrorFactory $rateErrorFactory,
        LoggerInterface $logger,
        RateResultFactory $rateResultFactory,
        MethodFactory $rateMethodFactory,
        ResultFactory $resultFactory,
        Session $session,
        Config $config,
        \Magento\Framework\ObjectManagerInterface $objectManager,
        \Magento\Shipping\Model\Tracking\Result\StatusFactory $trackStatusFactory,
        array $data = []
    ) {
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);

        $this->rateResultFactory  = $rateResultFactory;
        $this->rateMethodFactory  = $rateMethodFactory;
        $this->resultFactory      = $resultFactory;
        $this->session            = $session;
        $this->config             = $config;
        $this->objectManager      = $objectManager;
        $this->trackStatusFactory = $trackStatusFactory;
    }

    /**
     * Custom Shipping Rates Collector
     *
     * @param RateRequest $request
     * @return \Magento\Shipping\Model\Rate\Result|bool
     */
    public function collectRates(RateRequest $request)
    {
        $quote = $this->session->getQuote();
        if (!$this->isShippingAvailable($quote)) {
            return false;
        }

        $title = $this->getConfigData('name');
        if ($quote && $quote->getWarehouseTitle()) {
            $title = sprintf(
                "%s > %s",
                $quote->getCityTitle(),
                preg_replace('/([\:\(].+)$/', '', $quote->getWarehouseTitle())
            );
        }

        /** @var \Magento\Shipping\Model\Rate\Result $result */
        $result = $this->rateResultFactory->create();

        /** @var \Magento\Quote\Model\Quote\Address\RateResult\Method $method */
        $method = $this->rateMethodFactory->create(
            [
                'city_title' => $quote ? $quote->getCityTitle() : '',
                'warehouse_title' => $quote ? $quote->getWarehouseTitle() : ''
            ]
        );

        $method->setCarrier($this->_code);
        $method->setCarrierTitle($this->getConfigData('title'));

        $method->setMethod($this->_code);
        $method->setMethodTitle($title);

        $shippingCost = (float)$this->getConfigData('shipping_cost');
        $shippingCost = $this->getShippingCost($quote);

        if ($this->hasError()) {
            $method->setError($this->getError());
        }

        $method->setPrice($shippingCost);
        $method->setCost($shippingCost);
        $result->append($method);

        return $result;
    }

    /**
     * Sets error message.
     *
     * @param string $message Error message.
     * 
     * @return void
     */
    protected function setError(string $message)
    {
        $this->_error = $message;
    }

    /**
     * Checks if error exists.
     *
     * @return bool
     */
    protected function hasError()
    {
        return !empty($this->_error);
    }

    /**
     * Returns error message.
     *
     * @return string
     */
    protected function getError()
    {
        return $this->_error;
    }

    /**
     * Returns tracking information for current shipping.
     * 
     * @param string $trackingNumber
     * 
     * @return \Magento\Sales\Model\Order\Shipment\Track
     */
    public function getTrackingInfo($trackingNumber)
    {
        $tracking = $this->trackStatusFactory->create();

        $tracking->setData(
            [
                'carrier' => $this->_code,
                'carrier_title' => $this->getConfigData('title'),
                'tracking' => $trackingNumber,
                'url' => sprintf(
                    'https://novaposhta.ua/tracking/?cargo_number=%s&newtracking=1',
                    $trackingNumber
                ),
            ]
        );
        return $tracking;
    }

    /**
     * Shipping is available only Ukraine (country) for products which have 
     * dimensions and weight defined, if there are at least one product in the 
     * shopping cart without such attributes Novapay shipping is not available.
     *
     * @param \Magento\Quote\Api\Data\CartInterface $quote
     *
     * @return bool TRUE if shipping is available
     *              FALSE otherwise.
     */
    protected function isShippingAvailable($quote)
    {
        if (!$this->getConfigFlag('active')) {
            return false;
        }
        $address = $quote->getShippingAddress();
        if (!$address) {
            return false;
        }
        if ('UA' !== $address->getCountryId()) {
            return false;
        }
        foreach ($quote->getAllItems() as $item) {
            $product = $this->objectManager->get(Product::class)->load(
                $item->getProduct()->getId()
            );
            $volume = $this->config->getProductVolume($product);
            if (!$volume) {
                return false;
            }
            $weight = $this->config->getProductWeight($product);
            if (!$weight) {
                return false;
            }
        }
        return true;
    }

    /**
     * Returns shipping cost based on total dimensions/volume, weight and recepient
     * city & warehouse.
     *
     * @param \Magento\Quote\Api\Data\CartInterface $quote
     *
     * @return float Shipping cost.
     */
    protected function getShippingCost($quote)
    {
        if (!$quote) {
            $this->setError(__('Undefined quote'));
            return 0;
        }
        $info = $this->config->getDeliveryForQuote($quote);
        if (!$info->delivery->recipient_city) {
            $this->setError(__('Undefined city'));
            return 0;
        }
        if (!$info->delivery->recipient_warehouse) {
            $this->setError(__('Undefined warehouse'));
            return 0;
        }

        $this->config->initModel();

        $delivery = new Delivery();
        $ok = $delivery->priceOfShipping($info->total, $info->delivery);
        if (!$ok) {
            $response = $delivery->getResponse();
            if ($response instanceof Error) {
                $this->setError($response->message);
            }
            return 0;
        }

        return $delivery->price;
    }

    /**
     * @return array
     */
    public function getAllowedMethods()
    {
        return [
            $this->_code => $this->getConfigData('name')
        ];
    }

    /**
     * Check if carrier has shipping tracking option available
     *
     * @return bool
     */
    public function isTrackingAvailable()
    {
        return true;
    }

    /**
     * Check if city option required
     *
     * @return bool
     */
    public function isCityRequired()
    {
        return true;
    }

}
