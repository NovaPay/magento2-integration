<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Model;

use Magento\Framework\UrlInterface;
use Magento\Payment\Model\InfoInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Api\OrderPaymentRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address;
use NovaPay\Payment\Gateway\Config;
use NovaPay\Payment\Gateway\Http\Client;
use NovaPay\Payment\Gateway\Request\AddPaymentBuilder;
use NovaPay\Payment\Gateway\Request\CreateSessionBuilder;
use NovaPay\Payment\Model\Exception\PhoneValidationException;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates the checkout-time NovaPay calls: `POST /v1/session` then `POST /v1/payment` for a
 * just-placed order, persisting session_id/novapay_payment_id/external_id/novapay_redirect_url to
 * `sales_order_payment.additional_information`, and returning the payment frame URL the customer
 * must be redirected to verbatim. Never mutates order state/status itself — that is
 * PostbackProcessor/StatusMapper's job only.
 *
 * All amounts sent to NovaPay use **base currency** figures: NovaPay's API has no currency field,
 * every session/payment is implicitly UAH, and the store's base currency is guaranteed UAH by the
 * gateway adapter's `currency` validator, which hides the method for non-UAH stores before checkout
 * reaches this class — `buildRedirectUrl()` still asserts it defensively.
 *
 * Idempotent by design: once `/v1/payment` succeeds, the frame url is cached in
 * `additional_information.novapay_redirect_url` and served verbatim on any later call for an order
 * still awaiting payment — keyed on the cached URL, not the session id, so a call that saved a
 * session but failed before `/v1/payment` succeeded still creates a fresh session+payment on retry.
 *
 * @api
 */
class CheckoutOrchestrator
{
    private const INFO_SESSION_ID = 'novapay_session_id';
    private const INFO_PAYMENT_ID = 'novapay_payment_id';
    private const INFO_EXTERNAL_ID = 'novapay_external_id';
    private const INFO_REDIRECT_URL = 'novapay_redirect_url';

    /**
     * NovaPay has no currency field in its API — every session/payment is implicitly this currency.
     */
    private const SUPPORTED_CURRENCY = 'UAH';

    /**
     * Per-line tolerance (in currency units) for the products[]-vs-amount reconciliation check.
     * Scaled by the number of `products[]` lines ({@see assertLinesReconcile()}): each line's
     * `price` is independently rounded to 2 decimals from `netRowTotal / qty`, so a qty>1 line can
     * be off by up to half a cent per line even with correct math.
     */
    private const RECONCILE_TOLERANCE = 0.005;

