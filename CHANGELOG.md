# Changelog

All notable changes to `novapay/module-payment` are documented here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning follows
[Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-10-14

Initial release. NovaPay internet acquiring for Magento 2.4.8 (p1–p5), PHP 8.3/8.4.
The Changed and Fixed sections below record what changed during pre-release testing.

### Added

- Native Magento payment adapter (`novapay`) with the redirect flow: order placement →
  `POST /v1/session` → `POST /v1/payment` → redirect to NovaPay's hosted payment page; no card data
  ever touches the store.
- Direct (`authorize_capture`) and Hold (`authorize`) charge models, selected in configuration.
- Hold-mode lifecycle mapped onto native Magento operations: capture confirmation via online
  invoice capture (`POST /v1/complete-hold`, full or partial, ≤29 days), hold cancellation via
  order Void (`POST /v1/void`).
- Refunds mapped onto native online credit memos (`POST /v1/void`): full refund, or partial refund
  per order line, with shipping and adjustment-fee validation per NovaPay's rules. One NovaPay
  refund per order is currently supported pending clarification from NovaPay on the semantics of a
  repeat refund on the same session (see README "Refunds").
- Postback processing pipeline (`novapay/postback/index`): RSA-SHA256 signature verification and
  postback-version parser selection, both configured at the scope of the store resolved from the
  postback request's own URL (never from the untrusted payload; `callback_url` is always built from
  the ordering store's base URL), with a pluggable parser per version (v3 implemented; v1/v2
  stubbed), session-id-first order matching, byte-level idempotency with a bounded processed-hash history, and a status-transition
  matrix that never lets a terminal status (e.g. `paid`) be downgraded by a stale or out-of-order
  postback. Always answers HTTP 200; every outcome is logged.
- `novapay_sync_status` cron (every 15 minutes): recovers from missed postbacks by polling
  `POST /v1/get-status` for orders stuck in a pending/holded status, through the same status-mapper
  pipeline a real postback uses.
- Six custom order statuses (`novapay_pending`, `novapay_holded`, `novapay_paid`,
  `novapay_expired`, `novapay_failed`, `novapay_refunded`) registered via a data patch, bound to the correct Magento order states.
- Admin order-view information block: NovaPay session id, session status, paytype, RRN, approval
  code, masked PAN, and per-line refunded amounts. Credit-memo page banner explaining that NovaPay
  refunds must be issued online, and that offline refunds don't reach NovaPay.
- Dedicated JSON-lines logging channel (`var/log/novapay.log`) for every API
  request/response and every postback outcome, with PAN always masked and the private key never
  logged; critical errors are logged even when "Enable Logging" is off.
- Full configuration screen under Stores → Configuration → Sales → Payment Methods → NovaPay,
  including an encrypted Private Key field and a per-status order-status-mapping group.
- CSP whitelist entries for the NovaPay payment-frame hosts (test and production).
- `en_US` and `uk_UA` translations for all user-facing strings.
- Unit tests (Gateway/, Model/, Controller/, Cron/) and integration tests (checkout payload shape,
  postback controller end-to-end, capture/void command payloads); GitHub Actions CI running
  `phpcs --standard=Magento2`, `phpstan` (level 5), and the full test suite across PHP 8.3/8.4.

- **NovaPay's official `/v1/void` error texts and an order history comment for rejected NovaPay
  calls.** `Gateway\ErrorMapper` now covers every code of NovaPay's refund error list
  (docs/novapay-void-errors.md) with NovaPay's Ukrainian text as the uk_UA translation, including the
  `1c service request failed` / `1c service bad response` strings, `MerchantAccessDenied` (HTTP 403)
  and the code-less HTTP 500 response (matched by 5xx status or its `error` text, labeled `HTTP 500`).
  An unknown code is named in the generic message. When NovaPay returns a response `uuid`, the admin
  message ends with `(NovaPay ref: <uuid>)`. `map()` now returns the new
  `Gateway\GatewayRejectedException` (a `LocalizedException`) with the code, uuid, HTTP status and
  endpoint. The capture/void/refund commands record each rejected or unreachable call in the
  request-scoped `Model\Order\GatewayFailureNoteRegistry`; `Model\Order\GatewayFailureHistoryWriter`
  then adds an admin-only order history comment (admin locale via `AdminPhraseRenderer`, current
  status kept, only the history row saved on a freshly read order) after the rolled-back transaction,
  e.g. "NovaPay: the refund of 34.31 UAH was rejected. RefundTryLaterError: ... NovaPay ref: <uuid>".
  It runs from the module's refund, hold confirmation and hold release controllers and, through
  `Plugin\Adminhtml\Order\GatewayFailureHistoryPlugin`, after the native credit memo Save, invoice
  Save, VoidPayment and Cancel actions. REST/CLI operations get no comment. Texts replaced by
  NovaPay's were removed from the CSVs; unit tests added.

