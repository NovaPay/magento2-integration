<?php
/**
 * CitiesManager model.
 * 
 * PHP Version 7.X
 *
 * @category Module
 * @package  Magento2
 * @author   NovaPay <acquiring@novapay.ua>
 * @license  https://github.com/NovaPay/prestashop-integration/blob/master/LICENSE MIT
 * @link     https://novapay.ua/
 */
declare(strict_types=1);

namespace Novapay\Delivery\Model;

use Novapay\Delivery\Api\CitiesManagerInterface;
use Novapay\Payment\SDK\Model\Delivery\Cities;
use Novapay\Payment\SDK\Model\Delivery\Warehouses;
use Novapay\Payment\SDK\Model\Delivery;
use Novapay\Payment\SDK\Schema\Response\Error\Error;
use Psr\Log\LoggerInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\ObjectManager;
// use Novapay\Payment\Gateway\Config;
use Novapay\Delivery\Gateway\Config;

/**
 * CitiesManager model.
 * 
 * @category Module
 * @package  Magento2
 * @author   NovaPay <acquiring@novapay.ua>
 * @license  https://github.com/NovaPay/prestashop-integration/blob/master/LICENSE MIT
 * @link     https://novapay.ua/
 */
class CitiesManager implements CitiesManagerInterface
{
    const CITY_SEARCH_MIN_LENGTH = 2;
    /**
    * @var CityInterfaceFactory
     */
    protected $cityFactory;

    /**
     * @var WarehouseInterfaceFactory
     */
    protected $warehouseFactory;

    /**
     * @var CalculationInterfaceFactory
     */
    protected $calculationFactory;

    /**
     * @var QuoteIdMaskFactory
     */
    protected $quoteIdMaskFactory;

    /**
     * @var CartRepositoryInterface
     */
    protected $cartRepository;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var \Novapay\Payment\Gateway\Config
     */
    protected $config;

    /**
     * @var ObjectManager
     */
    protected $objectManager;

    /**
     * @var \Magento\Framework\Message\ManagerInterface
     */
    protected $messageManager;

    /**
     * @param \Novapay\Delivery\Api\Data\CityInterfaceFactory $cityFactory
     * @param \Novapay\Delivery\Api\Data\WarehouseInterfaceFactory $warehouseFactory
     * @param \Novapay\Delivery\Api\Data\CalculationInterfaceFactory $calculationFactory
     * @param \Magento\Quote\Model\QuoteIdMaskFactory $quoteIdMaskFactory
     * @param \Magento\Quote\Api\CartRepositoryInterface $cartRepository
     * @param \Magento\Checkout\Model\Session $session
     * @param \Psr\Log\LoggerInterface $logger
     * @param Config $config
     * @param \Magento\Framework\ObjectManagerInterface $objectManager
     */
    public function __construct(
        \Novapay\Delivery\Api\Data\CityInterfaceFactory $cityFactory,
        \Novapay\Delivery\Api\Data\WarehouseInterfaceFactory $warehouseFactory,
        \Novapay\Delivery\Api\Data\CalculationInterfaceFactory $calculationFactory,
        \Magento\Quote\Model\QuoteIdMaskFactory $quoteIdMaskFactory,
        \Magento\Quote\Api\CartRepositoryInterface $cartRepository,
        Session $session,
        LoggerInterface $logger,
        Config $config,
        \Magento\Framework\ObjectManagerInterface $objectManager,
        \Magento\Framework\Message\ManagerInterface $messageManager
    ) {
        $this->cityFactory        = $cityFactory;
        $this->warehouseFactory   = $warehouseFactory;
        $this->calculationFactory = $calculationFactory;
        $this->quoteIdMaskFactory = $quoteIdMaskFactory;
        $this->cartRepository     = $cartRepository;
        $this->session            = $session;
        $this->logger             = $logger;
        $this->config             = $config;
        $this->objectManager      = $objectManager;
        $this->messageManager     = $messageManager;
    }

    /**
     * @inheritdoc
     */
    public function search($q)
    {
        $q = trim($q);
        $this->config->initModel();

        $cities = new Cities(static::CITY_SEARCH_MIN_LENGTH);
        if (!$cities->search($q)) {
            $this->logger->debug('Cannot find city by %1', [$q]);
            $res = $cities->getResponse();
            if ($res instanceof Error) {
                $this->logger->debug($res->message);
            }
            return [];
        }

        $result = [];
        foreach ($cities->items as $item) {
            $city = $this->cityFactory->create(
                [
                    'Ref' => $item->Ref,
                    'Title' => $item->Description
                ]
            );
            $city->setRef($item->Ref);
            $city->setTitle($item->Description);
            $result[] = $city;
        }
        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function getWarehouses($cityRef)
    {
        $this->config->initModel();

        $houses = new Warehouses();
        if (!$houses->all($cityRef)) {
            $this->logger->debug('Cannot get warehouses for city %1', [$q]);
            $res = $houses->getResponse();
            if ($res instanceof Error) {
                $this->logger->debug($res->message);
            }
            return [];
        }
        $result = [];
        foreach ($houses->items as $item) {
            $house = $this->warehouseFactory->create();
            $house->setId($item->ref)
                ->setNo($item->no)
                ->setTitle($item->title);
            $result[] = $house;
        }
        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function calculate($cartId, $cityRef, $warehouseRef, $cityTitle, $warehouseTitle)
    {
        $this->config->initModel();

        $cityTitle = $this->decodeTitle($cityTitle);
        $warehouseTitle = $this->decodeTitle($warehouseTitle);

        $quoteIdMask = $this->quoteIdMaskFactory->create()->load($cartId, 'masked_id');
        $cart = $this->cartRepository->get($quoteIdMask->getQuoteId());

        $ship = $this->config->getDeliveryForCart($cart, $cityRef, $warehouseRef);

        // $volume = 0;
        // $weight = 0;
        // $total  = 0;

        // foreach ($cart->getItems() as $item) {
        //     $product = $this->objectManager->get(Product::class)->load(
        //         $item->getProduct()->getId()
        //     );
        //     $volume += $item->getQty() * $this->config->getProductVolume($product);
        //     $weight += $item->getQty() * $this->config->getProductWeight($product);
        //     $total  += $item->getRowTotalInclTax();
        // }

        $calc = $this->calculationFactory->create(['cityRef' => $cartId]);

        $delivery = new Delivery();
        $ok = $delivery->price(
            $ship->total,
            $ship->delivery->volume_weight,
            $ship->delivery->weight,
            $ship->delivery->recipient_city,
            $ship->delivery->recipient_warehouse
        );
        if ($ok) {
            $calc->setCost($delivery->price);
        } else {
            $calc->setCost(0);
            $this->messageManager->addErrorMessage(
                __('Cannot calculate shipping cost')
            );
        }

        $quote = $this->session->getQuote();
        $quote->setCityRef($cityRef);
        $quote->setCityTitle($cityTitle);
        $quote->setWarehouseRef($warehouseRef);
        $quote->setWarehouseTitle($warehouseTitle);
        $quote->save();

        $calc->setCityRef($cityRef);
        $calc->setWarehouseRef($warehouseRef);
        $calc->setVolume($ship->delivery->volume_weight);
        $calc->setWeight($ship->delivery->weight);
        $calc->setTotal($ship->total);
        return $calc;
    }

    /**
     * Decode title encoded from frontend.
     *
     * @param string $title Input title.
     *
     * @return string       Decode title.
     */
    protected function decodeTitle($title)
    {
        $title = str_replace('-', '/', $title);
        $title = str_replace(' ', '+', $title);
        return base64_decode($title);
    }
}
