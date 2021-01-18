<?php
/**
 * Interface CitiesManagerInterface
 * 
 * PHP version 7.X
 * 
 * @category Module
 * @package  Magento2
 * @author   NovaPay <acquiring@novapay.ua>
 * @license  https://github.com/NovaPay/prestashop-integration/blob/master/LICENSE MIT
 * @link     https://novapay.ua/
 */
namespace Novapay\Delivery\Api;

/**
 * Interface CitiesManagerInterface
 * 
 * @category Module
 * @package  Magento2
 * @author   NovaPay <acquiring@novapay.ua>
 * @license  https://github.com/NovaPay/prestashop-integration/blob/master/LICENSE MIT
 * @link     https://novapay.ua/
 * @api
 * @since    102.0.0
 */
interface CitiesManagerInterface
{
    /**
     * Searchs cities by provided first letters in $q argument.
     *
     * @param string $q First letters of the city to search for.
     *
     * @since  102.0.0
     * @return \Novapay\Delivery\Api\Data\CityInterface[]
     */
    public function search($q);

    /**
     * Returns warehouses for the provided city reference id.
     *
     * @param string $cityRef City reference id.
     * 
     * @since  102.0.0
     * @return \Novapay\Delivery\Api\Data\WarehouseInterface[]
     */
    public function getWarehouses($cityRef);

    /**
     * Returns calculation for the current Quote and provided city and warehouse.
     * 
     * @param string $cartId         Cart/quote id.
     * @param string $cityRef        City reference id.
     * @param string $warehouseRef   Warehouse reference id.
     * @param string $cityTitle      City title.
     * @param string $warehouseTitle Warehouse title.
     * 
     * @since  102.0.0
     * @return \Novapay\Delivery\Api\Data\CalculationInterface
     */
    public function calculate($cartId, $cityRef, $warehouseRef, $cityTitle, $warehouseTitle);
}