- **Optional "Confirm NovaPay Hold" button in the order view (spec 7).** New setting "NovaPay hold
  confirmation button in order view" (`payment/novapay/hold_confirm_flow`, store view scope, default
  No, read by `Gateway\Config::isHoldConfirmFlowEnabled()`). With No nothing changes. With Yes,
  `Plugin\Adminhtml\Order\HoldConfirmButtonsPlugin` replaces the native Invoice button on NovaPay
  orders with "Confirm NovaPay Hold" ("Підтвердити холдування" in uk_UA) while the NovaPay status is
  `holded`, the order can be invoiced, no invoice exists and the hold is at most 29 days old
  (`Model\HoldConfirm\HoldConfirmAvailability`, same rules as `CaptureCommand`). The button opens the
  new form `novapay/order/confirmHold`: hold amount, Recipient Identifier status and the amount to
  confirm, prefilled with the full hold amount; > 0 and <= the hold amount, partial only with a
  Recipient Identifier (JS and `Model\HoldConfirm\AmountValidator`). `novapay/order/confirmHoldPost`
  maps the amount to invoice quantities (`Model\HoldConfirm\CaptureQtyResolver`, core
  `InvoiceService` on a separate order copy): the full amount invoices everything, a partial amount
  the whole units it covers plus the shipping. A partial amount that does not match whole units is
  not sent; the form comes back with the nearest lower matching amount. The invoice is created like
  the core invoice Save action with `CAPTURE_ONLINE` in one DB transaction
  (`Model\HoldConfirm\HoldInvoiceCreator`), so `CaptureCommand` sends `/v1/complete-hold` (full
  without amount, partial with `operations[]`) and the invoice stays pending until the postback;
  meanwhile the button opens the form read-only. Errors are shown on the form, nothing is saved and
  the entered amount is kept (`Model\HoldConfirm\FormDataStore`). ACL `Magento_Sales::invoice`. en_US
  and uk_UA translations, unit and integration tests. Other NovaPay orders (paid in one step, hold
  already confirmed or older than 29 days) keep the native Invoice button (fixed 2026-09-30: it used to
  be hidden on every NovaPay order while the option was on).
- **Optional NovaPay refund button in the order view.** New setting "NovaPay refund button in order
  view" (`payment/novapay/refund_flow`, store view scope, default No, read by
  `Gateway\Config::isRefundFlowEnabled()`). With No nothing changes. With Yes, orders paid with
  NovaPay get one "NovaPay Refund" button ("Повернення" in uk_UA) per the spec matrix, and the native
  Credit Memo (order and invoice view), Void (order view) and Refund Offline (credit memo form)
  buttons are hidden by adminhtml plugins (`Plugin\Adminhtml\Order\*`); Cancel stays. On a hold
  (`holded`) the button confirms and posts to the new admin action `novapay/order/cancelHold`, which
  calls `OrderManagementInterface::cancel()` (existing cancel command, `POST /v1/void` without
  operations). On a paid order (`paid`, `hold_confirmed`) it opens the new refund form
  `novapay/order/refund`: per paid line SKU, paid and refunded qty, Return to Stock, qty to refund and
  amount to refund (live in JS), the total, the spec banner and "Refund via NovaPay". No qty entered
  means a full refund including shipping, entered qtys a partial refund without shipping.
  `novapay/order/refundPost` builds the credit memo with the core `CreditmemoLoader` like the native
  Save action and refunds it online through `CreditmemoManagementInterface::refund()`
  (`Model\Refund\CreditmemoRefunder`), so the existing refund command and guards apply. Errors are
  shown on the form and nothing is saved. The form disables submit after an earlier refund and the
  qty inputs on a partially captured payment, with the reason shown in advance
  (`Model\Refund\RefundAvailability`, `Model\Refund\RefundCalculator`). ACL: the same resources as
  the native actions (`Magento_Sales::creditmemo`, `Magento_Sales::cancel`). New admin route
  (`etc/adminhtml/routes.xml`), en_US and uk_UA translations, unit and integration tests.
