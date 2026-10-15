# NovaPay Payment for Magento 2

`novapay/module-payment` (`NovaPay_Payment`) integrates [NovaPay](https://novapay.ua) internet
acquiring (Ukrainian PSP, part of the Nova Poshta group) into Magento 2 checkout. Customers pay by
card, Apple Pay, Google Pay or the NovaPay app on NovaPay's own hosted payment page; your store
never sees card data (SAQ-A scope). Order status changes are driven by signed server-to-server
**postbacks** from NovaPay — never by the browser redirect alone.

Two charge models, selected in configuration:

- **Direct** — funds are captured immediately when the customer pays.
- **Hold** — funds are blocked on the customer's card; you confirm the capture later (invoice,
  within 29 days), partially if you like. NovaPay auto-refunds any uncaptured remainder.

Читати українською: [`README_uk.md`](README_uk.md).

## Requirements

- Magento 2.4.8, patch level p1–p5 (Open Source or Adobe Commerce).
- PHP 8.3 or 8.4 (`composer.json`: `~8.3.0||~8.4.0`).
- `ext-openssl` and `ext-json` (both are part of a standard Magento 2.4.8 PHP install).
- A publicly reachable storefront (NovaPay must be able to POST to your postback URL — see
  [Postback URL setup](#postback-url-setup)).

## Installation

### Option A — Zip / manual `app/code` copy (recommended for now)

`novapay/module-payment` is **not yet published on Packagist** — this is the only installation
method that works today. See Option B below for the Composer path once the package ships.

1. Download or unzip the module source so that its contents (this `README.md`, `registration.php`,
   `composer.json`, `etc/`, `Gateway/`, `Model/`, …) land directly under:

   ```
   <magento-root>/app/code/NovaPay/Payment/
   ```

2. From the Magento root:

   ```bash
   bin/magento module:enable NovaPay_Payment
   bin/magento setup:upgrade
   bin/magento setup:di:compile
   bin/magento cache:flush
   ```

3. **Production mode only** — also (re-)deploy static content before serving live traffic, since
   `setup:di:compile` alone does not do this:

   ```bash
   bin/magento setup:static-content:deploy -f
   ```

### Option B — Composer (available once the package is published)

`composer require novapay/module-payment` will work once the package is published on Packagist.
**Until then**, this command has nothing to install against — Composer needs to be told where to
find the package via a [VCS or artifact repository](https://getcomposer.org/doc/05-repositories.md)
entry pointing at your own copy of this source. Add this to your Magento project's root
`composer.json` (substitute a real Git remote URL, or a path/zip you host, for `<repository-url>` —
this placeholder will not work as-is):

```jsonc
{
    "repositories": [
        {
            "type": "vcs",
            "url": "<repository-url>"
        }
    ]
}
```

Then, from the Magento root:

```bash
composer require novapay/module-payment
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

(and, in production mode, `bin/magento setup:static-content:deploy -f` as in Option A step 3).

Either way, after `setup:upgrade` data patches create six NovaPay order statuses
(`novapay_pending`, `novapay_holded`, `novapay_paid`, `novapay_expired`, `novapay_failed`,
`novapay_refunded`) and the "NovaPay" payment method appears — **disabled by default** — under
**Stores → Configuration → Sales → Payment Methods → NovaPay**.

## Configuration walkthrough

All fields live under **Stores → Configuration → Sales → Payment Methods → NovaPay**. Every field
can be scoped per website/store view like any standard Magento payment configuration, **including**
the **Public Key** and **Postback Version** used to verify and parse incoming postbacks: those two
are read for the store Magento resolves from the *postback request's own URL*, so a website with its
own domain can run its own NovaPay account. What that does **not** cover is several store views
sharing one domain without a store code in the URL — there the website's default store view (and
therefore the website-level configuration) applies to every postback that domain receives. See
[Multi-store](#faq) in the FAQ for the full rule.

| Field | What it does |
|---|---|
| **Enabled** | Turns the method on/off. The method also only appears in checkout when the cart's base currency is **UAH** (see [FAQ](#faq)). Those are the only two method-*visibility* gates — see [Troubleshooting](#troubleshooting) for what happens if the billing address has no phone number (it is not a visibility gate; the order still gets placed). |
| **Title** | Label shown to the customer in checkout. Default "NovaPay". |
| **Test Mode** | Yes = calls go to `https://api-qecom.novapay.ua` and the test payment frame (`qecom.novapay.ua`); No = the production endpoint (`https://api-ecom.novapay.ua` / `ecom.novapay.ua`). Always start with Test Mode = Yes. |
| **Merchant ID** | Your NovaPay merchant id. The published test id is `2` (test mode only — see [Test-mode guide](#test-mode-guide)). |
| **Private Key** | Your merchant RSA private key, used to sign every outgoing request (`x-sign` header). Stored **encrypted** (`Magento\Config\Model\Config\Backend\Encrypted`) — never displayed in full after saving, never logged. |
| **Public Key** | NovaPay's RSA public key, used to verify the signature on every incoming postback. The postback delivers its signature in a **version-specific header** — `x-sign-v2` for v3, `x-sign` for the legacy v1/v2 shapes — and the module picks the correct header automatically from the **Postback Version** setting (v3 confirmed against a live NovaPay postback). Test and production public keys differ — use the one matching Test Mode. Read at the scope of the store resolved from the postback request's URL — see the multi-store note above. |
| **Postback URL** | Read-only, nothing is saved. Shows the fully resolved postback URL for the scope you are editing: a store view shows its own secure base URL, a website shows its default store view, the default scope shows the default store view of the default website. Copy it into your NovaPay merchant admin panel. It is the same string the module sends automatically as `callback_url`. See [Postback URL setup](#postback-url-setup). |
| **Payment Type** | `Direct` (maps to Magento's `authorize_capture` — funds captured immediately) or `Hold` (maps to `authorize` — funds blocked, you confirm capture later; see [Hold-mode guide](#hold-mode-guide)). |
| **NovaPay refund button in order view** | Default **No**. When **Yes**, orders paid with NovaPay get one **NovaPay Refund** button in the order view instead of **Credit Memo** (order view and invoice view), **Void** (order view) and **Refund Offline** (credit memo form). On a hold the button releases the hold (a standard order cancel); on a paid order it opens a refund form and creates the standard online credit memo. Orders paid with other methods are not affected. See [NovaPay refund button (optional)](#novapay-refund-button-optional). |
| **NovaPay hold confirmation button in order view** | Default **No**. When **Yes**, a NovaPay order in hold gets a **Confirm NovaPay Hold** button in the order view, and the standard **Invoice** button is hidden for NovaPay orders. The button opens a form with the hold amount and an amount to confirm (a smaller amount is a partial confirmation and needs the **Recipient Identifier**); submitting it creates the standard online invoice. Orders paid with other methods are not affected. See [NovaPay hold confirmation button (optional)](#novapay-hold-confirmation-button-optional). |
| **Postback Version** | **Only `v3` (the default) is implemented.** The dropdown still offers `v1`/`v2` for future compatibility, but selecting either of them — or leaving your NovaPay merchant account's own postback-version setting on v1/v2 — means the module drops **every** incoming postback: nothing is parsed, no order is ever updated from a postback, and each drop logs a warning with `reason: "unimplemented_version"` (HTTP 200 is still returned to NovaPay). Set your NovaPay merchant account's postback version to **v3** and leave this field at its default. Read at the scope of the store resolved from the postback request's URL — see the multi-store note above. |
| **Success URL** | Optional. Leave empty to use Magento's own `checkout/onepage/success` page after payment. **Unresolved:** this route only accepts GET; see [Troubleshooting](#troubleshooting). |
| **Fail URL** | Optional. Leave empty to restore the quote and return the customer to the cart with an error message. Same GET-only caveat as Success URL. |
| **Success Redirect Timeout (sec)** | Default 5. Seconds after which NovaPay's result page sends the customer to the Success URL automatically. 0 turns the automatic redirect off, and the customer clicks the button on NovaPay's page instead. Sent to NovaPay as `success_redirect_timeout` only when greater than 0. |
| **Recipient Identifier (ЄДРПОУ/ІПН)** | Optional, but **required for a partial hold capture** (see [Hold-mode guide](#hold-mode-guide)). One value per store scope. |
| **Enable Logging** | Yes/No, default No. Logs every API request/response and every postback to `var/log/novapay.log`. **Critical errors are always logged even when this is off** — see [Logging](#logging). |
| **Convert Phone to +380 Format** | Yes/No, default Yes. Rewrites a billing address phone entered as `0XXXXXXXXX` or `380XXXXXXXXX` to `+380XXXXXXXXX` before it is sent to NovaPay. Independent of **Validate Phone Number** below — turning this off just stops the rewrite; the mask check (if enabled) still applies to whatever was entered. Turn off only if your store already stores every phone in `+380XXXXXXXXX` form. |
| **Validate Phone Number (+380 mask)** | Yes/No, default Yes. Rejects checkout (with the message below) when either the shipping address phone (skipped for virtual orders — there is no shipping step) or the billing address phone doesn't match the Ukrainian +380 mask. An invalid shipping phone returns the customer to the shipping step; an invalid billing phone keeps them on the payment step, since that's where the billing form lives in Luma. Only the billing phone is ever sent to NovaPay — the shipping phone is checked for correctness but never transmitted. Independent of **Convert Phone to +380 Format** above — this only controls rejection of an unparseable phone, never whether a parseable one gets rewritten. |
| **Phone Validation Error Message** | Shown to the customer when phone validation (above) rejects the number. |
| **NovaPay Order Status Mapping** | See below — its own config group. |
| **Sort Order** | Where "NovaPay" appears among other payment methods in checkout. |

The comment above the field list points to the read-only **Postback URL** field, which shows the
exact postback URL for the scope being edited; see [Postback URL setup](#postback-url-setup).

### The status-mapping group

**Stores → Configuration → Sales → Payment Methods → NovaPay → NovaPay Order Status Mapping** has
one dropdown per NovaPay session status (`created`, `processing`, `holded`, `hold_confirmed`,
`paid`, `failed`, `voided`, `expired`). The shipped defaults:

| NovaPay session status | Magento order state (fixed) | Default status |
| --- | --- | --- |
| `created` | `new` | Pending NovaPay Payment (`novapay_pending`) |
| `processing` | `pending_payment` | Pending Payment (`pending_payment`) |
| `holded` | `processing` | NovaPay Hold Authorized (`novapay_holded`) |
| `hold_confirmed` | `processing` | Paid via NovaPay (`novapay_paid`) |
| `paid` | `processing` | Paid via NovaPay (`novapay_paid`) |
| `failed` | `pending_payment` | NovaPay Payment Failed (`novapay_failed`) |
| `voided` | `closed` | Refunded via NovaPay (`novapay_refunded`) |
| `voided` after a partial refund | unchanged (`processing`) | order stays Paid via NovaPay (`novapay_paid`) |
| `expired` | `canceled` | NovaPay Session Expired (`novapay_expired`) |

`failed` and `voided` use the module's own statuses rather than core Pending Payment / Closed, so a
failed NovaPay payment or a NovaPay refund is distinguishable from other orders awaiting payment or a
manual close.

A failed payment does **not** cancel the order. NovaPay lets the customer retry on the payment page
within the same session, so after `failed` the order stays in `pending_payment` (stock stays reserved)
and a later `processing`, `holded`, `paid` or `hold_confirmed` for that session is applied normally;
`expired` cancels it. A late `failed` from the earlier attempt that arrives after `holded` is ignored
(WARNING `stale_failed_after_hold`), so a held order never drops back to awaiting payment.

Core's pending-payment cleanup cron only cancels orders whose *status* is `pending_payment`, so it
skips NovaPay Payment Failed orders. The module's get-status cron (every 15 minutes) does it instead:
when NovaPay still reports `failed` and the order is older than **Pending Payment Order Lifetime**
(`sales/orders/delete_pending_after`, default 480 minutes, measured from order creation), the order
is canceled, its stock released, the status stays NovaPay Payment Failed (now in state `canceled`)
and an admin-only history comment is added (`cron.failed_order_expired` in the log).

A canceled or closed Magento order is never reopened by a postback, whatever NovaPay status it
had stored: only `expired` and `voided` are still applied to it. A later `holded`, `paid` or
`hold_confirmed` is not applied: the order stays canceled and a CRITICAL `money_taking_resurrection`
line is logged for manual reconciliation; other statuses are logged as WARNING `order_closed`. Orders canceled by a `failed` postback before this change
keep their canceled state; `novapay_failed` stays assigned to the `canceled` state for them, and the
data patch `AssignFailedStatusToPendingPaymentState` also assigns it to `pending_payment`. A Failed row
saved with a canceled-state status (for example core Canceled) now falls back to `novapay_failed`
with a `config.status_label_invalid` warning; re-select a Pending Payment status there.

On upgrade, the data patch `MigrateFailedAndVoidedStatusDefaults` switches a
default-scope Failed value of Canceled and a Voided value of Closed, the old shipped defaults, to the
new statuses. Any other saved value, and any website or store-view override, is left unchanged.

Each dropdown lists only the statuses assigned to that row's fixed state (see **Stores → Settings →
Order Status** to assign a custom status to a state).

Things to understand:

- **Only the human-facing *label* is configurable, not the underlying order *state*.** For example
  the `paid` row must resolve to Magento's `processing` state — you can rename the status label
  shown to the merchant (or point it at a different `processing`-state status you've created), but
  you cannot make `paid` resolve to `complete` or `canceled`. This is deliberate: the state model
  (which statuses allow invoicing, which are terminal, etc.) is fixed by the NovaPay integration
  protocol, not a per-merchant preference. If a saved label doesn't belong to the required state,
  the module logs a warning and falls back to the shipped default label for that state — it never
  silently applies an invalid state.
- Two NovaPay session statuses are **transient** and never appear in this list because they never
  change order status either way: `processing_hold_completion` and `processing_void`. A postback
  carrying one of these is logged and ignored (HTTP 200, no state change) — this is expected, not
  a missed postback.
- A **partial** void/refund postback does not change order status at all (regardless of mapping);
  it only records the refunded amount against the order. Status only moves to the `voided` mapping
  once NovaPay reports the **entire session** refunded.
- **Every applied NovaPay event is written to the order history.** Each postback that changes the
  order, and each get-status sync by the cron, adds one admin-only comment (not visible to the
  customer, customer not notified), for example "NovaPay: status paid received (postback). Amount
  39.00 UAH, RRN 9770660, paytype card, session created 2026-08-03T06:19:11.954+00:00." The source
  is `postback` or `get-status`, and a value the event did not carry is shown as `-`. "Session
  created" is the `created_at` value NovaPay sends, which is when the payment session was created,
  not when the event happened. Duplicates, repeats of the status the order already has, transient
  statuses and blocked transitions add no comment.
- **Invoice, Ship and Hold are hidden on NovaPay orders that still await payment.** Until NovaPay
  reports the money as held or taken (`holded`, `hold_confirmed` or `paid`), the order view does not
  offer these three buttons, so nobody can record an offline invoice for money that never arrived.
  Cancel stays available. Orders paid with other methods are not affected.
- **Pending NovaPay Payment (`novapay_pending`) belongs to state `new` only.** Earlier versions also
  assigned it to `pending_payment`, so Stores > Settings > Order Status listed it twice. The data
  patch `UnassignPendingStatusFromPendingPaymentState` removes that second assignment on upgrade.
  If the Processing row was saved as Pending NovaPay Payment, it is no longer valid for that row:
  the module falls back to Pending Payment and logs a `config.status_label_invalid` warning.
  Re-select Pending Payment for that row to silence it.

### Key generation

Generate your merchant RSA key pair (and register the public key with NovaPay) following NovaPay's
own integration manual:
<https://nova-pay.atlassian.net/wiki/spaces/EXT/pages/694779954>

Paste the resulting **private** key into the module's Private Key field and the **NovaPay-provided
public** key (not your own) into the Public Key field — they are two different key pairs (yours
signs outgoing requests, NovaPay's verifies incoming postbacks).

### Postback URL setup

The module's postback endpoint is a fixed route on the store view's secure base URL, for example
`https://shop.example.com/novapay/postback/index/`. You do not need to build it yourself: the
read-only **Postback URL** field in the NovaPay configuration section (right below **Public Key**)
shows the fully resolved URL for the scope you are editing. Switch the scope selector to a store
view to see that store view's URL; the default scope shows the default store view of the default
website, a website scope shows its default store view.

Copy the value from that field and register it in your NovaPay merchant admin panel (Acquiring3).
It is identical to the `callback_url` the module sends automatically on every `/v1/session` call, so
you do not need to type it into the module. The field is display-only and nothing is saved.

**This module implements only postback version v3.** In your NovaPay merchant admin panel, set the
account's postback-version setting to **v3** — do not leave it on v1 or v2. A v1/v2 postback-version
setting (on either side) means the module drops every postback it receives without processing it;
see the **Postback Version** row above and [Troubleshooting](#troubleshooting).

The endpoint must be reachable over the public internet directly (no VPN/basic-auth in front of it)
— NovaPay calls it server-to-server, not through the customer's browser. During local development,
use a tunnel (ngrok, Cloudflare Tunnel, `ddev share`, etc.) to expose it.

## Test-mode guide

1. Set **Test Mode = Yes**.
2. Set **Merchant ID = 2** — NovaPay's published shared test merchant.
3. Set **Private Key** / **Public Key** to the test key pair published on NovaPay's Authentication
   page: <https://novapay.readme.io/reference/authentication>. (Do not use these values with Test
   Mode = No — the release checklist explicitly rejects merchant id `2` and any published test key
   for a production configuration.)
4. Place an order with "NovaPay" as the payment method and pay on the resulting NovaPay test frame
   using one of the published test cards: <https://novapay.readme.io/reference/testing-data>.
5. Watch `var/log/novapay.log` (see [Logging](#logging)) for the outgoing `/v1/session` and
   `/v1/payment` calls and the resulting postback.

Test mode uses `https://api-qecom.novapay.ua` and the `qecom.novapay.ua` payment frame; nothing you
do in test mode reaches real card networks or the production NovaPay environment.

## Hold-mode guide

With **Payment Type = Hold**:

1. The order is placed and stays pending until the customer pays on the NovaPay frame.
2. A `holded` postback moves the order to the **NovaPay Hold Authorized** status (state
   `processing`) — funds are blocked on the customer's card, not yet captured.
3. From the order view, create an **Invoice** with capture case **Capture Online**:
   - Full amount → NovaPay captures the entire hold.
   - Reduced amount (partial capture) → requires **Recipient Identifier (ЄДРПОУ/ІПН)** to be
     configured; NovaPay captures the reduced amount and auto-refunds the remainder. When the
     capture is confirmed, the module cancels the uninvoiced quantity natively (items show it as
     Canceled, the totals show Total Canceled, stock is returned), so the **Invoice** button
     disappears and nobody can record a payment for the released amount. One order comment
     with the captured and released amounts is added under the paid status, in the admin
     default language (Stores → Configuration → General → Locale Options at Default Config). A later full
     refund of the captured amount closes the order.
   - The capture must happen **within 29 days** of the hold (NovaPay expires uncaptured holds after
     that; the module also enforces this client-side and refuses a capture attempt past day 29).
   - Only **one** complete-hold call is ever made per order — NovaPay's own semantics settle the
     whole hold (full or partial) in that single call, so there is no second "top-up" capture.
4. **After the invoice is created, the order sits in the native Magento `payment_review` state
   until the `hold_confirmed` postback arrives.** This is expected NovaPay behavior, not a bug or a
   stuck order — the invoice is deliberately created "pending" (not yet marked paid) and only
   flips to paid when NovaPay confirms the capture server-to-server. If a `hold_confirmed` postback
   never arrives, the 15-minute `novapay_sync_status` cron (`POST /v1/get-status`) will pick it up.
5. To cancel an uncaptured hold instead of capturing it, use the order's **Cancel** (or **Void**)
   action. This maps to `POST /v1/void` and cancels the whole hold — **partial hold cancellation is
   not supported** and is not offered.
6. **Magento's Hold / Unhold buttons are not the NovaPay hold.** They are Magento's own order-level
   hold (state `holded`), send nothing to NovaPay and only block other actions on the order. See the
   step-by-step admin guide with a "which button does what" table: `docs/admin-hold-operations.md`.

## Refunds

Refunds go through NovaPay's void endpoint (`POST /v1/void`), mapped to Magento's native **online
credit memo**. This is a *different* native operation from the hold-cancellation Void action
described in [Hold-mode guide](#hold-mode-guide) step 5 above — both happen to call the same
NovaPay endpoint, but a credit memo only exists once an order has been invoiced/paid; canceling an
uncaptured hold has no invoice to refund yet and uses the order's **Void** action instead, never a
credit memo.

Credit-memo refund rules:

- **Full refund** (all items, on a `paid` or hold-confirmed order) — supported.
- **Partial refund** (per order line) — supported, but see the limitation below. Partial refunds do
  **not** change the order's NovaPay status; the order stays paid, the refunded amount is recorded
  and shown per line on the order view, and the credit memo action remains available until the
  order is fully refunded via NovaPay, though only **one** online NovaPay refund is accepted in
  total per order (see the limitation below). NovaPay sends `voided` after a partial refund too;
  the module then asks `/v1/get-status` how much was refunded, keeps the order Paid via NovaPay and
  adds the history comment "NovaPay confirmed a partial refund: ... refunded, the rest remains
  captured." A partial refund made in NovaPay without a credit memo gets a "please reconcile
  manually" comment instead.
- Shipping can only be refunded as part of a **full** refund.
- Adjustment fees/refunds on the credit memo are **not supported** and are blocked.
- The credit memo page shows a banner: *"Online refunds (full or partial) through NovaPay are
  available only for orders paid with NovaPay"* — a reminder that offline refunds (see below)
  don't touch NovaPay at all.

**Limitation — one NovaPay refund per order.** This integration only ever records one NovaPay
operation id per order (the numeric id returned by `/v1/payment`). It is not documented whether a
bare `POST /v1/void` (no `operations[]`, the "full refund" shape) issued **after** an earlier
partial refund on that same session (a) refunds only the remainder, (b) refunds the full original
amount again — double-refunding the part already returned — or (c) is rejected outright. Until
NovaPay confirms which of (a)/(b)/(c) applies, this module allows only **one** NovaPay refund
(full or partial) per order rather than guessing. A second attempt is refused with a clear
admin message; contact NovaPay support to refund the remainder if you hit this. This is a
deliberate safety limit, not a missing feature.

**Offline refunds do not reach NovaPay.** If you issue a credit memo with "Refund Offline" instead
of "Refund", Magento adjusts its own books but no request is sent to NovaPay — funds are not
actually returned at NovaPay's side. Use the online refund whenever the order was genuinely paid
through NovaPay.

## NovaPay refund button (optional)

NovaPay's specification asks for a single **Refund** button in the order view instead of Magento's
credit memo screens. The module offers it as an option: **NovaPay refund button in order view**
(`payment/novapay/refund_flow`, store view scope, default **No**). With **No** nothing changes and
the native flow above is used.

With **Yes**, for orders paid with NovaPay:

- The order view shows a **NovaPay Refund** button according to the NovaPay status: on a hold
  (`holded`) and on a paid order (`paid`, `hold_confirmed`). It is hidden for created, processing,
  failed, expired and voided orders. After a full refund or a hold release the button stays until
  the `voided` postback arrives and opens the form read-only with the notice "The refund was
  submitted, waiting for NovaPay confirmation." (or "The hold release was submitted, ..."), with
  the submit disabled.
- **Credit Memo** (order view and invoice view), **Void** (order view) and **Refund Offline**
  (credit memo form) are hidden. **Cancel** stays: on a hold it does the same as the button.
- **Hold:** the button asks for confirmation and cancels the order through Magento's standard order
  cancel. NovaPay receives `POST /v1/void` without `operations[]`, the whole hold is released
  (a partial hold cancel is not supported).
- **Paid order:** the button opens a refund form with one row per paid line: product, SKU, paid
  amount per item, paid and refunded quantity, **Return to Stock**, **Qty to Refund** and the
  **Amount to Refund** of the line. Below are the **Total Amount to Refund**, the banner "A partial
  refund is possible if the order was paid to a NovaPay account" and the **Refund via NovaPay**
  button. Leave every quantity empty for a full refund (all remaining items and shipping), the
  button then asks "Refund the full order amount (...)?" first. Enter quantities for a partial
  refund (shipping is not refunded then).
- On submit the module builds the credit memo exactly like the native credit memo form (core
  credit memo loader, same stock and totals logic) and refunds it online, so the same
  `POST /v1/void` request and the same refund rules as above apply. The amount refunded is the
  credit memo total calculated by Magento.
- Any error, for example a NovaPay rejection, is shown on the form and nothing is saved: no credit
  memo, no change to the order. The form keeps the entered quantities, so submitting again sends
  the same partial refund, never a full one.
- When a refund is not possible the form says so before you submit: after an earlier refund (one
  NovaPay refund per order, see the limitation above) the submit button is disabled; on a partially
  captured payment the quantity inputs are disabled and only a full refund is offered.

Permissions: the refund form needs the same ACL resource as the native credit memo
(`Magento_Sales::creditmemo`), releasing a hold the same as the native Cancel
(`Magento_Sales::cancel`). Screenshots and details: `docs/admin-online-refund.md`.

## NovaPay hold confirmation button (optional)

NovaPay's specification asks for a separate **Confirm hold** button with an amount field in the
order view. The module offers it as an option: **NovaPay hold confirmation button in order view**
(`payment/novapay/hold_confirm_flow`, store view scope, default **No**). With **No** nothing changes
and the hold is confirmed with a standard invoice (see [Hold-mode guide](#hold-mode-guide)).

With **Yes**, for orders paid with NovaPay:

- The standard **Invoice** button is hidden in the order view.
- The order view shows **Confirm NovaPay Hold** while the NovaPay status is `holded`, the order can
  still be invoiced and the hold is at most 29 days old. It is hidden in every other status and for
  an older hold.
- The button opens a form with the **Hold Amount**, the **Recipient Identifier** status and
  **Amount to Confirm**, prefilled with the full hold amount (base currency, 2 decimals). The amount
  must be greater than 0 and not above the hold amount (checked in the browser and on the server).
  A smaller amount is a partial confirmation: NovaPay returns the difference to the payer
  automatically. A partial confirmation needs the **Recipient Identifier (ЄДРПОУ/ІПН)**; without it
  the form shows a warning and only the full amount is accepted.
- **Confirm Hold** creates the standard online invoice (the same as **Invoice** with
  **Capture Online**), so the module sends `POST /v1/complete-hold`: without an amount for the full
  hold, with `operations[{id, amount, recipient_identifier}]` for a partial one. The invoice stays
  pending and the order is in **Payment Review** until NovaPay's postback. Meanwhile the button
  opens the form read-only with the notice "The hold confirmation was submitted, waiting for NovaPay
  confirmation." After the `paid` / `hold_confirmed` postback the button is gone.
- **Partial amounts and invoices.** Magento invoices whole item quantities, and after NovaPay's
  postback the module expects the invoice total to equal the captured amount (the released
  remainder is then canceled). So a partial amount must match whole items plus the shipping (the
  first invoice always carries the full shipping). The module searches every combination of whole
  units of the order lines for one that matches the amount exactly (preferring the earliest lines).
  If one matches, it is sent as is. If not, nothing is sent: the form comes back with the nearest
  lower amount that matches and also names the nearest higher one (below the full hold), for
  example "... so 60.00 cannot be confirmed exactly. The nearest lower amount that covers whole
  items together with the shipping is 49.00 (1 x Tee), the nearest higher one is 69.00 (1 x Tee,
  1 x Bag) ...", and you confirm one of them with a second click. An amount below the shipping
  plus the cheapest item is rejected, naming the smallest amount that works. Very large amounts
  (above 1,000,000 price steps, 10,000.00 when prices differ in single cents) fall back to taking whole units of the lines in order.
- Any error, for example a NovaPay rejection, is shown on the form and nothing is saved: no invoice,
  no change to the order. The form keeps the entered amount.

Permissions: the form needs the same ACL resource as the native Invoice button
(`Magento_Sales::invoice`). Both optional buttons (refund and hold confirmation) can be on at the
same time. Screenshots and details: `docs/admin-hold-operations.md`.

## Logging

With **Enable Logging = Yes** (default No; turn it on while setting up or troubleshooting), every outgoing API request/response and every incoming
postback (valid or invalid) is logged as one JSON object per line to:

```
var/log/novapay.log
```

Every postback is recorded twice: once as it arrives (`"event":"postback.received"`, carrying the
payload itself plus `body_sha256`, `body_bytes`, `signature_present` and `postback_version`), and
once for its outcome (`postback.accepted`, `postback.duplicate`, `postback.invalid_signature`,
`postback.malformed`, `postback.unknown_session`, `postback.stale_session`,
`postback.blocked_transition`, `postback.store_mismatch`, `postback.store_key_mismatch_rejected`).
The arrival record is written *before* the signature is checked, so the body behind a rejected
postback is on record too — that is what makes an invalid-signature report diagnosable.

PAN is always masked (e.g. `123456******1234`) — full card numbers are never written to this file
regardless of the logging setting, including in the recorded postback body. The customer phone is
masked too, and the private key is never logged.

Two things worth knowing before you go live:

- **This file grows quickly and needs rotation.** Since every request/response/postback is logged
  as a full JSON line, `var/log/novapay.log` grows quickly on a busy store. It sits directly in
  `var/log/`, so a logrotate config (or a hosting provider's stock one) that globs `var/log/*.log`
  already covers it; if yours lists files explicitly, add `var/log/novapay.log`. Admin log viewer
  extensions that list only top-level `var/log` files show it as well.
- **Critical NovaPay errors (e.g. a signed postback arriving for an order that was already
  canceled) are written only to this dedicated log**, not to Magento's own `system.log` or
  `exception.log`. If your monitoring/alerting watches the standard Magento logs, it will miss
  these — point a monitoring rule at `var/log/novapay.log` (e.g. alert on any JSON line with
  `"level_name":"CRITICAL"`) in addition to your usual log monitoring.
- Disabling logging still writes critical errors to this same file — "Enable Logging = No" only
  suppresses the routine request/response/postback lines.

## Troubleshooting

**A failed or unpaid order was canceled, and later `var/log/novapay.log` shows a CRITICAL
`money_taking_resurrection`.**
Canceling an order in Magento (admin **Cancel** on a NovaPay Payment Failed or pending order, or the
automatic cancel after the pending payment lifetime) does not close the NovaPay session: the customer
can still pay on the NovaPay page until the session expires. The module then refuses to reopen the
canceled order and logs this CRITICAL line instead. Treat it as a reconciliation signal: check the
session in the NovaPay merchant cabinet. A hold (`holded`) can be released there; a direct payment
(`paid` / `hold_confirmed`) has to be refunded manually in the NovaPay cabinet, or the customer
contacted, because the canceled Magento order cannot be invoiced or refunded online.

**NovaPay isn't showing up as a payment method in checkout.**
Check: (1) Enabled = Yes at the applicable scope; (2) the cart's/store's base currency is UAH —
NovaPay is UAH-only in this module (see [FAQ](#faq)). **Those are the only two method-visibility
gates.** In particular, a missing phone number on the billing address does *not* hide the method —
see the next item for what actually happens in that case.

**Order placed, but the customer is bounced back to the cart with an error, and an orphaned
"Pending NovaPay Payment" order is left behind.**
This is what happens when the billing address has no phone number — but under Magento's **default**
address configuration (**Stores → Configuration → Customers → Customer Configuration → Name and
Address Options → Show Telephone**, `customer/address/telephone_show`, default `Required`), the
storefront checkout form itself blocks progression client-side ("This field is required.") the moment
the phone field is left empty, so an order can never actually be placed without a phone number in the
first place — this scenario is **not reachable through a default-configured Luma checkout**. It only
becomes reachable if that setting has been changed to `Optional` or `Hide` at the applicable scope
(a native Magento setting, unrelated to NovaPay), or an order is placed through a path that bypasses
the storefront form's own validation entirely (a REST/GraphQL order placement, admin order creation,
or a customer whose saved default address predates a `telephone_show` config change). In any of those
cases: order placement itself does **not** check for a phone number, so the order **is placed**
exactly like any other NovaPay order (status `novapay_pending`); only the very next step — building
the NovaPay session, on the redirect controller right after placement — discovers that `client_phone`
(required by `/v1/session`) is missing and throws before any NovaPay API call is made. The customer
sees "A phone number on the billing address is required to pay with NovaPay." and is sent back to the
cart; the order is left untouched. That order never obtained a NovaPay session id, so the
`novapay_sync_status` cron has nothing to poll and will not resolve it — it stays an orphaned pending
order until either you cancel it by hand or Magento's own native pending-order cleanup does
(**Stores → Configuration → Sales → Order Cleanup → Pending Order Lifetime (minutes)**, if you have
that configured — it is a core Magento feature, unrelated to NovaPay). Ask the customer to add a
phone number and re-order.

**The customer places the order but the redirect to NovaPay fails / errors out.**
Almost always a key/config problem: confirm Merchant ID, Private Key and Test Mode are all
consistent with each other (a live merchant id with test keys, or vice versa, will be rejected by
NovaPay). Check `var/log/novapay.log` for the actual `/v1/session` or `/v1/payment`
response — NovaPay's error code (e.g. `InvalidSignError`, `SystemError`) is logged and also mapped
to a friendly message.

**Postbacks never arrive / orders stay stuck in "Pending NovaPay Payment".**
Check: (1) the postback URL is registered in the NovaPay merchant admin panel as
exactly the value shown in the read-only **Postback URL** field for the store view that takes the
orders, and that value uses your real, public store domain, see
[Postback URL setup](#postback-url-setup); (2) the URL is actually reachable from the public
internet (no VPN, IP allowlist, or HTTP basic auth in front of the storefront); (3) **only postback
version v3 is implemented** — if your NovaPay merchant account's postback-version setting is v1 or
v2, or if the module's own **Postback Version** field has been changed away from its v3 default,
**every** postback is dropped without being parsed, logged as a warning with
`reason: "unimplemented_version"` (HTTP 200 is still returned to NovaPay, per NovaPay's own
requirement, so NovaPay won't retry more aggressively — check the log, not the HTTP response, to
diagnose this). Set both sides to v3. As a fallback, the `novapay_sync_status` cron job polls
`/v1/get-status` every 15 minutes for orders stuck in a pending/holded status **that already have a
NovaPay session id** — confirm Magento cron is actually running (`bin/magento cron:run` / the cron
table) if even that doesn't resolve a stuck order. Each run writes one `cron.sync_summary` line to
`var/log/novapay.log` when logging is enabled; a `cron.sync_skipped_locked` line only means a second
cron process started while a run was still in progress and stepped aside, which is expected.

**Repeated history entries from the status sync.**
The `novapay_sync_status` cron polls orders that still wait for NovaPay, and a poll often returns
the status the order already has (for example `holded` while the hold is not yet confirmed). Such a
repeat changes nothing: no history comment, no status change, and `var/log/novapay.log` shows a
`postback.unchanged` line with the order, status and source. If you see a second "NovaPay: status
... received" comment with the same status, the module version predates this fix. Update it; the
existing duplicate comments are harmless and can stay.

**Postback signature verification keeps failing.**
The **Public Key** field must be set to **NovaPay's** public key (not your own), and must match
Test Mode (test and production public keys are different values). The signature also arrives in a
**version-specific header** — v3 postbacks are signed in `x-sign-v2`, the legacy v1/v2 shapes in
`x-sign` — and the module reads whichever header matches the **Postback Version** setting, so that
field must agree with your NovaPay account's postback version (for v3, the default, the module reads
`x-sign-v2`). Every rejected signature is logged with enough detail to see this was the case; a
wrong-but-well-formed key produces a clean "invalid signature" log line, never a PHP error.

**Customer gets a 404 on the way back from NovaPay (success or fail return).**
`novapay/success/index` and `novapay/fail/index` are implemented as GET-only routes. Whether
NovaPay's browser redirect back to your store uses GET or POST has **not yet been confirmed live**
— if it turns out to be POST, the customer will 404 on this step. This is an unresolved item (see
`CHANGELOG.md` "Known limitations") that must be verified — with NovaPay support, or a live tunnel
test — before go-live.

## FAQ

**Why does NovaPay only work on UAH stores?**
NovaPay is a Ukrainian acquirer and settles in UAH. This module hides the method on any store whose
base currency isn't UAH, the same way core Magento payment methods hide themselves for unsupported
currencies — no separate configuration switch is needed.

**Can I use different NovaPay credentials per store view?**
Yes for separate **websites with their own domain**; with a caveat for store views that share a
domain. (Before this was made explicit, these two fields were read without a store id, which
Magento already resolves as the *current* store — so a normal web postback behaved the same way;
what changed is that the scope is now explicit, correct for CLI/cron, and covered by tests.)

Every configuration field, including Merchant ID and the keys, accepts a per-website/store-view
value like any standard Magento payment configuration, so the outgoing `/v1/session`/`/v1/payment`
calls a given store makes already use that store's own Merchant ID/Private Key. The **Public Key**
and **Postback Version** used for *incoming* postbacks are read for the store Magento resolves from
the postback request's URL — the request's domain (and `___store` code, if any), never anything in
the payload, which is untrusted until the signature has verified. That resolution is trustworthy
because the module builds each session's `callback_url` from the **ordering store's own base URL**,
so a genuine postback always comes back to the store that started the payment.

What this means in practice:

- **Separate websites, each on its own domain** — fully supported. Each website can have its own
  NovaPay account: set Merchant ID, Private Key, Public Key and Postback Version at that website's
  scope.
- **Several store views sharing one domain** — the postback URL alone cannot tell them apart, so
  Magento resolves the domain's **default store view** for that website, and its configuration is
  what verification and parsing use. Either keep Public Key / Postback Version identical across
  those store views (set them at website scope), or switch on **Stores → Configuration → General →
  Web → Url Options → Add Store Code to Urls** so each store view's `callback_url` carries its own
  store code.
- Values you leave unset at store scope inherit from website scope, and website from default, as
  usual — configuring only what actually differs per website is enough.
- If a verified postback turns out to belong to an order from a *different* store than the request
  resolved to (a misconfigured base URL, for example), the module logs a `postback.store_mismatch`
  warning. It still applies the postback **as long as both stores are configured with the same
  Public Key** — the normal case, since one NovaPay account's key is usually set once and inherited.
  If the two stores have *different* Public Keys, the signature that was verified belongs to a
  different NovaPay account than the order's own store trusts, so the postback is **refused** and
  logged as CRITICAL (`postback.store_key_mismatch_rejected`); fix the base URLs (or add the store
  code to URLs) and NovaPay's delivery retry will then land on the right store.

See also the note at the top of [Configuration walkthrough](#configuration-walkthrough).

**Does this module include a NovaPay SDK?**
No. All signing (RSA-SHA256 over the exact JSON body sent, base64 into the `x-sign` header on
outgoing requests; incoming v3 postbacks are verified against the `x-sign-v2` header) and HTTP calls
are implemented directly against NovaPay's published REST API using Magento's own HTTP client, per
NovaPay's own API documentation: the full endpoint list, request/response field shapes, the
RSA-SHA256 signing scheme, and the postback payload schemas.

**What happens if `/v1/session` or `/v1/payment` fails when the customer places an order?**
The order is left in its pending state, the quote is restored, the customer sees an error message
in checkout, and the failure is logged — nothing is captured or canceled.

**Where is the NovaPay session id / status / RRN / approval code / masked PAN stored?**
Internally, in `sales_order_payment.additional_information` for the order's payment record (the
same standard Magento mechanism any payment method uses to persist gateway metadata). It is
rendered for admin users on the order view, in a dedicated "NovaPay" information block — session
id, session status, paytype, RRN, approval code, masked PAN, and any per-line refunded amounts.

**Can I install this as a Marketplace zip instead of via Composer?**
Yes — that is in fact the currently recommended way to install it, since the Composer package isn't
published yet; see [Installation, Option A](#installation). The zip's contents map directly onto
`app/code/NovaPay/Payment/`.
