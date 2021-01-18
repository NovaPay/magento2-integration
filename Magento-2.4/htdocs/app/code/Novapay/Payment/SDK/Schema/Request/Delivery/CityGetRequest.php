<?php
/**
 * City GET request schema class.
 * The data about city for delivery section.
 * 
 * PHP version 7.X
 * 
 * @category SDK
 * @package  NovaPay
 * @author   NovaPay <acquiring@novapay.ua>
 * @license  https://github.com/NovaPay/prestashop-integration/blob/master/LICENSE MIT
 * @link     https://novapay.ua/
 * @link     https://devcenter.novaposhta.ua/docs/services/556d7ccaa0fe4f08e8f7ce43/operations/556d885da0fe4f08e8f7ce46
 */

namespace Novapay\Payment\SDK\Schema\Request\Delivery;

use Novapay\Payment\SDK\Schema\Request\Request;

/**
 * City GET request schema class.
 * The data about city for delivery section.
 * 
 * @category SDK
 * @package  NovaPay
 * @author   NovaPay <acquiring@novapay.ua>
 * @license  https://github.com/NovaPay/prestashop-integration/blob/master/LICENSE MIT
 * @link     https://novapay.ua/
 * @link     https://devcenter.novaposhta.ua/docs/services/556d7ccaa0fe4f08e8f7ce43/operations/556d885da0fe4f08e8f7ce46
 */
class CityGetRequest extends Request
{
    /**
     * The merchant ID.
     * 
     * @var string $merchant_id Merchant ID.
     */
    public $merchant_id;
    public $modelName = 'Address';
    public $calledMethod = 'getCities';
    public $methodProperties = [];

    /**
     * CityGetRequest constructor.
     * 
     * @param string $merchant_id Merchant ID.
     * @param string $cityRef     City reference ID.
     */
    public function __construct($merchant_id, $cityRef) 
    {
        $this->merchant_id = $merchant_id;
        $this->methodProperties['Ref'] = $cityRef;
    }
}