- **Success redirect timeout setting.** New field "Success Redirect Timeout (sec)"
  (`payment/novapay/success_redirect_timeout`, default 5) under the NovaPay payment settings, right
  after Fail URL. It sets how many seconds NovaPay's result page waits before sending the customer
  to the Success URL. The value is sent as `success_redirect_timeout` in `/v1/session` only when it
  is greater than 0; 0 turns the automatic redirect off. Read by
  `Gateway\Config::getSuccessRedirectTimeout()` and added by `Model\CheckoutOrchestrator`. en_US and uk_UA translations added.
- **Order history entry for every applied NovaPay event.** `Model\OrderStatusApplier` adds one
  admin-only, not customer-notified comment per applied postback or get-status sync, including
  partial voids: "NovaPay: status %1 received (%2). Amount %3 %4, RRN %5, paytype %6, session
  created %7." The source is `postback` or `get-status` (passed from `PostbackProcessor::applyParsed()`
  through `StatusMapper::apply()`), a missing value prints `-`, and "session created" is NovaPay's
  `created_at` (new `ParsedPostback::getCreatedAt()`), which is the session creation time, not the
  time of the event. The comment is rendered in the admin locale
  and stamped with the target status like the module's other comments. Duplicates, transient
  statuses and blocked transitions add none. All module comments are now saved with
  `is_customer_notified = 0`.
- **Read-only "Postback URL" field in the NovaPay payment settings.** The admin now shows the fully
  resolved postback URL for the scope being edited, right below Public Key, so the merchant can copy
  it into the NovaPay merchant panel. A store view shows its own secure base URL, a website shows its
  default store view, the default scope shows the default store view of the default website. The
  value is built the same way as the `callback_url` sent with every payment session, so both strings
  are identical. It is rendered by `Block\Adminhtml\System\Config\Form\PostbackUrl` as a read-only
  input without a "Use Default" checkbox; nothing is saved to `core_config_data`. The group comment
  no longer shows the unresolved `{base_url}novapay/postback/index` pattern and points to the new
  field instead (en_US and uk_UA translations updated).

### Changed

- **Invoice, Ship and Hold are hidden on NovaPay orders that still await payment.** New plugin
  `Plugin\Order\RestrictUnpaidActionsPlugin` on `Magento\Sales\Model\Order` makes `canInvoice()`,
  `canShip()` and `canHold()` return false for a `novapay` order whose payment `novapay_status` is
  not `holded`, `hold_confirmed` or `paid`. `canCancel()` is unchanged. The postback pipeline stores
  the incoming status on the payment before its own `canInvoice()` checks, so it is not blocked.
- **`novapay_pending` is assigned to state `new` only.** `AddOrderStatuses` no longer assigns it to
  `pending_payment`, and the new data patch
  `Setup\Patch\Data\UnassignPendingStatusFromPendingPaymentState` removes that assignment on
  existing installs, so Stores > Settings > Order Status lists it once. The patch uses the status
  resource model, because `Order\Status::unassignState()` refuses whenever any order uses the status.
  **Upgrade note:** a store that saved Pending NovaPay Payment for the Processing row of "NovaPay
  Order Status Mapping" now falls back to Pending Payment for that row, with a
  `config.status_label_invalid` warning in `var/log/novapay.log`. Re-select Pending Payment there.

- **New order statuses `novapay_failed` and `novapay_refunded` are now the defaults for the
  `failed` and `voided` rows of "NovaPay Order Status Mapping".** Previously these rows defaulted to
  core Canceled / Closed, so a store operator could not tell a NovaPay-failed payment from a manual
  cancel, or a NovaPay refund from any other close. A new data patch
  `Setup\Patch\Data\AddFailedAndRefundedOrderStatuses` (depending on `AddOrderStatuses`) creates
  "NovaPay Payment Failed" (`novapay_failed`, state `canceled`) and "Refunded via NovaPay"
  (`novapay_refunded`, state `closed`), both visible on the storefront like the other NovaPay
  statuses. The `etc/config.xml` defaults and the built-in fallback in `Model\StatusMapper` point at
  them. The order *state* mapping is unchanged (`failed` stays `canceled`, `voided` stays `closed`).
  Both labels are translated in en_US/uk_UA. The status-creation logic of `AddOrderStatuses` moved
  into the shared `Setup\OrderStatusInstaller`, with no behavior change.
  **Upgrade note:** existing installs get the two statuses on `setup:upgrade`. Saving the payment
  section in the admin stores every mapping row, so many installs hold the old defaults explicitly
  (`status_failed = canceled`, `status_voided = closed`). The data patch
  `Setup\Patch\Data\MigrateFailedAndVoidedStatusDefaults` switches those two default-scope values to
  the new statuses, because they were the shipped defaults rather than a merchant choice. Any other
  saved value, and any website or store-view override, is left unchanged. Only orders processed
  after the upgrade get the new statuses; existing orders keep their current status.
