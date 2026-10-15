<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Gateway;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Payment\Gateway\Config\Config as GatewayConfig;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Store\Model\ScopeInterface;

/**
 * Typed accessors for every NovaPay admin config field.
 *
 * @api
 */
class Config extends GatewayConfig
{
    public const CODE = 'novapay';

    public const KEY_ACTIVE = 'active';
    public const KEY_TITLE = 'title';
    public const KEY_TEST_MODE = 'test_mode';
    public const KEY_MERCHANT_ID = 'merchant_id';
    public const KEY_PRIVATE_KEY = 'private_key';
    public const KEY_PUBLIC_KEY = 'public_key';
    public const KEY_PAYMENT_ACTION = 'payment_action';
    public const KEY_POSTBACK_VERSION = 'postback_version';
    public const KEY_SUCCESS_URL = 'success_url';
    public const KEY_FAIL_URL = 'fail_url';
    public const KEY_SUCCESS_REDIRECT_TIMEOUT = 'success_redirect_timeout';
    public const KEY_RECIPIENT_IDENTIFIER = 'recipient_identifier';
    public const KEY_DEBUG = 'debug';
    public const KEY_PHONE_NORMALIZE_ENABLED = 'phone_normalize_enabled';
    public const KEY_PHONE_VALIDATION_ENABLED = 'phone_validation_enabled';
    public const KEY_PHONE_VALIDATION_ERROR_MESSAGE = 'phone_validation_error_message';
    public const KEY_REFUND_FLOW = 'refund_flow';
    public const KEY_HOLD_CONFIRM_FLOW = 'hold_confirm_flow';

    /**
     * Used only when the store-scoped {@see self::KEY_PHONE_VALIDATION_ERROR_MESSAGE} value is
     * empty (config.xml normally always populates it) — kept in sync with config.xml's default.
     */
    private const DEFAULT_PHONE_VALIDATION_ERROR_MESSAGE =
        'Please enter a valid Ukrainian phone number in +380XXXXXXXXX format.';

    /**
     * Status-mapping config field names are flat (payment/novapay/status_*, not a status_mapping/*
     * subtree): system.xml's config_path only accepts exactly 3 path segments (section/group/field).
     */
    private const STATUS_MAPPING_FIELDS = [
        'created' => 'status_created',
        'processing' => 'status_processing',
        'holded' => 'status_holded',
        'hold_confirmed' => 'status_hold_confirmed',
        'paid' => 'status_paid',
        'failed' => 'status_failed',
        'voided' => 'status_voided',
        'expired' => 'status_expired',
    ];

    /**
     * Core "Pending Payment Order Lifetime (minutes)" setting, read by
     * {@see getPendingPaymentLifetimeMinutes()}.
     */
    public const XML_PATH_PENDING_PAYMENT_LIFETIME = 'sales/orders/delete_pending_after';