    /**
     * @param CreateSessionBuilder $createSessionBuilder
     * @param AddPaymentBuilder $addPaymentBuilder
     * @param Client $client
     * @param Config $config
     * @param UrlInterface $urlBuilder
     * @param OrderPaymentRepositoryInterface $orderPaymentRepository
     * @param PhoneNumberValidator $phoneNumberValidator
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CreateSessionBuilder $createSessionBuilder,
        private readonly AddPaymentBuilder $addPaymentBuilder,
        private readonly Client $client,
        private readonly Config $config,
        private readonly UrlInterface $urlBuilder,
        private readonly OrderPaymentRepositoryInterface $orderPaymentRepository,
        private readonly PhoneNumberValidator $phoneNumberValidator,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Create the NovaPay session + payment for `$order` and return the frame URL to redirect to.
     *
     * Idempotent: if a previous call already obtained a frame URL for this order and the order is
     * still awaiting payment, that URL is returned verbatim and no NovaPay call is made.
     *
     * @param Order $order a just-placed order paid with the novapay method
     * @return string the `url` NovaPay returned from `/v1/payment` — must be used verbatim
     * @throws \InvalidArgumentException missing required data (phone, payment), non-UAH base
     *     currency, an order no longer awaiting payment, or an unexpected (200 but empty) NovaPay
     *     response
     * @throws PhoneValidationException phone_validation_enabled and the billing address telephone,
     *     or (for a non-virtual order with a shipping address) the shipping address telephone, does
     *     not match the Ukrainian +380 mask
     * @throws \NovaPay\Payment\Gateway\Http\Exception\ApiException NovaPay 4xx/5xx/timeout
     * @throws \NovaPay\Payment\Gateway\Exception\InvalidKeyException signing key rejected by openssl
     */
    public function buildRedirectUrl(Order $order): string
    {
        // Defensive backstop so a non-UAH order can never silently send its order-currency total
        // to NovaPay as if it were UAH.
        if ($order->getBaseCurrencyCode() !== self::SUPPORTED_CURRENCY) {
            throw new \InvalidArgumentException(
                (string)__('NovaPay only supports orders billed in the store base currency UAH.')
            );
        }

        $storeId = (int)$order->getStoreId();

        /** @var (OrderPaymentInterface&InfoInterface)|null $payment */
        $payment = $order->getPayment();
        if ($payment === null) {
            throw new \InvalidArgumentException(
                (string)__('The order has no payment information to pay with NovaPay.')
            );
        }

        if (!$this->isAwaitingPayment($order)) {
            throw new \InvalidArgumentException(
                (string)__('This order is no longer awaiting a NovaPay payment.')
            );
        }

        $existingUrl = trim((string)($payment->getAdditionalInformation(self::INFO_REDIRECT_URL) ?? ''));
        if ($existingUrl !== '') {
            return $existingUrl;
        }

        $this->validateShippingPhone($order, $storeId);

        /** @var Address|null $billingAddress */
        $billingAddress = $order->getBillingAddress();
        $phone = $billingAddress !== null ? trim((string)$billingAddress->getTelephone()) : '';
        if ($phone === '') {
            throw new \InvalidArgumentException(
                (string)__('A phone number on the billing address is required to pay with NovaPay.')
            );
        }

        $phone = $this->normalizePhone($phone, $storeId);

        $sessionResponse = $this->client->request(
            '/v1/session',
            $this->createSessionBuilder->build($this->buildSessionData($order, $billingAddress, $phone, $storeId)),
            $storeId
        );

        $sessionId = trim((string)($sessionResponse['id'] ?? ''));
        if ($sessionId === '') {
            throw new \InvalidArgumentException((string)__('NovaPay did not return a session id.'));
        }

        $payment->setAdditionalInformation(self::INFO_SESSION_ID, $sessionId);
        $this->orderPaymentRepository->save($payment);

        $paymentResponse = $this->client->request(
            '/v1/payment',
            $this->addPaymentBuilder->build($this->buildPaymentData($order, $sessionId, $storeId)),
            $storeId
        );

        $url = trim((string)($paymentResponse['url'] ?? ''));
        if ($url === '') {
            throw new \InvalidArgumentException((string)__('NovaPay did not return a payment redirect url.'));
        }

        // The /v1/payment response's own numeric `id` (distinct from session_id/external_id) is
        // needed for later partial capture/refund operations[].
        $payment->setAdditionalInformation(self::INFO_PAYMENT_ID, $paymentResponse['id'] ?? null);
        $payment->setAdditionalInformation(self::INFO_EXTERNAL_ID, (string)$order->getIncrementId());
        $payment->setAdditionalInformation(self::INFO_REDIRECT_URL, $url);
        $this->orderPaymentRepository->save($payment);

        return $url;
    }

    /**
     * Server-side mirror of the checkout JS's shipping-phone check: when validation is enabled,
     * reject a non-virtual order whose shipping address telephone does not match the Ukrainian
     * +380 mask, before the billing phone is even looked at — the same bug the client-side check
     * guards against (a customer can have a valid billing phone from a saved address while typing
     * an invalid one into the shipping form, or vice versa; only the billing phone is normally sent
     * to NovaPay, so only checking it would silently let an invalid shipping phone through).
     *
     * Unlike the billing phone, the shipping phone is never sent to NovaPay and is never
     * normalized — this only ever validates presence/mask, gated entirely by
     * `phone_validation_enabled` (when disabled, an invalid or missing shipping phone is not
     * rejected, matching the billing phone's disabled-validation behavior).
     *
     * @param Order $order
     * @param int $storeId
     * @return void
     * @throws PhoneValidationException
     */
    private function validateShippingPhone(Order $order, int $storeId): void
    {
        if (!$this->config->isPhoneValidationEnabled($storeId) || $order->getIsVirtual()) {
            return;
        }

        /** @var Address|null $shippingAddress */
        $shippingAddress = $order->getShippingAddress();
        if ($shippingAddress === null) {
            return;
        }

        $phone = trim((string)$shippingAddress->getTelephone());
        if (!$this->phoneNumberValidator->isValid($phone)) {
            throw new PhoneValidationException($this->config->getPhoneValidationErrorMessage($storeId));
        }
    }

