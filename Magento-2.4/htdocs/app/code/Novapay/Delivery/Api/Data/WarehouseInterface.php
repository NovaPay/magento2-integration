<?php
/**
 * Warehouse model interface.
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
 * Warehouse model interface.
 *
 * @category Module
 * @package  Magento2
 * @author   NovaPay <acquiring@novapay.ua>
 * @license  https://github.com/NovaPay/prestashop-integration/blob/master/LICENSE MIT
 * @link     https://novapay.ua/
 * @api
 * @since    100.0.2
 */
interface WarehouseInterface
{
    /**#@+
     * Constants defined for keys of data array, makes typos less likely
     */
    const KEY_ID     = 'id';
    const KEY_TITLE  = 'title';
    const KEY_NUMBER = 'no';
    /**#@-*/

    /**
     * Returns Warehouse Reference id.
     *
     * @return string
     */
    public function getId();

    /**
     * Sets Warehouse Reference id.
     *
     * @param string $id Warehouse Reference id.
     * 
     * @return $this
     */
    public function setId($id);

    /**
     * Returns Warehouse title.
     *
     * @return string
     */
    public function getTitle();

    /**
     * Sets Warehouse title.
     *
     * @param string $title Warehouse title
     *
     * @return $this
     */
    public function setTitle($title);

    /**
     * Returns Warehouse number.
     *
     * @return int
     */
    public function getNo();

    /**
     * Sets Warehouse number.
     *
     * @param int $No Warehouse number
     *
     * @return $this
     */
    public function setNo($number);
}