    /**
     * Core default of {@see XML_PATH_PENDING_PAYMENT_LIFETIME} (Magento_Sales etc/config.xml).
     */
    private const DEFAULT_PENDING_PAYMENT_LIFETIME = 480;

    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $coreScopeConfig;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param EncryptorInterface $encryptor
     * @param string|null $methodCode
     * @param string $pathPattern
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        $methodCode = self::CODE,
        $pathPattern = self::DEFAULT_PATH_PATTERN
    ) {
        parent::__construct($scopeConfig, $methodCode, $pathPattern);
        $this->coreScopeConfig = $scopeConfig;
    }

    /**
     * Core's pending payment order lifetime in minutes, store scope.
     *
     * Stores > Configuration > Sales > Sales > Orders Cron Settings; core's default 480 when unset
     * or not numeric.
     *
     * @param int|null $storeId
     * @return int
     */
    public function getPendingPaymentLifetimeMinutes(?int $storeId = null): int
    {
        $value = $this->coreScopeConfig->getValue(
            self::XML_PATH_PENDING_PAYMENT_LIFETIME,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return is_numeric($value) ? max(0, (int)$value) : self::DEFAULT_PENDING_PAYMENT_LIFETIME;
    }

    /**
     * Whether the method is enabled at the current store scope.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isActive(?int $storeId = null): bool
    {
        return (bool)$this->getValue(self::KEY_ACTIVE, $storeId);
    }

    /**
     * Checkout title shown to the customer.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getTitle(?int $storeId = null): string
    {
        return (string)$this->getValue(self::KEY_TITLE, $storeId);
    }

    /**
     * Whether the module should call the qecom (test) API/frame instead of the production ones.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isTestMode(?int $storeId = null): bool
    {
        return (bool)$this->getValue(self::KEY_TEST_MODE, $storeId);
    }

    /**
     * Merchant identifier issued by NovaPay.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getMerchantId(?int $storeId = null): string
    {
        return (string)$this->getValue(self::KEY_MERCHANT_ID, $storeId);
    }

    /**
     * Merchant RSA private key (used to sign outgoing requests), decrypted.
     *
     * Stored encrypted via Magento\Config\Model\Config\Backend\Encrypted — never logged.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getPrivateKey(?int $storeId = null): string
    {
        $encrypted = (string)$this->getValue(self::KEY_PRIVATE_KEY, $storeId);
        if ($encrypted === '') {
            return '';
        }

        return $this->encryptor->decrypt($encrypted);
    }

    /**
     * NovaPay's RSA public key (used to verify postback signatures).
     *
     * @param int|null $storeId
     * @return string
     */
    public function getPublicKey(?int $storeId = null): string
    {
        return (string)$this->getValue(self::KEY_PUBLIC_KEY, $storeId);
    }

    /**
     * Raw Magento payment_action value: authorize_capture (direct) or authorize (hold).
     *
     * @param int|null $storeId
     * @return string
     */
    public function getPaymentAction(?int $storeId = null): string
    {
        $action = (string)$this->getValue(self::KEY_PAYMENT_ACTION, $storeId);

        return $action !== '' ? $action : AbstractMethod::ACTION_AUTHORIZE_CAPTURE;
    }

    /**
     * Whether the configured charge model is "hold" (two-step: authorize now, capture later).
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isHoldMode(?int $storeId = null): bool
    {
        return $this->getPaymentAction($storeId) === AbstractMethod::ACTION_AUTHORIZE;
    }

    /**
     * Postback payload version to parse with: v1, v2 or v3 (default v3).
     *
     * @param int|null $storeId
     * @return string
     */
    public function getPostbackVersion(?int $storeId = null): string
    {
        $version = (string)$this->getValue(self::KEY_POSTBACK_VERSION, $storeId);

        return $version !== '' ? $version : 'v3';
    }

    /**
     * Optional override for the redirect-back-on-success URL; empty = Magento default.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getSuccessUrl(?int $storeId = null): string
    {
        return (string)$this->getValue(self::KEY_SUCCESS_URL, $storeId);
    }

    /**
     * Optional override for the redirect-back-on-failure URL; empty = Magento default.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getFailUrl(?int $storeId = null): string
    {
        return (string)$this->getValue(self::KEY_FAIL_URL, $storeId);
    }

    /**
     * Seconds after which NovaPay's result page redirects the customer to the Success URL
     * (`success_redirect_timeout` of `/v1/session`). 0 disables the automatic redirect; a missing,
     * non-numeric or negative value counts as 0.
     *
     * @param int|null $storeId
     * @return int
     */
    public function getSuccessRedirectTimeout(?int $storeId = null): int
    {
        $value = trim((string)$this->getValue(self::KEY_SUCCESS_REDIRECT_TIMEOUT, $storeId));

        return ctype_digit($value) ? (int)$value : 0;
    }

    /**
     * Recipient identifier (ЄДРПОУ/ІПН) used in partial complete-hold operations[].
     *
     * @param int|null $storeId
     * @return string
     */
    public function getRecipientIdentifier(?int $storeId = null): string
    {
        return (string)$this->getValue(self::KEY_RECIPIENT_IDENTIFIER, $storeId);
    }

    /**
     * Whether request/response/postback logging is enabled (critical errors always log regardless).
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isLoggingEnabled(?int $storeId = null): bool
    {
        return (bool)$this->getValue(self::KEY_DEBUG, $storeId);
    }

    /**
     * Whether a parseable billing address telephone is rewritten to `+380XXXXXXXXX` before being
     * sent to NovaPay. When disabled, the phone is passed through as entered (still subject to
     * {@see self::isPhoneValidationEnabled()} mask validation, which just doesn't rewrite it).
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isPhoneNormalizeEnabled(?int $storeId = null): bool
    {
        return (bool)$this->getValue(self::KEY_PHONE_NORMALIZE_ENABLED, $storeId);
    }

    /**
     * Whether checkout must validate the billing address telephone against the Ukrainian mask.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isPhoneValidationEnabled(?int $storeId = null): bool
    {
        return (bool)$this->getValue(self::KEY_PHONE_VALIDATION_ENABLED, $storeId);
    }

    /**
     * Customer-facing message shown when phone validation rejects the billing address telephone.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getPhoneValidationErrorMessage(?int $storeId = null): string
    {
        $message = trim((string)$this->getValue(self::KEY_PHONE_VALIDATION_ERROR_MESSAGE, $storeId));

        return $message !== '' ? $message : self::DEFAULT_PHONE_VALIDATION_ERROR_MESSAGE;
    }

    /**
     * Whether the optional NovaPay refund button replaces the Credit Memo / Refund Offline buttons
     * in the admin order view for NovaPay orders (the button still creates a standard credit memo or
     * cancels the order through the core services).
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isRefundFlowEnabled(?int $storeId = null): bool
    {
        return (bool)$this->getValue(self::KEY_REFUND_FLOW, $storeId);
    }

    /**
     * Whether the optional "Confirm NovaPay Hold" button replaces the native Invoice button.
     *
     * Applies in the admin order view of NovaPay orders; the button still creates a standard online invoice.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isHoldConfirmFlowEnabled(?int $storeId = null): bool
    {
        return (bool)$this->getValue(self::KEY_HOLD_CONFIRM_FLOW, $storeId);
    }
    /**
     * Magento order status configured for a given NovaPay session status.
     *
     * @param string $sessionStatus keys: created, processing, holded, hold_confirmed, paid,
     *     failed, voided, expired
     * @param int|null $storeId
     * @return string
     */
    public function getMappedOrderStatus(string $sessionStatus, ?int $storeId = null): string
    {
        if (!isset(self::STATUS_MAPPING_FIELDS[$sessionStatus])) {
            return '';
        }

        return (string)$this->getValue(self::STATUS_MAPPING_FIELDS[$sessionStatus], $storeId);
    }
}