    /**
     * Validate/normalize a presence-checked billing address phone against the Ukrainian +380 mask
     * ({@see PhoneNumberValidator}).
     *
     * `phone_validation_enabled` and `phone_normalize_enabled` are independent toggles:
     *
     * - `$phone` parses as a valid Ukrainian number: always sent to NovaPay (mask check trivially
     *   passes regardless of the validation toggle); rewritten to `+380XXXXXXXXX` only when
     *   normalization is enabled, otherwise sent exactly as entered.
     * - `$phone` does not parse: validation enabled -> reject with the admin-configured message
     *   (customer can fix the address and retry); validation disabled -> keep the raw
     *   presence-checked value as before (there is nothing to normalize), so disabling the check
     *   never blocks checkout. The normalize toggle has no effect on this branch — an unparseable
     *   phone cannot be rewritten either way.
     *
     * @param string $phone already presence-checked, not yet normalized
     * @param int $storeId
     * @return string
     * @throws PhoneValidationException
     */
    private function normalizePhone(string $phone, int $storeId): string
    {
        if ($this->phoneNumberValidator->isValid($phone)) {
            return $this->config->isPhoneNormalizeEnabled($storeId)
                ? $this->phoneNumberValidator->normalize($phone)
                : $phone;
        }

        if ($this->config->isPhoneValidationEnabled($storeId)) {
            throw new PhoneValidationException($this->config->getPhoneValidationErrorMessage($storeId));
        }

        return $phone;
    }

    /**
     * Whether `$order` is still in a state where a NovaPay payment attempt makes sense.
     *
     * @param Order $order
     * @return bool
     */
    private function isAwaitingPayment(Order $order): bool
    {
        return in_array($order->getState(), [Order::STATE_NEW, Order::STATE_PENDING_PAYMENT], true);
    }

    /**
     * Build the `/v1/session` request data (docs/novapay-api-reference.md "1. Create session").
     *
     * @param Order $order
     * @param Address|null $billingAddress
     * @param string $phone already presence-checked, and rewritten to `+380XXXXXXXXX` when it
     *     parses as a Ukrainian number and normalization is enabled ({@see normalizePhone()})
     * @param int $storeId
     * @return array
     */
    private function buildSessionData(Order $order, ?Address $billingAddress, string $phone, int $storeId): array
    {
        $data = [
            'client_phone' => $phone,
            'callback_url' => $this->urlBuilder->getUrl('novapay/postback/index', ['_secure' => true]),
            'metadata' => ['order_id' => (string)$order->getIncrementId()],
            'store_id' => $storeId,
        ];

        if ($billingAddress !== null) {
            $this->addNonEmptyString($data, 'client_first_name', $billingAddress->getFirstname());
            $this->addNonEmptyString($data, 'client_last_name', $billingAddress->getLastname());
            $this->addNonEmptyString($data, 'client_patronymic', $billingAddress->getMiddlename());
        }

        $this->addNonEmptyString($data, 'client_email', $order->getCustomerEmail());

        $data['success_url'] = $this->config->getSuccessUrl($storeId)
            ?: $this->urlBuilder->getUrl('novapay/success/index', ['_secure' => true]);
        $data['fail_url'] = $this->config->getFailUrl($storeId)
            ?: $this->urlBuilder->getUrl('novapay/fail/index', ['_secure' => true]);

        $redirectTimeout = $this->config->getSuccessRedirectTimeout($storeId);
        if ($redirectTimeout > 0) {
            $data['success_redirect_timeout'] = $redirectTimeout;
        }

        return $data;
    }

