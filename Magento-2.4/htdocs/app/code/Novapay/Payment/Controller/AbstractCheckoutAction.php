<?php
/**
 * AbstractCheckoutAction controller class.
 * 
 * PHP version 7.X
 * 
 * @category Module
 * @package  Magento2
 * @author   NovaPay <acquiring@novapay.ua>
 * @license  https://github.com/NovaPay/prestashop-integration/blob/master/LICENSE MIT
 * @link     https://novapay.ua/
 */

namespace Novapay\Payment\Controller;

use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Checkout\Model\Session;
use Magento\Sales\Api\Data\TransactionInterface;
use Magento\Sales\Api\Data\TransactionSearchResultInterfaceFactory;
use Magento\Sales\Model\OrderFactory;
use Magento\Quote\Model\QuoteFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface;
use Magento\Sales\Model\Service\InvoiceService;
use Psr\Log\LoggerInterface;
use Novapay\Payment\SDK\Model\Model;
use Novapay\Payment\SDK\Model\Session as SessionSDK;

/**
 * AbstractCheckoutAction controller class.
 * 
 * @category Module
 * @package  Magento2
 * @author   NovaPay <acquiring@novapay.ua>
 * @license  https://github.com/NovaPay/prestashop-integration/blob/master/LICENSE MIT
 * @link     https://novapay.ua/
 */
abstract class AbstractCheckoutAction extends AbstractAction
{
    /**
     * @var \Magento\Checkout\Model\Session
     */
    protected $checkoutSession;

    /**
     * @var \Magento\Sales\Model\OrderFactory
     */
    protected $orderFactory;

    protected $quoteFactory;

    protected $scopeConfig;

    protected $transactionBuilder;

    protected $transactionSearch;
    
    protected $invoiceService;

    /**
     * AbsctractCheckoutAction constructor.
     * 
     * @param Magento\Framework\App\Action\Context                           $context            Context.
     * @param Psr\Log\LoggerInterface                                        $logger             Logger.
     * @param Magento\Framework\Controller\ResultFactory                     $resultFactory      Result factory.
     * @param Magento\Checkout\Model\Session                                 $checkoutSession    Checkout session.
     * @param Magento\Sales\Model\OrderFactory                               $orderFactory       Order factory.
     * @param Magento\Framework\App\Config\ScopeConfigInterface              $scopeConfig        Scope config.
     * @param Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface $transactionBuilder Transaction builder.
     * @param Magento\Sales\Model\Service\InvoiceService                     $invoiceService     Invoice service.
     */
    public function __construct(
        Context $context,
        LoggerInterface $logger,
        ResultFactory $resultFactory,
        Session $checkoutSession,
        OrderFactory $orderFactory,
        QuoteFactory $quoteFactory,
        ScopeConfigInterface $scopeConfig,
        BuilderInterface $transactionBuilder,
        InvoiceService $invoiceService,
        TransactionSearchResultInterfaceFactory $transactionSearch
    ) {
        parent::__construct($context, $logger, $resultFactory);
        $this->checkoutSession    = $checkoutSession;
        $this->orderFactory       = $orderFactory;
        $this->quoteFactory       = $quoteFactory;
        $this->scopeConfig        = $scopeConfig;
        $this->transactionBuilder = $transactionBuilder;
        $this->invoiceService     = $invoiceService;
        $this->transactionSearch  = $transactionSearch;
    }

    /**
     * Get an Instance of the current Checkout Order Object
     * @return \Magento\Sales\Model\Order
     */
    protected function getOrder($id = null)
    {
        $orderId = null === $id 
            ? $this->checkoutSession->getLastRealOrderId() 
            : $id;

        if (!isset($orderId)) {
            return null;
        }

        $order = $this->orderFactory->create()->loadByIncrementId(
            $orderId
        );

        if (!$order->getId()) {
            return null;
        }

        return $order;
    }

    protected function loadOrder($id)
    {
        // @todo use $this->objectManager
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $order = $objectManager->create('\Magento\Sales\Model\OrderRepository')->get($id);
        // $order = $this->orderFactory->create()->get($id);

        if (!$order->getId()) {
            return null;
        }

        return $order;
    }

    /**
     * Returns payment config value by it's name.
     * 
     * @param string $name Config name
     * 
     * @return mixed       Config value.
     */
    protected function getPaymentConfig($name)
    {
        // @todo change to Novapay\Payment\Gateway\Config->getValue();
        return $this->scopeConfig->getValue(
            "payment/novapayment_gateway/$name",
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Returns order status value by it's payment status.
     * Stores > Configuration > Sales > Payment methods > Novapay > Status Mapping
     * 
     * @param string $status Payment status name.
     * 
     * @return string        Order status.
     */
    protected function getPaymentStatus($status)
    {
        return $this->getPaymentConfig("status_$status");
    }

    /**
     * Prepare (initialise) SDK\Model\Model with config values stored in the 
     * website/store settings.
     * 
     * @return void
     */
    protected function initPaymentModel()
    {
        // @todo fix test mode
        if (Model::MODE_LIVE === $this->getPaymentConfig('mode')) {
            Model::enableLiveMode();
        } else {
            Model::disableLiveMode();
        }
        // Enable tracing to use it with Model::getLog() after actions to 
        // see the curl requests
        Model::enableTracing();

        Model::setPrivateKey($this->getPaymentConfig('private_key'));
        Model::setPassword($this->getPaymentConfig('private_key_pass'));
        Model::setPublicKey($this->getPaymentConfig('public_key'));
    }

    /**
     * Returns transaction type based on API session status.
     * 
     * @param string $status API session status.
     * 
     * @return string        Transaction type.
     */
    protected function getTransactionType($status)
    {
        // TransactionInterface::TYPE_PAYMENT;
        // TransactionInterface::TYPE_ORDER;
        // TransactionInterface::TYPE_AUTH;
        // TransactionInterface::TYPE_CAPTURE;
        // TransactionInterface::TYPE_VOID;
        // Unknown type for Novapay
        // TransactionInterface::TYPE_REFUND;

        switch ($status) {
            case SessionSDK::STATUS_PROCESS:
            case SessionSDK::STATUS_HOLDED:
                return TransactionInterface::TYPE_CAPTURE;
            case SessionSDK::STATUS_CONFIRMED:
            case SessionSDK::STATUS_COMPLETE:
            case SessionSDK::STATUS_FAILED:
                return TransactionInterface::TYPE_PAYMENT;
            case SessionSDK::STATUS_PAID:
                return TransactionInterface::TYPE_ORDER;
            break;
            case SessionSDK::STATUS_VOIDED:
                return TransactionInterface::TYPE_VOID;
            // case SessionSDK::STATUS_EXPIRED:
            // case SessionSDK::STATUS_CREATED:
            // case SessionSDK::STATUS_VOIDING:
        }
        // default type
        return TransactionInterface::TYPE_AUTH;
    }

    /**
     * Does a redirect to the Checkout Payment Page
     * @todo remove?
     * @return void
     */
    protected function redirectToCheckoutFragmentPayment()
    {
        $this->_redirect('checkout', ['_fragment' => 'payment']);
    }

    /**
     * Does a redirect to the Checkout Success Page
     * @todo remove?
     * @return void
     */
    protected function redirectToCheckoutOnePageSuccess()
    {
        $this->_redirect('checkout/onepage/success');
    }

    /**
     * Does a redirect to the Checkout Cart Page
     * @todo remove?
     * @return void
     */
    protected function redirectToCheckoutCart()
    {
        $this->_redirect('checkout/cart');
    }
}
