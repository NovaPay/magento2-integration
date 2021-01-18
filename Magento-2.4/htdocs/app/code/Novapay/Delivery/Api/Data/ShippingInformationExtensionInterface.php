<?php
/**
 * Shipping Information Extension interface.
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
 * Shipping Information Extension interface.
 *
 * @category Module
 * @package  Magento2
 * @author   NovaPay <acquiring@novapay.ua>
 * @license  https://github.com/NovaPay/prestashop-integration/blob/master/LICENSE MIT
 * @link     https://novapay.ua/
 * @api
 * @since    100.0.2
 */
interface ShippingInformationExtensionInterface
{
    /**#@+
     * Constants defined for keys of data array, makes typos less likely
     */
    const KEY_ID    = 'city_ref';
    const KEY_TITLE = 'warehouse_ref';
    /**#@-*/

    /**
     * Returns City Reference id.
     *
     * @return string
     */
    public function getCityRef();

    /**
     * Sets City Reference id.
     *
     * @param string $ref City Reference id.
     * 
     * @return $this
     */
    public function setCityRef($ref);


    /**
     * Returns Warehouse Reference id.
     *
     * @return string
     */
    public function getWarehouseRef();

    /**
     * Sets Warehouse Reference id.
     *
     * @param string $ref Warehouse Reference id.
     * 
     * @return $this
     */
    public function setWarehouseRef($ref);
}