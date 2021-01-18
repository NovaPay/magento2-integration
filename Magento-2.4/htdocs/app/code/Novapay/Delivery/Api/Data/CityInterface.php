<?php
/**
 * City model interface.
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
 * City model interface.
 *
 * @category Module
 * @package  Magento2
 * @author   NovaPay <acquiring@novapay.ua>
 * @license  https://github.com/NovaPay/prestashop-integration/blob/master/LICENSE MIT
 * @link     https://novapay.ua/
 * @api
 * @since    100.0.2
 */
interface CityInterface
{
    /**#@+
     * Constants defined for keys of data array, makes typos less likely
     */
    const KEY_ID    = 'Ref';
    const KEY_TITLE = 'Title';
    /**#@-*/

    /**
     * Returns City Reference id.
     *
     * @return string
     */
    public function getRef();

    /**
     * Sets City Reference id.
     *
     * @param string $ref City Reference id.
     * 
     * @return $this
     */
    public function setRef($ref);

    /**
     * Returns City description.
     *
     * @return string
     */
    public function getTitle();

    /**
     * Sets City title.
     *
     * @param string $title City title
     *
     * @return $this
     */
    public function setTitle($title);
}