    /**
     * Build the `/v1/payment` request data (docs/novapay-api-reference.md "2. Add payment").
     *
     * `amount` is the order's **base-currency** grand total, rounded to 2 decimals, and must equal
     * the sum of `products[].price * products[].count` (asserted by {@see assertLinesReconcile()}).
     *
     * @param Order $order
     * @param string $sessionId
     * @param int $storeId
     * @return array
     */
    private function buildPaymentData(Order $order, string $sessionId, int $storeId): array
    {
        $amount = round((float)$order->getBaseGrandTotal(), 2);
        $lines = $this->buildProductLines($order);
        $this->assertLinesReconcile($order, $amount, $lines);

        return [
            'session_id' => $sessionId,
            'amount' => $amount,
            'external_id' => (string)$order->getIncrementId(),
            'use_hold' => $this->config->isHoldMode($storeId),
            'products' => $lines,
            'store_id' => $storeId,
        ];
    }

    /**
     * Order lines for the `products` array, in **base currency**, net of discounts.
     *
     * One line per visible item — unit price `(base_row_total_incl_tax - base_discount_amount) / qty`,
     * rounded to 2 decimals — plus one extra line for shipping (`base_shipping_incl_tax -
     * base_shipping_discount_amount`) when positive.
     *
     * `/v1/payment` documents no top-level shipping field, only `products: [{description, count,
     * price}]`; shipping is added as one more line in that same array to keep `amount` consistent
     * with the line-item breakdown.
     *
     * @param Order $order
     * @return array<int, array{description: string, count: float, price: float}>
     */
    private function buildProductLines(Order $order): array
    {
        $lines = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $qty = (float)$item->getQtyOrdered();
            $netRowTotal = (float)$item->getBaseRowTotalInclTax() - (float)$item->getBaseDiscountAmount();

            $lines[] = [
                'description' => (string)$item->getName(),
                'count' => $qty,
                'price' => $qty > 0.0 ? round($netRowTotal / $qty, 2) : 0.0,
            ];
        }

        $shippingLine = round(
            (float)$order->getBaseShippingInclTax() - (float)$order->getBaseShippingDiscountAmount(),
            2
        );
        if ($shippingLine > 0.0) {
            $lines[] = [
                'description' => (string)__('Shipping'),
                'count' => 1.0,
                'price' => $shippingLine,
            ];
        }

        return $lines;
    }

    /**
     * Log a warning (never throws — this must not block checkout) if `$lines` don't sum back up to
     * `$amount` within `self::RECONCILE_TOLERANCE * max(1, count($lines))`. Guards against
     * rounding/discount edge cases silently sending NovaPay a `products[]` breakdown that doesn't
     * match `amount`.
     *
     * @param Order $order
     * @param float $amount
     * @param array $lines each `{description: string, count: float, price: float}`
     * @return void
     */
    private function assertLinesReconcile(Order $order, float $amount, array $lines): void
    {
        $sum = 0.0;
        foreach ($lines as $line) {
            $sum += $line['price'] * $line['count'];
        }
        $sum = round($sum, 2);

        // Round the delta before comparing: float subtraction of two cent-rounded values can land a
        // hair above an exact-cent tolerance.
        $tolerance = self::RECONCILE_TOLERANCE * max(1, count($lines));
        $delta = round(abs($sum - $amount), 4);
        if ($delta > $tolerance) {
            $this->logger->warning('NovaPay products[] total does not reconcile with the order amount', [
                'event' => 'checkout.amount_reconciliation_warning',
                'order_increment_id' => $order->getIncrementId(),
                'amount' => $amount,
                'products_sum' => $sum,
            ]);
        }
    }

    /**
     * Add `$key => (string)$value` to `$data` unless the stringified value is empty.
     *
     * @param array $data
     * @param string $key
     * @param mixed $value
     * @return void
     */
    private function addNonEmptyString(array &$data, string $key, mixed $value): void
    {
        $stringValue = (string)$value;
        if ($stringValue !== '') {
            $data[$key] = $stringValue;
        }
    }
}
