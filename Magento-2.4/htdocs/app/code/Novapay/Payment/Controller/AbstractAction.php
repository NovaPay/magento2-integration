<?php

namespace Novapay\Payment\Controller;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Psr\Log\LoggerInterface;

/**
 * Base Controller Class
 * Class AbstractAction
 * @package Novapay\Payment\Controller
 */
abstract class AbstractAction extends Action
{
    /**
     * @var \Magento\Framework\App\Action\Context
     */
    private $_context;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    protected $resultFactory;

    /**
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        LoggerInterface $logger,
        ResultFactory $resultFactory
    ) {
        parent::__construct($context);
        $this->_context      = $context;
        $this->logger        = $logger;
        $this->resultFactory = $resultFactory;
    }

    /**
     * Get Instance of Magento Controller Action
     * @return \Magento\Framework\App\Action\Context
     */
    protected function getContext()
    {
        return $this->_context;
    }

    /**
     * Get Instance of Magento Object Manager
     * @return \Magento\Framework\ObjectManagerInterface
     */
    protected function getObjectManager()
    {
        return $this->_objectManager;
    }

    /**
     * Get Instance of Magento global Message Manager
     * @return \Magento\Framework\Message\ManagerInterface
     */
    protected function getMessageManager()
    {
        return $this->getContext()->getMessageManager();
    }

    /**
     * Check if param exists in the post request
     * @param string $key
     * @return bool
     */
    protected function isPostRequestExists($key)
    {
        $post = $this->getPostRequest();

        return isset($post[$key]);
    }

    /**
     * Get an array of the Submitted Post Request
     * @param string|null $key
     * @return null|array
     */
    protected function getPostRequest($key = null)
    {
        $post = $this->getRequest()->getPostValue();

        if (isset($key) && isset($post[$key])) {
            return $post[$key];
        }
        if (isset($key)) {
            return null;
        }
        return $post;
    }

    /**
     * Sets result type for current controller.
     * 
     * @param int $type Result type.
     * 
     * @return void
     */
    protected function setResultType($type = ResultFactory::TYPE_LAYOUT)
    {
        $this->_resultType = $type;
    }

    /**
     * Returns result type for current controller.
     * 
     * @return int Result Type.
     */
    protected function getResultType()
    {
        return null === $this->_resultType 
            ? ResultFactory::TYPE_LAYOUT : $this->_resultType;
    }

    /**
     * Returns error to UI in different result type.
     * 
     * @param mixed $error Error message.
     * @param int   $type  Result type, if NULL the current $_resultType used.
     * 
     * @return [type]
     */
    protected function answerError($error, $type = null)
    {
        $this->messageManager->addError($error);
        if (null === $type) {
            $type = $this->getResultType();
        }
        $result = $this->resultFactory->create($type);
        if (ResultFactory::TYPE_REDIRECT === $type) {
            $result->setUrl($this->_redirect->getRefererUrl());
        }
        return $result;
    }

    /**
     * Returns JSON data to UI.
     * 
     * @param mixed $data The data to print.
     * 
     * @return Magento\Framework\Controller\ResultInterface|Magento\Framework\View\Result\Layout
     */
    protected function answerJSON($data)
    {
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        if (is_string($data)) {
            $result->setJsonData($data);
        } else {
            $result->setData($data);
        }
        return $result;
    }
}
