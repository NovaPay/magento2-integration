<?php
/**
 * Gateway Config class.
 * Used for getting configuration saved in Admin UI.
 * 
 * PHP version 7.X
 * 
 * @category Module
 * @package  Magento2
 * @author   NovaPay <acquiring@novapay.ua>
 * @license  https://github.com/NovaPay/prestashop-integration/blob/master/LICENSE MIT
 * @link     https://novapay.ua/
 */

namespace Novapay\Delivery\Gateway;

use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Catalog\Model\Product;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Model\ScopeInterface;

use Novapay\Payment\Gateway\Config as PaymentConfig;
use Novapay\Payment\SDK\Schema\Delivery;

/**
 * Gateway Config class.
 * 
 * @category Module
 * @package  Magento2
 * @author   NovaPay <acquiring@novapay.ua>
 * @license  https://github.com/NovaPay/prestashop-integration/blob/master/LICENSE MIT
 * @link     https://novapay.ua/
 */
class Config extends PaymentConfig
{
    /**
     * AbsctractCheckoutAction constructor.
     * 
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig Scope config.
     * @param \Magento\Framework\ObjectManagerInterface          $objectManager
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ObjectManagerInterface $objectManager
    ) {
        parent::__construct($scopeConfig);

        $this->objectManager = $objectManager;
    }

    /**
     * Retrieves module config value by it's name.
     *
     * @param string $name Config name.
     * 
     * @return mixed
     */
    public function get($name)
    {
        return $this->scopeConfig->getValue(
            "carriers/novapay/$name",
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Returns config value from global/locale namespace.
     * Used for weight_unit in the first place.
     *
     * @param string $name Config name
     * 
     * @return mixed
     */
    public function getGlobal($name)
    {
        return $this->scopeConfig->getValue(
            "general/locale/$name",
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Returns product package volume for shipping calculation.
     * 
     * @param Product $product Product model.
     * 
     * @return float           Volume of the product package.
     */
    public function getProductVolume(Product $product)
    {
        $m = $this->getMultiplier($this->get('length_unit'));
        $w = $product->getData($this->get('product_width')) * $m;
        $h = $product->getData($this->get('product_height')) * $m;
        $d = $product->getData($this->get('product_depth')) * $m;
        return $w * $h * $d;
    }
    
    /**
     * Returns product weight in the required by API units.
     * 
     * @param Product $product Product model.
     * 
     * @return float           Weight.
     */
    public function getProductWeight(Product $product)
    {
        if (null === $this->get('weight_unit')) {
            // use system weight
            $weight = $product->getWeight();
            $multiplier = $this->getMultiplier($this->getGlobal('weight_unit'));
            return $weight * $multiplier;
        }
        // use custom attribute value
        $weight = $product->getData($this->get('product_weight'));
        $multiplier = $this->getMultiplier($this->get('weight_unit'));
        return $weight * $multiplier;
    }

    /**
     * Returns multiplier for the unit to calculate proper values for API.
     *
     * @param string $unit Unit value.
     *
     * @return float       Multiplier.
     */
    protected function getMultiplier($unit)
    {
        $all = [
            'g'   => 0.001,
            'kg'  => 1,
            'kgs' => 1, // system unit
            'lbs' => 0.453592,

            'mm'  => 0.001,
            'cm'  => 0.01,
            'm'   => 1,
        ];

        return $all[$unit] ?? 1;
    }

    /**
     * Returns ShippingDelivery object for provided order.
     *
     * @param OrderInterface $order Order object.
     * 
     * @return ShippingDelivery|null
     */
    public function getDeliveryForOrder(OrderInterface $order)
    {
        $city = $order->getCityRef();
        $warehouse = $order->getWarehouseRef();
        if (!$city || !$warehouse) {
            return null;
        }

        return $this->getShippingDeliveryForItems(
            $order->getItems(),
            $city,
            $warehouse
        );
    }

    /**
     * Returns ShippingDelivery object for provided shopping cart.
     *
     * @param CartInterface $cart         Shopping cart object.
     * @param string        $cityRef      City reference id.
     * @param string        $warehouseRef Warehouse reference id.
     *
     * @return ShippingDelivery
     */
    public function getDeliveryForCart($cart, $cityRef, $warehouseRef)
    {
        return $this->getShippingDeliveryForItems(
            $cart->getItems(),
            $cityRef,
            $warehouseRef
        );
    }

    /**
     * Returns ShippingDelivery object for provided quote.
     *
     * @param QuoteInterface $quote        Quoute object.
     *
     * @return ShippingDelivery
     */
    public function getDeliveryForQuote($quote)
    {
        return $this->getShippingDeliveryForItems(
            $quote->getAllItems(),
            $quote->getCityRef(),
            $quote->getWarehouseRef()
        );
    }

    /**
     * Returns ShippingDelivery object for array of items with calculated:
     * {weight, volume, total} and used references {cityRef, warehouseRef}
     *
     * @param array  $items        Array of order/quote/cart items.
     * @param string $cityRef      City reference id.
     * @param string $warehouseRef Warehouse reference id.
     *
     * @return ShippingDelivery
     */
    protected function getShippingDeliveryForItems($items, $cityRef, $warehouseRef)
    {
        $volume = 0;
        $weight = 0;
        $total  = 0;

        foreach ($items as $item) {
            $product = $this->objectManager->get(Product::class)->load(
                $item->getProduct()->getId()
            );
            $qty = method_exists($item, 'getQtyOrdered') 
                   ? $item->getQtyOrdered() 
                   : $item->getQty();
            $volume += $qty * $this->getProductVolume($product);
            $weight += $qty * $this->getProductWeight($product);
            $total  += $item->getRowTotalInclTax();
        }

        return new ShippingDelivery(
            new Delivery($weight, $volume, $cityRef, $warehouseRef),
            $total
        );
    }
}
