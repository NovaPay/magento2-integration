<?php
/**
 * Warehouse types GET request schema class.
 * The data about types of warehouse for delivery section.
 * 
 * PHP version 7.X
 * 
 * @category SDK
 * @package  NovaPay
 * @author   NovaPay <acquiring@novapay.ua>
 * @license  https://github.com/NovaPay/prestashop-integration/blob/master/LICENSE MIT
 * @link     https://novapay.ua/
 * @link     https://devcenter.novaposhta.ua/docs/services/556d7ccaa0fe4f08e8f7ce43/operations/556d8211a0fe4f08e8f7ce45
 */

namespace Novapay\Payment\SDK\Schema\Request\Delivery;

use Novapay\Payment\SDK\Schema\Request\Request;

/**
 * Warehouse types GET request schema class.
 * The data about types of warehouse for delivery section.
 * 
 * @category SDK
 * @package  NovaPay
 * @author   NovaPay <acquiring@novapay.ua>
 * @license  https://github.com/NovaPay/prestashop-integration/blob/master/LICENSE MIT
 * @link     https://novapay.ua/
 * @link     https://devcenter.novaposhta.ua/docs/services/556d7ccaa0fe4f08e8f7ce43/operations/556d8211a0fe4f08e8f7ce45
 */
class WarehouseTypesGetRequest extends Request
{
    /**
     * The merchant ID.
     * 
     * @var string $merchant_id Merchant ID.
     */
    public $merchant_id;
    public $modelName = 'AddressGeneral';
    public $calledMethod = 'getWarehouseTypes';
    public $methodProperties = [];

    /**
     * WarehouseTypesGetRequest constructor.
     * 
     * @param string $merchant_id Merchant ID.
     * @param string $cityRef     City Reference.
     */
    public function __construct($merchant_id, $cityRef) 
    {
        $this->merchant_id = $merchant_id;
        $this->methodProperties['CityRef'] = $cityRef;
    }
}