- **Each status-mapping dropdown now lists only the statuses valid for its row.** The fixed
  Magento state of each row decides the options: core `Status\NewStatus` for Created, core
  `Status\Processing` for Holded / Hold Confirmed / Paid, and new module source models
  `Model\Config\Source\Order\Status\PendingPayment` for Processing, `...\Canceled` for Failed /
  Expired and `...\Closed` for Voided. Previously every order status was offered, so a choice such
  as Complete for Hold Confirmed was saved in the admin but silently rejected at runtime by
  `StatusMapper` (`config.status_label_invalid`) in favor of the default. The `config_path` values
  are unchanged. A value saved earlier that is not valid for its row is no longer among the options,
  so the dropdown shows "-- Please Select --" for it. At runtime it keeps falling back to the
  default, as before, and re-saving the page stores an empty value, which also means "use the
  default".
- **The NovaPay log moved from `var/log/novapay/novapay.log` to `var/log/novapay.log`.** The file
  now sits directly in `var/log/`, so admin log viewer extensions that list only top-level
  `var/log` files show it, and the conventional `var/log/*.log` logrotate glob covers it. Only
  the `fileName` argument of `Logger\Handler` in `etc/di.xml` changed; the channel is still the
  dedicated `novapay` channel with no dual-write into `system.log`/`debug.log`. The "Enable Logging"
  field comment in the admin (and its en_US/uk_UA translations) names the new path.
  **Upgrade note:** existing lines under `var/log/novapay/novapay.log` are not moved
  automatically — append that file to `var/log/novapay.log` and remove the `var/log/novapay/`
  folder (see the README "Logging" section).
- **Every received postback is now logged with its payload.** `Model\PostbackProcessor::process()`
  writes one INFO entry (`event: postback.received`) on the `novapay` channel for every postback
  that clears the empty-body and maximum-size checks — valid *and* invalid, before signature
  verification — carrying the body itself alongside `body_sha256`, `body_bytes`,
  `signature_present`, `postback_version` and `request_store_id`. Previously only the hash and the
  outcome verdict were logged, so the payload behind a rejected postback was unrecoverable
  (requirements section 9, postback acceptance criterion 6). The body is masked with the module's
  shared `Gateway\Http\PanMasker` (the same rule applied to API request/response bodies): a PAN is
  written as first 6 digits + asterisks + last 4 (`444455******1111`) and `client_phone` is masked
  by field name, whatever the payload contains. A body that is not parsable JSON still goes through
  the masker's blind digit-run pass, so a masked string is logged either way. The entry obeys the
  existing **Enable Logging** toggle, exactly like the `api.request`/`api.response` entries; the
  WARNING-and-above outcome verdicts remain unconditional. `PostbackProcessor`'s constructor gained
  a `PanMasker` argument.

### Fixed

