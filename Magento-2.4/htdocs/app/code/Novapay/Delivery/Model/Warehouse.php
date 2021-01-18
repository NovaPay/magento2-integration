<?php
/**
 * Warehouse model.
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

use Novapay\Delivery\Api\Data\WarehouseInterface;

/**
 * Warehouse model.
 * 
 * @category Module
 * @package  Magento2
 * @author   NovaPay <acquiring@novapay.ua>
 * @license  https://github.com/NovaPay/prestashop-integration/blob/master/LICENSE MIT
 * @link     https://novapay.ua/
 */
class Warehouse implements WarehouseInterface
{
    protected $id;
    protected $no;
    protected $title;

    /**
     * Returns Warehouse Reference id.
     *
     * @return string
     */
    public function getId()
    {
        return $this->{static::KEY_ID};
    }

    /**
     * Sets Warehouse Reference id.
     *
     * @param string $id Warehouse Reference id.
     * 
     * @return $this
     */
    public function setId($id)
    {
        $this->{static::KEY_ID} = $id;
        return $this;
    }

    /**
     * Returns Warehouse title.
     *
     * @return string
     */
    public function getTitle()
    {
        return $this->{static::KEY_TITLE};
    }

    /**
     * Sets Warehouse title.
     *
     * @param string $title Warehouse title
     *
     * @return $this
     */
    public function setTitle($title)
    {
        $this->{static::KEY_TITLE} = $title;
    }

    /**
     * Returns Warehouse number.
     *
     * @return int
     */
    public function getNo()
    {
        return $this->{static::KEY_NUMBER};
    }

    /**
     * Sets Warehouse number.
     *
     * @param int $number Warehouse number
     *
     * @return $this
     */
    public function setNo($number)
    {
        $this->{static::KEY_NUMBER} = $number;
        return $this;
    }
}
