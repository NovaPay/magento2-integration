<?php
/**
 * Novapayment method.
 * 
 * PHP version 7.X
 * 
 * @category Module
 * @package  Magento2
 * @author   NovaPay <acquiring@novapay.ua>
 * @license  https://github.com/NovaPay/prestashop-integration/blob/master/LICENSE MIT
 * @link     https://novapay.ua/
 */

namespace Novapay\Payment\Model\Payment;

use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Payment\Model\InfoInterface;

use Novapay\Payment\Model\Payment\Block\Form as MethodForm;
use Novapay\Payment\Model\Payment\Block\Info as MethodInfo;
use Magento\Framework\DataObject;

/**
 * Novapayment method.
 */
class Novapayment extends AbstractMethod
{
    /**
     * @var string
     */
    protected $_formBlockType = MethodForm::class;

    /**
     * @var string
     */
    protected $_infoBlockType = MethodInfo::class;

    /**
     * @var \Magento\Framework\Module\ModuleListInterface
     */
    protected $_moduleList;

    /**
     * @var \Magento\Framework\Stdlib\DateTime\TimezoneInterface
     */
    protected $_localeDate;

    /**
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory
     * @param \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory
     * @param \Magento\Payment\Helper\Data $paymentData
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param Logger $logger
     * @param \Magento\Framework\Module\ModuleListInterface $moduleList
     * @param \Magento\Framework\Stdlib\DateTime\TimezoneInterface $localeDate
     * @param \Magento\Framework\Model\ResourceModel\AbstractResource $resource
     * @param \Magento\Framework\Data\Collection\AbstractDb $resourceCollection
     * @param array $data
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Payment\Model\Method\Logger $logger,
        \Magento\Framework\Module\ModuleListInterface $moduleList,
        \Magento\Framework\Stdlib\DateTime\TimezoneInterface $localeDate,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );
        $this->_moduleList = $moduleList;
        $this->_localeDate = $localeDate;
    }

    /**
     * Assign data to info model instance
     *
     * @param \Magento\Framework\DataObject|mixed $data
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function assignData(DataObject $data)
    {
        // @todo remove additionalData comments
        $this->_log('assignData', $data, null);
        return $this;
        // $additionalData = $data->getData(PaymentInterface::KEY_ADDITIONAL_DATA);
        // if (!is_object($additionalData)) {
        //     $additionalData = new DataObject($additionalData ?: []);
        // }

        /** @var DataObject $info */
        $info = $this->getInfoInstance();
        // $info->addData(
        //     [
        //         'cc_type' => $additionalData->getCcType(),
        //         'cc_owner' => $additionalData->getCcOwner(),
        //         'cc_last_4' => substr($additionalData->getCcNumber(), -4),
        //         'cc_number' => $additionalData->getCcNumber(),
        //         'cc_cid' => $additionalData->getCcCid(),
        //         'cc_exp_month' => $additionalData->getCcExpMonth(),
        //         'cc_exp_year' => $additionalData->getCcExpYear(),
        //         'cc_ss_issue' => $additionalData->getCcSsIssue(),
        //         'cc_ss_start_month' => $additionalData->getCcSsStartMonth(),
        //         'cc_ss_start_year' => $additionalData->getCcSsStartYear()
        //     ]
        // );

        return $this;
    }

    /**
     * Authorize payment abstract method
     *
     * @param \Magento\Framework\DataObject|InfoInterface $payment
     * @param float $amount
     * @return $this
     * @throws LocalizedException
     * @api
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function authorize(InfoInterface $payment, $amount)
    {
        $this->_log('authorize', $payment, $amount);
        if (!$this->canAuthorize()) {
            throw new LocalizedException(__('The authorize action is not available.'));
        }
        return $this;
    }
    /**
     * Capture payment abstract method
     *
     * @param \Magento\Framework\DataObject|InfoInterface $payment
     * @param float $amount
     * @return $this
     * @throws LocalizedException
     * @api
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function capture(InfoInterface $payment, $amount)
    {
        $this->_log('capture', $payment, $amount);
        if (!$this->canCapture()) {
            throw new LocalizedException(__('The capture action is not available.'));
        }
        return $this;
    }
    /**
     * Refund specified amount for payment
     *
     * @param \Magento\Framework\DataObject|InfoInterface $payment
     * @param float $amount
     * @return $this
     * @throws LocalizedException
     * @api
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function refund(InfoInterface $payment, $amount)
    {
        $this->_log('refund', $payment, $amount);
        if (!$this->canRefund()) {
            throw new LocalizedException(__('The refund action is not available.'));
        }
        return $this;
    }

    /**
     * Validate payment method information object.
     * Nothing to validate in current method.
     *
     * @return $this
     * @throws LocalizedException
     */
    public function validate()
    {
        return $this;
    }

    private function _log($method, $payment, $amount)
    {
        // http_response_code(502);
        $msg = json_encode([$method, $payment->getOrder()->getEntityId(), $amount]);
        $this->logger->debug($msg);
    }
}