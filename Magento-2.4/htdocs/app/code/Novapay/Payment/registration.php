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
 * PHP version 7.X
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
    'Novapay_Payment',
    __DIR__
);
