<?php
/**
 * CalculationInterface interface.
 *
 * PHP version 7.0
 *
 * @category Module
 * @package  Magento2
 * @author   NovaPay <acquiring@novapay.ua>
 * @license  https://github.com/NovaPay/prestashop-integration/blob/master/LICENSE MIT
 * @link     https://novapay.ua/
 */

namespace Novapay\Delivery\Api\Data;

/**
 * CalculationInterface interface.
 *
 * @category Module
 * @package  Magento2
 * @author   NovaPay <acquiring@novapay.ua>
 * @license  https://github.com/NovaPay/prestashop-integration/blob/master/LICENSE MIT
 * @link     https://novapay.ua/
 * @api
 * @since    100.0.2
 */
interface CalculationInterface
{
    /**#@+
     * Constants defined for keys of data array, makes typos less likely
     */
    const KEY_CITY_REF      = 'cityRef';
    const KEY_WAREHOUSE_REF = 'warehouseRef';
    const KEY_VOLUME        = 'volume';
    const KEY_WEIGHT        = 'weight';
    const KEY_TOTAL         = 'total';
    const KEY_COST          = 'cost';
    /**#@-*/

    /**
     * Returns city reference id.
     *
     * @return string 
     */
    public function getCityRef();

    /**
     * Sets city reference id.
     *
     * @param string $cityRef city reference id.
     * 
     * @return $this
     */
    public function setCityRef($cityRef);

    /**
     * Returns warehouse reference id.
     *
     * @return string 
     */
    public function getWarehouseRef();

    /**
     * Sets warehouse reference id.
     *
     * @param string $warehouseRef warehouse reference id.
     * 
     * @return $this
     */
    public function setWarehouseRef($warehouseRef);

    /**
     * Returns total shopping cart volume.
     *
     * @return float 
     */
    public function getVolume();

    /**
     * Sets total shopping cart volume.
     *
     * @param float $volume total shopping cart volume.
     * 
     * @return $this
     */
    public function setVolume($volume);

    /**
     * Returns total shopping cart weight.
     *
     * @return float 
     */
    public function getWeight();

    /**
     * Sets total shopping cart weight.
     *
     * @param float $weight total shopping cart weight.
     * 
     * @return $this
     */
    public function setWeight($weight);

    /**
     * Returns total shopping cart amount.
     *
     * @return float 
     */
    public function getTotal();

    /**
     * Sets total shopping cart amount.
     *
     * @param float $total total shopping cart amount.
     * 
     * @return $this
     */
    public function setTotal($total);

    /**
     * Returns shipping cost.
     *
     * @return float 
     */
    public function getCost();

    /**
     * Sets shipping cost.
     *
     * @param float $cost shipping cost.
     * 
     * @return $this
     */
    public function setCost($cost);
}