- **Hold confirmation finds an exact whole-unit match instead of the greedy one.**
  `Model\HoldConfirm\CaptureQtyResolver` took whole units of the order lines in order as long as
  they fit, so an amount matching a later line alone was rejected (order 000000227: Yoga Brick 5,
  Water Bottle 7, shipping 10; 17.00 took the brick, proposed 15.00). It now searches every
  combination of whole units (bounded subset sum over unit prices in cents divided by their GCD,
  searched only up to the amount plus a rounding slack plus one unit; at most 1,000,000 steps /
  10,000,000 step visits, about 32 MB and 0.3 s, otherwise the old greedy rule) and picks an exact
  match, preferring the earliest order lines; zero-priced lines are always invoiced in full and
  configurable/bundle children stay grouped by their parent line. The choice is made on core trial
  invoice totals (rounding, tax, discount), not on the estimate: every combination within the
  rounding slack of the amount (half a cent per unit plus a cent per line, at most 20) is checked, so
  a suggested amount whose estimate was a cent off (row discount spread over units) confirms exactly
  when resubmitted, and the lower result is the largest core total below the amount.
  When no combination matches, the form proposes the nearest lower amount as before and the warning
  also names the nearest higher one below the full hold ("... is 49.00 (1 x Tee), the nearest higher
  one is 69.00 (1 x Tee, 1 x Bag) ..."); an amount below every item names the smallest valid amount.
  `resolve()` returns the new keys `next_total` / `next_lines` (null / empty when not applicable).

- **Failed payment no longer cancels the order; retry in the same session is applied.** NovaPay
  lets the customer retry on the payment page within the same session, so `failed` can be followed
  by `holded` / `paid`. Before, `failed` canceled the order and the guard in `Model\StatusMapper`
  treated it as terminal, so the later `holded` was rejected (`postback.blocked_transition
  reason=terminal_status`) and the order stayed canceled while the money was held (stage QA).
  Customer spec section 5 maps `failed` to "failed" (payment error), not to a cancel.
  Now `failed` maps to state `pending_payment` with the `novapay_failed` label and has no side
  effect in `Model\OrderStatusApplier` (the stock reservation stays, like any order awaiting
  payment); only `expired` cancels (`applyFailedOrExpired()` renamed to `applyExpired()`). From a
  stored `failed` the guard accepts `failed`, `processing`, `holded`, `paid`, `hold_confirmed` and
  `expired` while the Magento order is open. A new first guard layer follows the Magento order
  state, whatever the stored NovaPay status: a canceled or closed order (canceled by the old
  behavior, by an admin, or by the cron below) accepts only `expired` (relabel only; `applyExpired()`
  no longer tries a second cancel) and `voided` (hold release / full refund confirmations).
  `paid`, `hold_confirmed` and now also `holded` are blocked and logged CRITICAL with reason
  `money_taking_resurrection` (WARNING when the stored status is already paid / hold_confirmed /
  voided); every other status is blocked with WARNING reason `order_closed`. Before, an order
  canceled by an admin while its stored status was `created` / `processing` could be reopened by a
  later `failed` (state pending_payment with canceled items) and then by `holded` / `paid`. `holded`
  also joined the money-taking
  statuses for a stored `expired`, so a blocked `expired -> holded` is now CRITICAL instead of
  WARNING (still blocked). A `failed` arriving after `holded` (a late event from the earlier attempt
  of the same session) is now blocked with WARNING reason `stale_failed_after_hold`, so a held order
  never drops back to `pending_payment`; before, `holded -> failed` was allowed and canceled the
  order. Blocked-transition log lines carry the order state (`order_state`).
  The new data patch `Setup\Patch\Data\AssignFailedStatusToPendingPaymentState` assigns
  `novapay_failed` to `pending_payment` as well (not default, visible on the storefront); its
  `canceled` assignment stays for older orders. The Failed row of "NovaPay Order Status Mapping" now
  lists Pending Payment statuses (source `Model\Config\Source\Order\Status\PendingPayment`), with a
  new field comment (en_US/uk_UA). `Cron\SyncStatus` also polls `novapay_failed` orders in state
  `pending_payment`, so a lost postback after a successful retry is recovered; canceled
  `novapay_failed` orders are not polled. Core's `sales_clean_expired_orders` only cancels status
  `pending_payment`, so `Cron\SyncStatus` now also cancels a failed order itself when get-status
  still reports `failed` and the order is older than `sales/orders/delete_pending_after` (store
  scope, default 480 minutes, from `created_at`; new `Gateway\Config::getPendingPaymentLifetimeMinutes()`):
  `StatusMapper::cancelStaleFailedOrder()` → `OrderStatusApplier::cancelStaleFailed()` runs native
  `Order::cancel()` (stock released), keeps the novapay_failed label in state `canceled` (core
  `canceled` default when that assignment is missing), adds an admin-only history comment (en_US /
  uk_UA) and logs NOTICE `cron.failed_order_expired`; `novapay_status` stays `failed`. `SyncStatus`
  has a new constructor argument (`StatusMapper`). README troubleshooting explains the CRITICAL
  `money_taking_resurrection` after an admin cancel as a reconciliation signal. Unit and integration
  tests added.
  **Upgrade note:** a Failed row saved with a canceled-state status (for example core Canceled) now
  falls back to `novapay_failed` with a `config.status_label_invalid` warning; re-select a Pending
  Payment status there.

- **Partial operations always use the NovaPay operation id.** A partial `/v1/complete-hold` and a
  partial `/v1/void` send the numeric NovaPay operation id as `operations[].id` (the `/v1/payment`
  response `id`; for a refund after a partial hold confirmation, the newer `transaction_id`). NovaPay
  confirmed on 2026-10-12 that the spec's `payments[].external_id` (sections 7.4.1 and 8.3) is wrong:
  with it partial hold confirmation and refund are impossible (stage: `SessionNotFoundError`, orders
  000000184 and 000000186). The interim external_id variant and its admin setting were removed.

- **An out-of-order earlier status no longer downgrades a held order.** The status-progression
  guard in `Model\StatusMapper` only restricted the terminal statuses (paid, hold_confirmed, voided,
  failed, expired). A late `processing` or `created` event arriving after `holded` (a different
  body, so the payload-hash idempotency did not catch it) was applied: `novapay_status` was
  overwritten, the order dropped to `pending_payment` and `Gateway\Command\CaptureCommand` then
  refused to confirm the hold. A second layer, the lifecycle rank `created` < `processing` <
  `holded` < `paid` = `hold_confirmed` < `voided`, now blocks any move to a lower rank when both
  statuses are ranked. The terminal matrix still runs first and is unchanged. `failed` and `expired`
  are not ranked, so a hold can still expire or fail. A blocked downgrade logs WARNING
  `postback.blocked_transition` with `reason` = `rank_downgrade`; terminal-matrix blocks now carry
  `reason` = `terminal_status`. Unit tests.
- **Two concurrent `paid` events for the same session can no longer create two invoices.**
  `Model\PostbackProcessor` had no lock around its apply tail: two `paid` events with different
  bodies in the same second (two NovaPay retries, or a retry and the get-status cron) both passed
  the hash check, both loaded the order with no invoice and both invoiced it. The tail now runs under
  a blocking per-session lock (`Magento\Framework\Lock\LockManagerInterface`, name `novapay_pb_` +
  32 hex characters of the session id's sha256, 10 second wait), taken before the order is loaded
  and released in `finally`, so the second event sees the first one's invoice and becomes
  `postback.unchanged`. When the lock is not acquired in time nothing is applied and WARNING
  `postback.lock_timeout` is logged (session id, external id, source); NovaPay retries and the
  get-status cron (`Cron\SyncStatus`, same tail) apply it later. Unit tests.
- **A `voided` postback after a partial refund no longer closes the order.** NovaPay confirmed
  (2026-09-28) that after any partial refund of a single-operation session the operation and the
  session become `voided`, and the postback carries no refund amounts. The module treated that as a
  full refund: `sideeffect.voided_ledger_mismatch`, order closed as "Refunded via NovaPay". Now
  `StatusMapper` checks a `voided` event on an invoiced order that Magento's ledger does not show
  fully refunded: it reads the refunded amount from the event's `operations[]` or, when absent, from
  `POST /v1/get-status` (new `Model\Refund\NovaPayRefundState`). A partial refund keeps the order
  Paid via NovaPay (`novapay_status` unchanged, `novapay_last_event` = `voided`) and adds one admin
  comment "NovaPay confirmed a partial refund: %1 of %2 %3 refunded, the rest remains captured."
  (log `postback.partial_refund_confirmed`); a repeated `voided` with the same amount adds no second
  comment. A partial refund NovaPay reports without any credit memo in Magento keeps the order paid
  and asks for manual reconciliation (log WARNING `refund.partial_outside_magento`, no automatic
  credit memo). NovaPay reporting the whole amount refunded, or a ledger that is already fully
  refunded (no get-status call), still closes the order. When get-status fails the ledger decides
  (WARNING `refund.status_check_failed`). The refund form of such an order stays read-only with the
  "further refunds through NovaPay support" notice. en_US and uk_UA translations, unit and
  integration tests.
- **Refund form keeps the entered quantities after an error, and a full refund needs an explicit
  choice.** On stage (E2E 2026-09-27) NovaPay rejected a partial refund, the form reloaded with every
  "Qty to Refund" empty and the total back at the full amount, so a second click would have sent a
  full refund. `novapay/order/refundPost` now saves the submitted quantities and Return to Stock
  flags in the admin session, scoped to the order (`Model\Refund\FormDataStore`), and the form block
  reads and clears them, so the form comes back with the admin's values and the partial total. The
  form posts `refund_mode` (`partial` when a quantity is entered, otherwise `full`), and a full
  refund always asks "Refund the full order amount (%1)?" ("Повернути всю суму замовлення (%1)?").
  The controller treats "no quantity" as a full refund only with `refund_mode=full`, and rejects
  `partial` without any quantity (or `full` with quantities) with an error and no refund.
- **NovaPay operation ids are no longer masked in the log** (audit 2026-09-24 finding #6).
  `Gateway\Http\PanMasker::maskArray()` now checks the PAN keys (`pan`, `card_number`, `masked_pan`)
  first and masks the whole value regardless of spaces or dashes, and leaves the values of known id
  keys as they are (`id`, `transaction_id`, `session_id`, `external_id`, `payment_id`,
  `operation_id`, `rrn`, `approval_code`, `approval`, case-insensitive, at any depth, e.g.
  `operations[].id`). Every other string value and every non-JSON body still gets the 12-19 digit
  rule, `client_phone` masking is unchanged, and the order view card number still uses `mask()`.
- **The NovaPay refund button stays until the `voided` postback** (spec 8.4). The button used to
  disappear as soon as the credit memo existed. `RefundAvailability::getMode()` now shows it for
  `paid` / `hold_confirmed` regardless of `canCreditmemo()`, and for a released hold (`holded`,
  order canceled) until the `voided` postback changes the status. When nothing is left to refund
  the button opens the form read-only with a notice: "The refund was submitted, waiting for NovaPay
  confirmation." ("Повернення оформлено, очікується підтвердження NovaPay.") after a full refund,
  "The hold release was submitted, waiting for NovaPay confirmation." ("Зняття холду оформлено,
  очікується підтвердження NovaPay.") after a hold release, the NovaPay support text after a partial
  refund. The submit is disabled and a second cancel is not possible.
- **Config help text of the refund button option** now lists every native button it hides: Credit
  Memo (order view and invoice view), Void (order view) and Refund Offline (credit memo form).

- **The status sync no longer re-applies a status the order already has.** On stage, orders
  000000097 and 000000098 received `holded` by postback, and the next hourly `novapay_sync_status`
  poll got `holded` again from `/v1/get-status`. Its body differs from the postback, so the payload
  hash was new, and a same-to-same transition is allowed, so `StatusMapper::apply()` ran the side
  effect again and added a second status-history row and comment. Every poll of a waiting order
  would add another one. `StatusMapper::apply()` now treats an incoming status equal to the stored
  `novapay_status` as unchanged, unless the event is a partial void: no side effect, no state or
  status write, no history entry. It logs `postback.unchanged` (INFO, when logging is enabled, with
  order id, status and source) and records the payload hash through the new
  `OrderStatusApplier::recordPayloadHash()`, so a byte-identical repeat is caught as a duplicate.
  A status whose first application failed half-way is still applied later, because
  `novapay_status` is only saved at the end of a successful application. The history comment
  field "NovaPay time" is renamed to "session created" (en_US and uk_UA), because the get-status
  `created_at` is the session creation time, not the event time.
- **The `novapay_sync_status` cron no longer reverts the status it has just applied.** `Cron\SyncStatus`
  handed the `/v1/get-status` result to `PostbackProcessor::applyParsed()`, which loads its own copy
  of the order, applies the status (invoice, transactions, state/status, payment
  additional_information) and saves it. The cron then stamped `novapay_last_sync_at` on its own,
  older copy of the order from the stale-order query and saved that copy, overwriting everything the
  pipeline had just saved: state/status went back to pending, the payment lost `novapay_status`,
  RRN, masked PAN and the postback hash history, order totals went back to empty, while the invoice
  and capture transaction the pipeline had created stayed behind without a matching order. The stamp
  is now written on a fresh copy of the order read through the order repository after the pipeline
  has finished, including after an API error, so there is one save chain per order and a stale copy
  is never saved. Orders already affected keep their inconsistent data; the next cron run finds the
  invoice already there and only sets state/status again.
- **Two `cron:run` processes can no longer run the get-status sync at the same time.** On stage two
  processes executed the job in the same second, which the per-order one-hour cooldown cannot stop.
  `Cron\SyncStatus::execute()` now takes a non-blocking lock (`novapay_sync_status`, Magento
  `LockManagerInterface`) for the whole run, including the stale-order query. A process that does not
  get the lock logs `cron.sync_skipped_locked` (INFO, when logging is enabled) and does nothing. The
  lock is released in a `finally` block, also when the run throws.
- **A hold confirmed on NovaPay's side no longer leaves the authorization open.** When a `paid` or
  `hold_confirmed` postback arrived for a hold order with no pending invoice (the hold was
  confirmed at NovaPay, not through a Magento "Capture Online" invoice),
  `Model\OrderStatusApplier` created the paid offline-capture invoice but never closed the open
  authorization transaction recorded by the earlier `holded` postback. `Order::canVoidPayment()`
  therefore stayed true on fully captured (even later refunded) orders, the admin showed a Void
  button on them, and clicking it recorded a bogus void transaction and a "Voided authorization."
  comment. The authorization-closing logic now lives in one private helper,
  `closeAuthorization()`, used by the pending-invoice path and by both offline-capture fallbacks:
  it sets `is_closed = 1` and persists the authorization in the same DB transaction as the invoice
  and the order, logging `sideeffect.authorization_closed`. It is a no-op for direct-mode orders
  (no authorization) and for an already-closed authorization. Orders affected before this fix keep
  their open authorization row; this change only prevents new ones.
- **The uninvoiced quantity is canceled after a partial hold capture.** After an admin captured
  part of a hold ("Invoice" with fewer units, Capture Online), NovaPay captured that amount and
  released the rest to the customer, but the uninvoiced quantity stayed open: `canInvoice()` stayed
  true, the Invoice button remained, and the form for the remaining quantity only offered an
  offline capture, so an admin could record a payment that never happened. When the `paid` or
  `hold_confirmed` confirmation pays the pending invoice, `Model\OrderStatusApplier` now cancels the
  remaining quantity in the same DB transaction the way `Order::registerCancellation()` does, but
  only for item trees that still have quantity to invoice: `Order\Item::cancel()` per item (sets
  `qty_canceled`, dispatches `sales_order_item_cancel`, so stock is returned) and core's canceled
  totals formulas (subtotal, tax, shipping, discount and total, base and store currency). Shipping
  is canceled only if the partial invoice did not carry it. The whole order is never canceled.
  Result: the Invoice and Cancel buttons are gone, Ship stays available for the invoiced quantity,
  Credit Memo stays available and a refund of the captured amount is still a full refund for
  NovaPay, the totals footer shows "Total Canceled" instead of "Total Due", and the order closes
  after that refund. Idempotent via the `novapay_remainder_canceled` payment flag. Orders captured
  before this fix are not changed. The order comment now reads "... The uninvoiced quantity was
  canceled, so the remainder cannot be invoiced." (en_US and uk_UA updated).
- **Module-written order history comments use the admin default language (Stores → Configuration
  → General → Locale Options at Default Config) and carry the new status.** The partial-capture comment and the paid-amount-mismatch comment were rendered with
  `__()` inside the postback/cron request, so they were stored in the store view's locale, not the
  admin's. They were also stamped with the order's status at that moment (`payment_review`)
  instead of the paid status set right after. A new `Model\Order\AdminPhraseRenderer` renders
  both in the locale the backend uses for a user without a personal interface locale: the
  default-scope `general/locale/code`. When that differs from the current locale it switches the
  translator to it for the adminhtml area, renders the phrase and restores the previous locale in a
  `finally` block, the same mechanism as `Magento\Store\Model\App\Emulation`. The phrases stay in
  `__()` and the i18n CSVs. The comment is stamped with the status the order is about to get,
  without changing the order status early. Storefront-visible strings are unaffected.

### Known limitations

- Only one NovaPay refund (full or partial) is supported per order until NovaPay confirms the
  semantics of a second refund attempt on a session that already had a partial refund.
- NovaPay is UAH-only; the method is hidden on any store whose base currency is not UAH.
- Multi-store: store views that share one domain (no store code in the URL) cannot be told apart
  from a postback request, so the website's default store view supplies the public key / postback
  version used to verify and parse every postback that domain receives. Separate websites on
  separate domains can each use their own NovaPay account. A postback that resolves to one store
  but matches an order in another is applied only while both stores share a public key; if they
  differ it is refused and logged CRITICAL (`postback.store_key_mismatch_rejected`).
- Whether NovaPay reaches the Success/Fail return URLs by GET or POST has not yet been confirmed
  live; the controllers currently only accept GET.
- **Only postback version v3 is implemented.** The "Postback Version" field still offers v1/v2 for
  future compatibility, but selecting either of them (or leaving the NovaPay merchant account's own
  postback-version setting on v1/v2) means every incoming postback is dropped without being parsed
  — logged as a warning with `reason: "unimplemented_version"` (HTTP 200 is still returned to
  NovaPay). Merchants must set their NovaPay account's postback version to v3.
