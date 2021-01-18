<?php
/**
 * City model.
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

use Novapay\Delivery\Api\Data\CityInterface;

/**
 * City model.
 * 
 * @category Module
 * @package  Magento2
 * @author   NovaPay <acquiring@novapay.ua>
 * @license  https://github.com/NovaPay/prestashop-integration/blob/master/LICENSE MIT
 * @link     https://novapay.ua/
 */
class City implements CityInterface
{
    protected $Ref;
    protected $Title;

    /**
     * {@inheritDoc}
     */
    public function getRef()
    {
        return $this->{static::KEY_ID};
    }

    /**
     * {@inheritDoc}
     */
    public function getTitle()
    {
        return $this->{static::KEY_TITLE};
    }

    /**
     * {@inheritDoc}
     */
    public function setRef($ref)
    {
        $this->{static::KEY_ID} = $ref;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function setTitle($title)
    {
        $this->{static::KEY_TITLE} = $title;
        return $this;
    }
}
