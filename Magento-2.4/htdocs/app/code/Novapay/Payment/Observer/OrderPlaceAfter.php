<?php
/**
 * OrderPlaceAfter observer.
 * 
 * PHP version 7.X
 * 
 * @category Module
 * @package  Magento2
 * @author   NovaPay <acquiring@novapay.ua>
 * @license  https://github.com/NovaPay/prestashop-integration/blob/master/LICENSE MIT
 * @link     https://novapay.ua/
 */

namespace Custom\Module\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Payment\Model\Method\Logger;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\ResponseFactory;
use Magento\Framework\UrlInterface;

class OrderPlaceAfter implements ObserverInterface
{
    protected $logger;
    protected $customLogger;
    protected $responseFactory;
    protected $url;

    public function __construct(
        LoggerInterface $logger, 
        Logger $customLogger,
        ResponseFactory $responseFactory,
        UrlInterface $url
    ) {
        $this->logger          = $logger;
        $this->customLogger    = $customLogger;
        $this->responseFactory = $responseFactory;
        $this->url             = $url;
    }

    public function execute(Observer $observer)
    {
        $orders = $observer->getEvent()->getOrderIds();
        $orderId = array_shift($orders);

        $order = $this->orderRepository->get($orderId);
        
        $this->logger->debug("NOVAPAY: Order Id: $orderId");
        $this->customLogger->debug("NOVAPAY_C: Order Id: $orderId");

        $cartUrl = $this->_url->getUrl('checkout/cart/index');
        $response = $this->_responseFactory->create();
        $response->setRedirect($cartUrl)->sendResponse();
        exit;

        if (!$order->getEntityId()) {
            return;
        }

    }
}
