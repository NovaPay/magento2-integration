<?php
/**
 * Calculation model.
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

use Novapay\Delivery\Api\Data\CalculationInterface;

/**
 * Calculation model.
 * 
 * @category Module
 * @package  Magento2
 * @author   NovaPay <acquiring@novapay.ua>
 * @license  https://github.com/NovaPay/prestashop-integration/blob/master/LICENSE MIT
 * @link     https://novapay.ua/
 */
class Calculation implements CalculationInterface
{
    protected $cityRef;
    protected $warehouseRef;
    protected $volume;
    protected $weight;
    protected $total;
    protected $cost;


    /**
     * Returns city reference id.
     *
     * @return string 
     */
    public function getCityRef()
    {
        return $this->{static::KEY_CITY_REF};
    }

    /**
     * Sets city reference id.
     *
     * @param string $cityRef city reference id.
     * 
     * @return $this
     */
    public function setCityRef($cityRef)
    {
        $this->{static::KEY_CITY_REF} = $cityRef;
        return $this;
    }

    /**
     * Returns warehouse reference id.
     *
     * @return string 
     */
    public function getWarehouseRef()
    {
        return $this->{static::KEY_WAREHOUSE_REF};
    }

    /**
     * Sets warehouse reference id.
     *
     * @param string $warehouseRef warehouse reference id.
     * 
     * @return $this
     */
    public function setWarehouseRef($warehouseRef)
    {
        $this->{static::KEY_WAREHOUSE_REF} = $warehouseRef;
        return $this;
    }

    /**
     * Returns total shopping cart volume.
     *
     * @return float 
     */
    public function getVolume()
    {
        return $this->{static::KEY_VOLUME};
    }

    /**
     * Sets total shopping cart volume.
     *
     * @param float $volume total shopping cart volume.
     * 
     * @return $this
     */
    public function setVolume($volume)
    {
        $this->{static::KEY_VOLUME} = $volume;
        return $this;
    }

    /**
     * Returns total shopping cart weight.
     *
     * @return float 
     */
    public function getWeight()
    {
        return $this->{static::KEY_WEIGHT};
    }

    /**
     * Sets total shopping cart weight.
     *
     * @param float $weight total shopping cart weight.
     * 
     * @return $this
     */
    public function setWeight($weight)
    {
        $this->{static::KEY_WEIGHT} = $weight;
        return $this;
    }

    /**
     * Returns total shopping cart amount.
     *
     * @return float 
     */
    public function getTotal()
    {
        return $this->{static::KEY_TOTAL};
    }

    /**
     * Sets total shopping cart amount.
     *
     * @param float $total total shopping cart amount.
     * 
     * @return $this
     */
    public function setTotal($total)
    {
        $this->{static::KEY_TOTAL} = $total;
        return $this;
    }

    /**
     * Returns shipping cost.
     *
     * @return float 
     */
    public function getCost()
    {
        return $this->{static::KEY_COST};
    }

    /**
     * Sets shipping cost.
     *
     * @param float $cost shipping cost.
     * 
     * @return $this
     */
    public function setCost($cost)
    {
        $this->{static::KEY_COST} = $cost;
        return $this;
    }
}
