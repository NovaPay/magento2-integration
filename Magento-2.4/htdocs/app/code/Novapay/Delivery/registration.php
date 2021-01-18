<?php
/**
 *    ########    #######  ##     ##  ######   ########    ######  ##     ##   
 *    ##     ##  ##     ##  ##   ##   #######  ##     ##   #######  ##   ##    
 *    ##     ##  ##     ##   ## ##   ##    ##  ##     ##  ##    ##   ## ##     
 *    ##     ##   ######      ###     #######  #######     #######    ###      
 *                                             ##                     ##       
 *                                             ##                    ##        
 *
 * Payment action selectbox on order placement.
 * 
 * PHP version 7.0
 * 
 * @category Module
 * @package  Magento2
 * @author   NovaPay <acquiring@novapay.ua>
 * @license  https://github.com/NovaPay/prestashop-integration/blob/master/LICENSE MIT
 * @link     https://novapay.ua/
 */

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    'Novapay_Delivery',
    __DIR__
);
