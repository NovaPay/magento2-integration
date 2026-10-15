<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Controller\Postback;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Store\Model\StoreManagerInterface;
use NovaPay\Payment\Model\PostbackProcessor;
use Psr\Log\LoggerInterface;

/**
 * NovaPay's server-to-server postback endpoint (`novapay/postback/index`). Reachable by NovaPay
 * only — never trust the storefront redirect for order state (that's what
 * {@see \NovaPay\Payment\Controller\Success\Index} / Fail\Index deliberately don't do).
 *
 * `CsrfAwareActionInterface` exempts this route from Magento's form-key CSRF check — a legitimate
 * server-to-server POST from NovaPay carries no Magento form key. All real security here is
 * {@see PostbackProcessor}'s RSA-SHA256 signature verification, which happens first, inside the
 * processor, before anything else.
 *
 * Hard requirement: this controller ALWAYS answers HTTP 200 with a minimal, non-revealing body, no
 * matter the outcome — valid, invalid signature, duplicate, unknown session, internal error. The
 * only place outcomes are visible is the log. {@see PostbackProcessor::process()} already never
 * throws, but this controller wraps it in `\Throwable` again as defense in depth.
 *
 * The store this controller passes to {@see PostbackProcessor::process()} is the one Magento
 * resolves for the *request itself* ({@see StoreManagerInterface::getStore()} — from the request
 * domain / `___store` code, independent of anything in the untrusted payload) — safe to use before
 * the signature is verified because {@see \NovaPay\Payment\Model\CheckoutOrchestrator} always builds
 * `callback_url` from the ordering store's own base URL, so NovaPay posts back to that same store's
 * URL. This is NOT the order's store (unknowable before verification) — see
 * {@see PostbackProcessor::process()}'s docblock for the mismatch-logging step that runs after the
 * order is matched.
 *
 * @api
 */
class Index implements CsrfAwareActionInterface, HttpPostActionInterface
{
    /**
     * @param HttpRequest $request
     * @param RawFactory $resultRawFactory
     * @param PostbackProcessor $postbackProcessor
     * @param LoggerInterface $logger
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly HttpRequest $request,
        private readonly RawFactory $resultRawFactory,
        private readonly PostbackProcessor $postbackProcessor,
        private readonly LoggerInterface $logger,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Run the postback pipeline and always answer HTTP 200.
     *
     * The signature is read from whichever header carries it: `x-sign-v2` (v3 postbacks) if
     * present, otherwise `x-sign` (the legacy v1/v2 shapes). Deliberately NOT driven by the
     * configured postback version — the same NovaPay public key verifies the body either way, so
     * reading whichever header actually arrived is both simpler and more robust than trusting a
     * config field to describe what NovaPay just sent. The version→header mapping is still
     * documented and used for the "missing signature header" diagnostic inside
     * {@see PostbackProcessor::signatureHeaderForVersion()}.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        try {
            $storeId = (int)$this->storeManager->getStore()->getId();
            $rawBody = (string)$this->request->getContent();
            $signature = $this->request->getHeader(PostbackProcessor::HEADER_SIGNATURE_V3)
                ?: $this->request->getHeader(PostbackProcessor::HEADER_SIGNATURE_LEGACY);
            $this->postbackProcessor->process(
                $rawBody,
                $signature !== false ? (string)$signature : '',
                $storeId
            );
        } catch (\Throwable $e) {
            // Defense in depth: nothing may escape this controller (see class docblock).
            $this->logger->critical('NovaPay postback controller caught an unexpected exception.', [
                'event' => 'postback.error',
                'exception' => $e->getMessage(),
            ]);
        }

        return $this->buildOkResponse();
    }

    /**
     * Minimal, non-revealing 200 response, regardless of outcome.
     *
     * @return ResultInterface
     */
    private function buildOkResponse(): ResultInterface
    {
        $result = $this->resultRawFactory->create();
        $result->setHttpResponseCode(200);
        $result->setHeader('Content-Type', 'text/plain');
        $result->setContents('OK');

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
