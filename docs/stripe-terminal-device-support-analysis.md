# OpenEMR Stripe Terminal Integration — Analysis & Path to S700 / Verifone Smart Reader Support

**Date:** 2026-07-20
**Scope:** (§1–§9) Current in-person (card-present) Stripe Terminal implementation, and what must change to support the Stripe Reader S700/S710 and the current Verifone smart reader family. (§10) Upstream 2026 issue/PR review. (§11) UK deployment feasibility — GBP currency support and SCA compliance across both the patient portal and back-office payment paths.
**Baseline:** [openemr/openemr#4404](https://github.com/openemr/openemr/pull/4404) — "New Stripe In Person CC terminal payments", merged 2021-05-20 by Jerry Padgett.

> **Checkout note (correction to the first revision of this document):** Sections 2–6 were originally written against the local working copy, which is on tag **`v8_0_0_3`** (`rel-800`, 2026-03-25) — *not* `master`. All line numbers in those sections refer to that tag. Section 10 re-verifies every finding against `origin/master` as of 2026-07-20. **All substantive findings survive on master**; only line numbers and some crypto/globals API calls differ. Read §10 for the master-accurate picture.

---

## Contents

1. [Executive summary](#1-executive-summary)
2. [Current implementation](#2-current-implementation)
3. [Reader compatibility matrix](#3-reader-compatibility-matrix)
4. [Blocking defects for S700 / multi-reader deployment](#4-blocking-defects-for-s700--multi-reader-deployment)
5. [Missing capabilities the S700 specifically expects](#5-missing-capabilities-the-s700-specifically-expects)
6. [Additional issues found during review](#6-additional-issues-found-during-review)
7. [Two options](#7-two-options)
8. [Recommended sequencing](#8-recommended-sequencing)
9. [Open questions](#9-open-questions-for-you)
10. [Upstream activity review (2026 issues & PRs)](#10-upstream-activity-review-2026-issues--prs--and-what-it-changes)
11. [UK deployment feasibility — currency and regulatory audit](#11-uk-deployment-feasibility--currency-and-regulatory-audit)
12. [Decisions log (2026-07-20 review session)](#12-decisions-log-2026-07-20-review-session)

> **How to read this document:** §1–§9 are the original Stripe Terminal / S700 analysis. §10 confirms nothing has changed upstream and surfaces the `src/PaymentProcessing/` architecture we should follow. §11 is the UK/GBP feasibility audit (currency hardcoding + SCA compliance). §12 records the decisions taken in the 2026-07-20 review and the resulting consolidated build plan — **start there for the current plan of record.**

---

## 1. Executive summary

The headline finding is **not** what the framing of the request assumes.

There is **no device-model gating anywhere in the OpenEMR codebase**. The only mention of "P400" in the entire repo is a *label string* in `library/globals.inc.php`. The integration is generic Stripe Terminal JavaScript SDK code, and the JS SDK's `discoverReaders()` returns whatever internet readers are registered to the account — S700 included.

So the question is not "how do we add S700 support?" It is:

> **Why does the existing generic code fail in practice on modern readers and modern browsers — and is the JS SDK still the right architecture at all?**

Three findings drive the recommendation:

1. **The Verifone P400 named in the global reached Stripe end-of-life on 2024-10-01** and can no longer process transactions. The feature as documented is dead. ([Stripe support](https://support.stripe.com/questions/end-of-life-status-for-terminal-card-readers-and-sdks))
2. **Chrome 142 (2025-10-28) broke the JS SDK path.** The JS SDK reaches smart readers over the *local network*; Chrome now requires an explicit Local Network Access permission grant. OpenEMR opens the terminal UI inside a `dlgopen()` **iframe**, which additionally requires an `allow="local-network-access"` attribute that OpenEMR does not set. This is a hard functional break, not a warning.
3. **Stripe's server-driven integration** now covers exactly the reader set in question (S700, S710, WisePOS E, Verifone smart readers), needs no browser/LAN involvement, and is fully supported by the `stripe/stripe-php` v16.6.0 already vendored in this repo.

**Recommendation: migrate the card-present flow to the server-driven integration** rather than patching the JS SDK path. It is less code than the current implementation, removes the entire class of network/browser failures, and is the only path Stripe recommends for new smart-reader deployments.

---

## 2. Current implementation

### 2.1 Files

| File | Role |
|---|---|
| `library/globals.inc.php:3360` | `cc_stripe_terminal` bool global — "In person payments with Stripe Verifone P400" |
| `library/globals.inc.php:3366` | `payment_gateway` selector, includes `Stripe` |
| `library/globals.inc.php:3399` | `gateway_api_key` — encrypted Stripe secret key |
| `interface/patient_file/front_payment.php:1813-1853` | `posDialog()` — validates the payment form, then `dlgopen()`s the terminal modal passing `?total=` |
| `interface/patient_file/front_payment_terminal.php` | The entire reader UI + JS SDK driver (288 lines, all inline `<script>`) |
| `interface/patient_file/front_payment_cc.php:107-200` | Server endpoints: `terminal_token`, `terminal_create`, `terminal_capture`, `cancel_intent` |
| `src/Billing/PaymentGateway.php` | Omnipay wrapper — **card-not-present only**, not used by the terminal flow |

Note the terminal flow bypasses `PaymentGateway` entirely and talks to `Stripe\*` SDK classes directly.

### 2.2 Flow

```
front_payment.php  posDialog()
      │  dlgopen(front_payment_terminal.php?total=NN.NN)   ← iframe
      ▼
front_payment_terminal.php
      │  <script src="https://js.stripe.com/terminal/v1/">
      │  StripeTerminal.create({onFetchConnectionToken, onUnexpectedReaderDisconnect})
      │
      ├─► POST front_payment_cc.php?mode=terminal_token
      │        ConnectionToken::create()                    ← no location scope
      │
      │  discoverReaders({simulated: false})                ← no location filter
      │  connectReader(discoveredReaders[0])                ← auto-picks first
      │
      │  [user clicks "Collect Payment"]
      ├─► POST front_payment_cc.php?mode=terminal_create
      │        PaymentIntent::create(card_present, capture_method: manual)
      │  collectPaymentMethod(client_secret)
      │  processPayment(paymentIntent)                      → requires_capture
      │
      │  [user clicks "Post Payment"]
      └─► POST front_payment_cc.php?mode=terminal_capture
               $intent->capture()
               → writes intent id into opener's #check_number, submits the form
```

### 2.3 Library status

`stripe/stripe-php` is pinned at `^16.6.0` (locked v16.6.0), API version `2025-02-24.acacia`. This is **current enough** — it already ships:

- `Stripe\Terminal\Reader::processPaymentIntent()`, `cancelAction()`, `setReaderDisplay()`, `refundPayment()` (`vendor/stripe/stripe-php/lib/Service/Terminal/ReaderService.php`)
- `Stripe\Terminal\Configuration` (reader fleet configuration)
- `device_type` enum in `Terminal/Reader.php:16` already includes `stripe_s700`, `bbpos_wisepos_e`, `simulated_wisepos_e`

**No composer upgrade is required to support the S700.** The newer Verifone models (V660p, UX700, P630, M425) are absent from that docblock enum, but the enum is documentation only — it does not constrain runtime behavior. A minor version bump is still worth doing for docblock accuracy.

---

## 3. Reader compatibility matrix

| Reader | Category | JS SDK | Server-driven | Status |
|---|---|---|---|---|
| Verifone P400 | smart | ✅ | ❌ | **EOL 2024-10-01 — cannot transact** |
| BBPOS WisePOS E | smart | ✅ | ✅ | current |
| **Stripe Reader S700** | smart | ✅ | ✅ | current |
| **Stripe Reader S710** | smart (+4G/LTE) | ✅ | ✅ | current |
| Verifone V660p | smart | ✅ | ✅ | current |
| Verifone UX700 / P630 / M425 | smart | ✅ | ❌ | current |
| Stripe Reader M2 | mobile (BT/USB) | ❌ | ❌ | iOS/Android SDK only |
| BBPOS WisePad 3 | mobile (BT/USB) | ❌ | ❌ | iOS/Android SDK only |
| Tap to Pay | software | ❌ | ❌ | iOS/Android SDK only |

Sources: [Stripe Terminal readers](https://docs.stripe.com/terminal/readers), [server-driven integration](https://docs.stripe.com/terminal/payments/setup-integration?terminal-sdk-platform=server-driven).

**Implication:** mobile readers (M2, WisePad 3) and Tap to Pay are **out of reach for any browser-based OpenEMR integration**. They require a native mobile app. Scope the work to smart readers only and say so in the global's description text.

---

## 4. Blocking defects for S700 / multi-reader deployment

### B1 — Chrome 142+ Local Network Access breaks the JS SDK path *(critical)*

The JS SDK discovers readers via the Stripe API but **connects to smart readers over the local network**. Chrome 142+ requires an explicit permission grant; without it the connection fails with:

```
blocked by CORS policy: Permission was denied for this request
to access the 'unknown' address space
```

Requirements to keep the JS SDK working:
- Site must be served over **HTTPS** (many OpenEMR installs are plain HTTP on a LAN)
- User must click "Allow" on a Chrome permission prompt, per domain
- **The iframe must carry `allow="local-network-access"`** — `front_payment_terminal.php` is loaded via `dlgopen()`, which builds an iframe, and no such attribute is set anywhere
- Enterprise deployments can pre-grant via the `LocalNetworkAccessAllowedForUrls` Chrome policy

([Stripe guidance](https://support.stripe.com/questions/ensuring-stripe-terminal-javascript-sdk-functionality-on-chrome-142))

This alone means the current feature is broken on any up-to-date Chrome, independent of reader model.

### B2 — Hardcoded `discoveredReaders[0]` *(critical for multi-reader sites)*

`front_payment_terminal.php:87`:

```js
const selectedReader = discoveredReaders[0];
```

`discoverReaderHandler()` fires automatically on page load and immediately connects to whichever reader Stripe happens to return first. A clinic with a reader per exam room will randomly route payments to the wrong terminal. There is **no reader selection UI at all** — the "Discover readers" / "Connect Reader" comments in the code describe buttons that were never built.

This is the single most user-visible gap for a multi-device rollout, and it is orthogonal to reader model.

### B3 — No Location scoping *(critical for multi-site)*

- `ConnectionToken::create()` (`front_payment_cc.php:115`) takes no `location` argument → the token is usable with every reader on the account
- `discoverReaders({simulated: false})` (`front_payment_terminal.php:65`) takes no `location` filter → discovery spans the whole account

A multi-facility practice will see every reader in the organization in one undifferentiated list. `Stripe\Terminal\Location` is imported at `front_payment_cc.php:22` and **never used** — the intent was clearly there and never finished.

There is no facility→Stripe-Location mapping anywhere in schema or globals.

### B4 — Amount conversion is wrong for common inputs *(critical, financial)*

`front_payment_terminal.php:19`:

```php
let amount = <?php echo js_escape(str_replace('.', '', $total)); ?>;
```

`$total` comes straight from the `form_paytotal` text input via a query string. Stripping the decimal point is not a cents conversion:

| User enters | Becomes | Charged | Correct |
|---|---|---|---|
| `50.00` | `5000` | $50.00 | ✅ |
| `50` | `50` | **$0.50** | ❌ |
| `50.5` | `505` | **$5.05** | ❌ |
| `1,250.00` | `125000` | $1,250.00 | ⚠️ (comma survives → likely JS parse issue) |

Must be `(int) round((float) $total * 100)`, and — see S3 — the amount should be derived server-side from the invoice, not accepted from the client.

### B5 — No simulated reader support *(blocks testing)*

`{simulated: false}` is hardcoded. There is no way to exercise the flow without physical hardware, which is why this code has gone five years without regression coverage. Stripe's `simulated_wisepos_e` device type exists precisely for this.

### B6 — Currency hardcoded to USD

`front_payment_cc.php:185` (`'currency' => 'usd'`) and the literal `$` in the terminal template. Blocks EU/UK/AU deployments and conflicts with OpenEMR's existing currency handling.

---

## 5. Missing capabilities the S700 specifically expects

The S700 is a large-screen customer-facing device. Features that were irrelevant on a P400 keypad are now baseline UX expectations:

| Capability | API | Current | Notes |
|---|---|---|---|
| Cart / line-item display | `setReaderDisplay()` | ❌ absent | S700's main screen sits idle showing a splash image |
| On-reader tipping | `collectPaymentMethod` `config_override.tipping` / `skip_tipping` | ❌ absent | Behavior is whatever the account default is — unpredictable, and tips would not reconcile into OpenEMR's ledger |
| Customer cancellation on reader | `enable_customer_cancellation` | ❌ absent | S700 can offer a Cancel button |
| Reader status / connection UI | `onConnectionStatusChange`, `onPaymentStatusChange` | ❌ absent | Only `onUnexpectedReaderDisconnect` → a bare `alert()` |
| Refunds at the reader | `Reader::refundPayment()` | ❌ absent | "Cancel Payment" only voids an *uncaptured* intent |
| Reader fleet config | `Terminal\Configuration` | ❌ absent | Splash screen, tipping defaults per fleet |

Note the button labelled "Cancel Payment" (`refund-button`, `front_payment_terminal.php:280`) calls `PaymentIntent::cancel()`. Once the intent is captured, this button is hidden — so **there is no refund path in the UI at all**, despite the element id.

---

## 6. Additional issues found during review

Not S700-specific, but they should be resolved as part of any rework of these files.

### S1 — No CSRF protection on any terminal endpoint
`front_payment_cc.php` modes `terminal_token`, `terminal_create`, `terminal_capture`, `cancel_intent` accept POSTs with no CSRF token. `$ignoreAuth = false` means a session is required, but that is exactly the precondition CSRF exploits. `terminal_create` in particular will mint a PaymentIntent on demand.

### S2 — No ACL check
Neither `front_payment_cc.php` nor `front_payment_terminal.php` calls `AclMain::aclCheckCore()`. Any authenticated user — regardless of billing/front-office privilege — can create, capture, and cancel payment intents.

### S3 — Client-supplied amount
`terminal_create` takes `$json_obj->amount` from the request body verbatim (`front_payment_cc.php:183`). Combined with S1/S2, a user can charge an arbitrary amount against a patient's card. The server should compute the amount from the selected invoices.

### S4 — Full PaymentIntent serialized to the browser
`front_payment_cc.php:158`: `echo json_encode($intent);` returns the entire object — including `charges`, card metadata, and internal Stripe fields — into browser JS and into the on-screen `#logs` panel. Return only `id` and `status`.

### S5 — Undefined array key warnings on PHP 8
`front_payment_cc.php:24` and `:60` read `$_POST['mode']` unguarded. Every GET-mode terminal request (`?mode=terminal_token` etc.) emits two `Undefined array key "mode"` warnings. Any warning output ahead of `header('Content-Type: application/json')` also risks corrupting the JSON response.

### S6 — No webhook handler → orphaned authorizations
The flow uses `capture_method: manual`. If the browser is closed, the tab crashes, or the staff member walks away between `processPayment` and `terminal_capture`, the PaymentIntent is left in `requires_capture` — the patient's card carries a live authorization that OpenEMR has no record of and no way to reconcile. There is no `terminal.reader.action_succeeded` / `payment_intent.*` webhook endpoint anywhere in the repo. This is a real financial exposure that exists today.

### S7 — Payment recorded via DOM manipulation
`front_payment_terminal.php:166-167` writes the intent id into the opener's `#check_number` field and synthesizes a form click. No structured audit record of the card-present transaction is stored (contrast the `$ccaudit` JSON assembled for the card-not-present Stripe and AuthorizeNet paths).

### S8 — Stale/misleading global text
`cc_stripe_terminal`'s label and description both name the EOL Verifone P400. Users configuring an S700 have no reason to believe this is the right switch.

---

## 7. Two options

### Option A — Modernize the JavaScript SDK path

Keep the architecture; fix the defects.

**Work:** reader selection UI, location scoping (ConnectionToken + discovery + a facility→Location mapping), simulated reader toggle, amount fix, currency, `setReaderDisplay`, tipping config, connection-status handling, `allow="local-network-access"` on the dlgopen iframe, HTTPS enforcement documentation, plus S1–S8.

**Pros:** incremental; keeps `processPayment` semantics staff are used to; preserves the existing modal UX.

**Cons:** inherits the Chrome LNA problem permanently — every new browser release is a potential break. Requires HTTPS and correct local DNS on the practice LAN, which is a support burden OpenEMR cannot control. Stripe's own docs steer smart-reader integrators away from this path. And it does nothing about S6 (orphaned auths) without also building webhooks.

### Option B — Migrate to the server-driven integration *(recommended)*

Drive the reader from PHP. The browser only polls OpenEMR for status.

```php
// 1. Create the intent (amount derived server-side from invoices)
$intent = PaymentIntent::create([...'payment_method_types' => ['card_present']...]);

// 2. Push it to a specific, chosen reader
$reader = Reader::processPaymentIntent($readerId, ['payment_intent' => $intent->id]);

// 3. Poll Reader::retrieve($readerId)->action  (or handle the webhook)
// 4. Reader::cancelAction($readerId) to abort
// 5. PaymentIntent::capture() as today
```

Every method above exists in the vendored v16.6.0 (`vendor/stripe/stripe-php/lib/Service/Terminal/ReaderService.php:39,86,150`).

**Pros:**
- No local network involvement → **Chrome 142 issue disappears entirely**; no HTTPS-on-LAN requirement, no local DNS requirement
- No `js.stripe.com/terminal/v1/` dependency, no connection-token endpoint, no client-side reader lifecycle → **less code than today**
- Reader selection becomes a plain server-side list (`Reader::all(['location' => ...])`) rendered as a dropdown — solves B2 and B3 naturally
- Amount is inherently server-derived → resolves S3
- Webhooks are the documented completion path → resolves S6
- Supports S700, S710, WisePOS E, Verifone V660p

**Cons:**
- Requires polling or webhooks; webhooks need an internet-reachable endpoint (polling is an acceptable fallback for on-prem installs)
- Does **not** cover Verifone UX700 / P630 / M425 — those remain JS-SDK-only
- A behavioral change for existing P400 sites, though those sites are non-functional anyway post-EOL

**Suggested shape:** a `src/Billing/StripeTerminalService.php` extending the service-layer conventions in `CLAUDE.md`, replacing the ad-hoc `Stripe\*` calls scattered through `front_payment_cc.php`.

---

## 8. Recommended sequencing

**Phase 1 — Stop the bleeding (small, independent of architecture)**
1. Fix the amount conversion (B4) — active financial bug
2. Add CSRF + ACL checks (S1, S2)
3. Server-derive the amount (S3)
4. Trim the JSON response (S4); guard `$_POST['mode']` (S5)
5. Reword the `cc_stripe_terminal` global away from "P400" (S8)

**Phase 2 — Architecture**
6. Build `StripeTerminalService` on the server-driven API
7. Add `terminal_location_id` (per-facility) and reader-selection configuration
8. Rebuild `front_payment_terminal.php` as a reader picker + status poller
9. Add a webhook endpoint for `terminal.reader.action_*` / `payment_intent.*` (S6)
10. Persist a structured card-present audit record (S7)

**Phase 3 — Smart reader UX**
11. `setReaderDisplay()` cart line items
12. Tipping configuration + reconciliation of tip amounts into the ledger
13. Reader-initiated refunds via `Reader::refundPayment()`
14. Multi-currency (B6)

**Phase 4 — Testing**
15. Simulated reader support (B5) so this flow can finally have automated coverage

---

## 9. Open questions for you

1. **Do we need the Verifone UX700 / P630 / M425?** If yes, server-driven alone is insufficient and we need to keep or dual-path the JS SDK. If the target is S700 + WisePOS E + V660p, server-driven covers everything.
2. **Are any target deployments on plain HTTP?** This decides whether Option A is viable at all.
3. **Can target sites expose a webhook endpoint,** or must completion detection be polling-only?
4. **Is on-reader tipping in scope?** It has ledger implications well beyond the terminal code — tips would need somewhere to land in the payments schema.
5. **Should this move into a module** (e.g. alongside the existing `oe-module-*` custom modules) rather than staying in `interface/patient_file/`? The current code mixes UI, JS, and Stripe API calls in files that also handle AuthorizeNet and Sphere.
6. **Upstream or downstream?** Phase 1 fixes look like upstream OpenEMR PRs regardless of what we decide for Phase 2+.

---

## 10. Upstream activity review (2026 issues & PRs) — and what it changes

Surveyed `openemr/openemr` issues and PRs created 2026-01-01 → 2026-07-20 for Stripe / terminal / payments / card-present / reader work, plus the full git history of the three terminal files.

### 10.1 Headline: nobody has touched the Stripe terminal code

```
$ git log --oneline origin/master -- interface/patient_file/front_payment_terminal.php
deeff7968d New Stripe In Person CC terminal payments (#4404)
```

**One commit. Ever.** The file is byte-identical to its 2021 merge.

`front_payment_cc.php` has had 9 commits in 2026, but every one is a mechanical sweep that happened to include this file — crypto helper migration (#11956), PHPStan baseline drains (#11821, #11902), `OEGlobalsBag` migration (#11017, #11257), `catch (Throwable)` (#10620), unused-import linting (#10938). None is a payments change.

Notably, #10938's unused-import lint **deleted the `use Stripe\Terminal\Location;` import** rather than implementing location scoping — which independently confirms **B3**: the dead import was recognized as dead and removed, and nobody wondered why it was there.

**Every finding in §4–§6 is still open on master.** Re-verified individually:

| Finding | Master status |
|---|---|
| B1 Chrome 142 / LNA iframe | ❌ open — no `allow="local-network-access"` anywhere |
| B2 `discoveredReaders[0]` | ❌ open — file untouched |
| B3 no Location scoping | ❌ open — import *removed*, feature never built |
| B4 amount conversion bug | ❌ open — `str_replace('.', '', $total)` verbatim |
| B5 no simulated reader | ❌ open |
| B6 hardcoded USD | ❌ open — `'currency' => 'usd'` verbatim |
| S1 no CSRF | ❌ open on the **terminal** endpoints (see 10.3) |
| S2 no ACL | ❌ open |
| S3 client-supplied amount | ❌ open — `$json_obj->amount` verbatim |
| S4 full PaymentIntent echoed | ❌ open — `echo json_encode($intent);` verbatim |
| S5 undefined `$_POST['mode']` | ❌ open |
| S6 no webhooks / orphaned auths | ⚠️ partly addressed — infrastructure now exists, unused by Stripe (see 10.2) |
| S7 DOM-based payment recording | ⚠️ `Recorder.php` now exists, unused by Stripe (see 10.2) |
| S8 stale "P400" global text | ❌ open — `globals.inc.php:3329` still says Verifone P400 |

### 10.2 The big one: PR #10445 already built the architecture I recommended

[**#10445 — feat(payments): Add support for Rainforest as a payment gateway**](https://github.com/openemr/openemr/pull/10445) (Eric Stern / @Firehed, merged 2026-02-03) is a full, modern, in-core payment gateway implementation:

```
src/PaymentProcessing/Recorder.php                      ← shared payment recording
src/PaymentProcessing/Rainforest/Api.php
src/PaymentProcessing/Rainforest/EncounterData.php
src/PaymentProcessing/Rainforest/Metadata.php
src/PaymentProcessing/Rainforest/Webhooks/Dispatcher.php
src/PaymentProcessing/Rainforest/Webhooks/ProcessorInterface.php
src/PaymentProcessing/Rainforest/Webhooks/RecordPayment.php
src/PaymentProcessing/Rainforest/Webhooks/Verifier.php
src/PaymentProcessing/Rainforest/Webhooks/Webhook.php
interface/webhooks/payment/rainforest.php               ← webhook endpoint
templates/payments/rainforest.js
tests/Tests/Unit/PaymentProcessing/Rainforest/...       ← 3 unit test files
```

This **materially changes my recommendation**. §7 Option B proposed inventing `src/Billing/StripeTerminalService.php`. That is now wrong — the established pattern is `src/PaymentProcessing/<Gateway>/`, with:

- a signature-verifying webhook receiver under `interface/webhooks/payment/` (**directly solves S6** — the orphaned-authorization exposure)
- `PaymentProcessing\Recorder` for structured payment persistence (**directly solves S7** — replaces the `#check_number` DOM hack)
- a `ProcessorInterface` / `Dispatcher` pattern for webhook event routing
- an actual unit-test convention for payment code

A server-driven Stripe Terminal implementation should be `src/PaymentProcessing/Stripe/` following #10445's shape, reusing `Recorder` and the webhook receiver pattern. Read `src/PaymentProcessing/Rainforest/README.md` before designing anything.

The PR author's own note is worth quoting, because it answers open question #5 in §9:

> "I wanted to implement this as an external module, but due to the current implementation of payments logic in core, doing so is infeasible at the moment. We have plans to pull payments logic out to modules, but completing this is blocked on a few things. The implementation is designed to be as easy to extract as possible once doing so becomes practical."

So: **build in core, structured for later extraction.** Don't start a module.

### 10.3 Related open issues — active, unclaimed, and directly adjacent

| # | Title | Relevance |
|---|---|---|
| [#10219](https://github.com/openemr/openemr/issues/10219) | refactor(payments): Create service for payments internals | **Highest.** Firehed proposes a "driver model" for gateways; sjpadgett explicitly says *"I recommend a reengineering of the gateway. As is it really doesn't follow its original intent (got sidetracked)."* Any Stripe terminal rework should be coordinated here or it will be rewritten under us. |
| [#10334](https://github.com/openemr/openemr/issues/10334) | feat: Generic webhook handling infrastructure | Directly covers S6. Explicitly framed "from a payments lens." adunsulag wants it aligned with FHIR Subscriptions; sjpadgett wants it module-first. **Unresolved design debate — do not build a bespoke Stripe webhook handler without weighing in here.** |
| [#10448](https://github.com/openemr/openemr/issues/10448) | feat(payments): Support Sphere's PAX Terminal Integration | **Strong precedent.** Same problem, different vendor: existing terminal integration is stuck on obsolete hardware (ID Tech SREDKey2), users want modern tap-to-pay devices. Filed by Stephen Waite, open since January, no comments, no assignee. A Stripe S700 issue would be its sibling — consider cross-linking. |
| [#11804](https://github.com/openemr/openemr/issues/11804) | Rainforestpay handlers crash on CSRF mismatch | Cautionary: CSRF-on-JSON-payment-endpoints is a known rough edge. Relevant when implementing S1. |

**No 2026 issue or PR mentions Stripe Terminal, card-present, the S700, or reader hardware.** Searches for "card present", "in person", and "front_payment" returned nothing. This work is genuinely unclaimed — and the P400 EOL appears to be unnoticed upstream.

### 10.4 Adjacent merged PRs worth reading before writing code

| # | Title | Why |
|---|---|---|
| [#11958](https://github.com/openemr/openemr/pull/11958) | fix(portal): add CSRF protection to payment handler | Adds CSRF to `portal/lib/paylib.php` + portal payment forms. **Covers the portal Stripe path but NOT the front-office terminal endpoints** — so S1 stands. Copy this PR's approach. |
| [#12069](https://github.com/openemr/openemr/pull/12069) | refactor(sphere): use session storage instead of encryption for payment data | Precedent for *not* trusting client-passed payment data — the same principle behind S3. |
| [#11956](https://github.com/openemr/openemr/pull/11956) | refactor(crypto): migrate to encryptForDatabase/decryptFromDatabase | Touched `front_payment_cc.php`. On master the key fetch is now `ServiceContainer::getCrypto()->decryptFromDatabase(OEGlobalsBag::getInstance()->getString('gateway_api_key'))` — use this form, not `CryptoGen`/`$GLOBALS`. |
| [#12339](https://github.com/openemr/openemr/pull/12339) | fix(payments): add cryptogen to front_payment.php | Recent active maintenance on the sibling file by Stephen Waite. |

### 10.5 Revised guidance

1. **Rebase the work onto `master`, not `rel-800`.** The local checkout is 4 months behind and lacks the entire `src/PaymentProcessing/` Rainforest architecture.
2. **Replace §7 Option B's `src/Billing/StripeTerminalService.php` with `src/PaymentProcessing/Stripe/`,** modeled on `src/PaymentProcessing/Rainforest/`.
3. **Reuse `PaymentProcessing\Recorder` and the `interface/webhooks/payment/` pattern** rather than inventing persistence or webhook handling.
4. **Comment on #10219 and #10334 before starting Phase 2.** Both have live, unresolved design debates (driver model; module-vs-core; FHIR Subscriptions) that would otherwise invalidate the work. sjpadgett — the original author of the Stripe terminal code — is active in both threads and is the right person to review this.
5. **File a new issue for Stripe smart reader support,** cross-linked to #10448. The P400 EOL and the Chrome 142 breakage are both news to the project and are worth surfacing on their own merits, independent of whether we do the work.
6. **Phase 1 fixes (§8) remain valid, unblocked, and independently mergeable** against master — B4 and S1–S5 touch only `front_payment_cc.php` / `front_payment_terminal.php` and conflict with nothing in flight.

---

## 11. UK deployment feasibility — currency and regulatory audit

**Question posed:** can we process Stripe payments in the UK, in GBP, from both the patient portal and the back office, with nothing hardcoded to USD?

**Answer:** not today. Currency hardcoding is real but is the *smaller* of two problems — and the larger one has nothing to do with currency.

All findings verified against `origin/master` (`7cdbd106ad`, 2026-07-20).

### 11.1 Summary of blockers

| # | Blocker | Severity | Affects |
|---|---|---|---|
| **UK-1** | Card-not-present flow uses the legacy Charges/Tokens API — **not SCA-compliant** | 🔴 Critical | Portal + back office |
| **UK-2** | No ISO-4217 currency global exists — there is nothing to configure | 🔴 Critical | All gateways |
| **UK-3** | 5 hardcoded `USD`/`usd` sites determine the actual charge currency | 🔴 Critical | Portal + back office |
| **UK-4** | Terminal requires GB-addressed Locations; OpenEMR has no Location support | 🔴 Critical | Back office (in-person) |
| **UK-5** | Payment tables have no currency column — DB is single-currency by construction | 🟡 Structural | All |
| **UK-6** | Sphere has no currency concept at all | 🟡 Scope | Portal + back office |
| **UK-7** | Rainforest is US-only regardless of code | 🟡 Scope | Portal + back office |
| **UK-8** | `FormatMoney` renders `£ 12.50` (spurious space) | 🟢 Cosmetic | Display only |

### 11.2 UK-1 — The card-not-present path is not SCA-compliant *(the real blocker)*

This is more serious than currency and was not in the original scope of the question.

`PaymentGateway::setGateway()` (`src/Billing/PaymentGateway.php:135`) calls `Omnipay::create('Stripe')`, which resolves to `Omnipay\Stripe\Gateway` → `PurchaseRequest extends AuthorizeRequest` → endpoint **`/charges`** (`vendor/omnipay/stripe/src/Message/AuthorizeRequest.php:377`). The client side matches: `stripe.createToken(card)` at `front_payment.php:1734` and in `portal/portal_payment.stripe.js`.

That is the **legacy Charges API driven by the legacy Tokens API** — the exact combination Stripe names as non-SCA-ready:

> "Integrations that aren't SCA-ready, like those using the legacy Charges API, might see high rates of declines from banks that enforce SCA."
> — [Stripe SCA readiness](https://docs.stripe.com/strong-customer-authentication)

PSD2 SCA has been enforced in the UK since **2021-09-14**. Only the **Payment Intents API**, Setup Intents, Checkout, and Billing are SCA-ready. A UK deployment on this path will see card payments declined by issuing banks, intermittently and unpredictably — the worst possible failure mode for a clinic taking payment at the desk.

**This affects both the portal and the back-office manual-entry paths equally.** It does *not* affect the Terminal path — see 11.6.

**Fix is available and cheap.** The already-vendored `omnipay/stripe` v3.2.0 ships `Omnipay\Stripe\PaymentIntentsGateway` with `purchase()`, `confirm()`, `completePurchase()`, `createSetupIntent()`, and a full `Message/PaymentIntents/` request set. OpenEMR simply doesn't use it.

- Server: `Omnipay::create('Stripe_PaymentIntents')` in `setGateway()`, plus handling for the `requires_action` / redirect response that `submitPaymentToken()` currently collapses into a bare string message
- Client: `stripe.createToken()` → `stripe.confirmCardPayment()` in `front_payment.php` and `portal/portal_payment.stripe.js`

Note `submitPaymentToken()` (`PaymentGateway.php:111-127`) already has an `isRedirect()` branch, but it discards the response and returns `$response->getMessage()` — so the 3DS challenge URL is thrown away. That branch needs real handling, not just a gateway swap.

### 11.3 UK-2 — There is no currency code to configure

OpenEMR's currency globals (`library/globals.inc.php:784-822`, Locale section):

| Global | Type | Default | Purpose |
|---|---|---|---|
| `currency_decimals` | select 0/1/2 | `2` | display |
| `currency_dec_point` | select `.` / `,` | `.` | display |
| `currency_thousands_sep` | select `,` `.` ` ` `` | `,` | display |
| `gbl_currency_symbol` | text | `$` | display glyph |

**All four are display formatting only. None is ever consulted by any payment gateway.**

There is no ISO-4217 currency-code global anywhere in OpenEMR. The practical consequence:

> A UK admin sets `gbl_currency_symbol` to `£`. Every screen renders `£`. Every card transaction still submits `USD`.

So UK-3 cannot be fixed by parameterising the existing hardcoded strings — **there is no variable to point them at**. A new global (`gbl_currency_code`, ISO-4217, default `USD`) must be added first. This is the prerequisite for everything else in this section.

### 11.4 UK-3 — Hardcoded USD inventory

Complete list on master. The first five determine what currency the customer's card is actually charged in.

| # | File | Line | Code | Path |
|---|---|---|---|---|
| 1 | `interface/patient_file/front_payment_cc.php` | 29 | `$transaction['currency'] = "USD";` — AuthorizeNet | Back office |
| 2 | `interface/patient_file/front_payment_cc.php` | 72 | `$transaction['currency'] = "USD";` — Stripe | Back office |
| 3 | `interface/patient_file/front_payment_cc.php` | 189 | `'currency' => 'usd',` — **Stripe Terminal** `PaymentIntent::create` | Back office |
| 4 | `portal/lib/paylib.php` | 109 | `$transaction['currency'] = "USD";` — AuthorizeNet | **Portal** |
| 5 | `portal/lib/paylib.php` | 148 | `$transaction['currency'] = "USD";` — Stripe | **Portal** |
| 6 | `templates/payments/rainforest.js` | 17-19 | `// This assumes USD for the foreseeable future.` / `const currency = 'USD'` | Shared |
| 7 | `src/PaymentProcessing/Rainforest/Apis/GetPayinComponentParameters.php` | 83, 85, 95 | `$usd = new Currency('USD');` | Shared |
| 8 | `src/Services/FHIR/FhirCoverageService.php` | 294 | `$valueMoney->setCurrency('USD');` | FHIR export (not payment capture) |

Confirms **§4 B6** and extends it: B6 flagged only the Terminal path (#3). In fact the portal is equally affected (#4, #5), which the original document did not cover.

Two details worth noting:

- **#6 and #7 are independent USD assumptions on the same request.** `rainforest.js` sends a `currency` field; `GetPayinComponentParameters` never reads it — its docblock (lines 72-77) documents only `dollars`, `patientId`, `encounters`. Fixing the client alone would silently do nothing.
- **No hardcoded `$` glyphs were found** in `front_payment.php`, `portal_payment.php`, `paylib.php`, or `templates/payments/`. UI symbol rendering is already global-driven. The one exception is `front_payment_terminal.php:274` — `<i>$</i>` hardcoded in the Terminal modal (already logged as part of B6).

### 11.5 UK-4 — Terminal Locations are mandatory in the UK

From [Stripe Terminal regional considerations (GB)](https://docs.stripe.com/terminal/payments/regional?integration-country=GB):

- Your Stripe account **must be a UK account**
- You **must create Locations with GB addresses** (`line1`, `city`, `country=GB`, `postal_code`) and associate readers with them — *"this will ensure that they automatically download the configuration needed to properly process charges in the United Kingdom"*
- **"Terminal requires you to use local currency"** — GBP only, no exceptions
- Minimum reader firmware: BBPOS WisePOS E `1.5.0.0`+

**This re-rates §4 B3 from 🟡 to 🔴 for UK.** The original document treated missing Location scoping as a multi-site convenience gap. In the UK it is a hard functional requirement: without GB-addressed Locations the readers do not receive correct regional configuration. `ConnectionToken::create()` (`front_payment_cc.php:115`) still takes no location argument, and the `Stripe\Terminal\Location` import was *deleted* by lint tidying (see §10.1).

### 11.6 What actually works in the UK's favour

Two pieces of good news:

1. **Terminal handles SCA in hardware.** In GB, chip-and-PIN contact transactions satisfy SCA natively (chip = possession, PIN = knowledge) — the reader prompts for it. So the in-person path sidesteps UK-1 entirely. Expect two charge records per authenticated transaction (a soft decline `offline_pin_required`, then the real authorization) — any reconciliation logic must tolerate this.
2. **`moneyphp/money` is already in the stack.** `Recorder::formatMoney()` (`src/PaymentProcessing/Recorder.php:222`) uses `DecimalMoneyFormatter` with `ISOCurrencies`, so minor-unit scaling is correct for any ISO currency. GBP is 2-decimal like USD, so no behavioural change — but the abstraction is there and correct.

### 11.7 UK-5 — The database is single-currency by construction

Verified against `origin/master:sql/database.sql`:

| Table | Monetary columns | Currency column |
|---|---|---|
| `ar_session` (10158) | `pay_total`, `global_amount` `decimal(12,2)` | ❌ none |
| `ar_activity` (10188) | `pay_amount`, `adj_amount` `decimal(12,2)` | ❌ none |
| `payments` (8595) | `amount1`, `amount2`, `posted1`, `posted2` `decimal(12,2)` | ❌ none |
| `payment_gateway_details` (~8620) | — | ❌ none |

No migration anywhere adds a currency column to a payment table. The only currency reference in the entire SQL tree is a comment on an unrelated column (`pr_price ... COMMENT 'price in local currency'`).

**Assessment: workable for a single-country UK site, but note the constraint.** Nothing corrupts — every stored amount is simply implicitly in the site's currency. But it forecloses multi-currency, and any future migration of historical rows is lossy because the currency was never recorded. If OPIT expects to run UK and non-UK sites off one database this becomes blocking; for separate per-country instances it is acceptable technical debt.

### 11.8 UK-6 / UK-7 — Gateway viability in the UK

| Gateway | UK viable? | Notes |
|---|---|---|
| **Stripe** | ✅ **with UK-1/2/3/4 fixed** | The only realistic option. Terminal + portal + back office all supported in GB/GBP |
| AuthorizeNet | ⚠️ possible | Supports GBP; needs UK-2/UK-3 threading (#1, #4) |
| **Sphere** | ❌ | **No currency concept whatsoever.** `SpherePayment.php` has zero currency references; line 212 builds the redirect as `'&amount=' + encodeURIComponent(total)` — bare amount, no currency parameter. US-only processor. Should be hidden from the gateway selector on UK sites |
| **Rainforest** | ❌ | Best-architected gateway (Money/Currency value objects throughout), but Rainforest Pay is a **US-only acquirer**. Code fixes would not make it usable in the UK |

So: **Stripe is the answer for UK, and it is the right call** — but only after UK-1 through UK-4 are addressed.

### 11.9 UK-8 — Cosmetic

`src/Common/Utils/FormatMoney.php:43`: `$s = $currencySymbol . " $s";` renders **`£ 12.50`** with a spurious space. Pre-existing and affects `$ 12.50` equally, so not UK-specific — but far more conspicuous with `£`. Note the payment screens call `oeFormatMoney()` with `$symbol` defaulting to `false`, so amounts render bare there; the symbol only appears in POS checkout (`pos_checkout_normal.php:418,466`, `pos_checkout_ippf.php:1042`).

### 11.10 Recommended UK work plan

**Phase U0 — Prerequisite**
1. Add `gbl_currency_code` global (ISO-4217, default `USD`) in the Locale section beside the existing currency globals. Everything else depends on this.

**Phase U1 — Make GBP charges actually work**
2. Thread `gbl_currency_code` through the 5 charge-determining sites (`front_payment_cc.php:29,72,189`; `paylib.php:109,148`)
3. Fix the Terminal amount conversion (**§4 B4**) using `currency_decimals` rather than assuming 2 — this is a prerequisite for correct minor units in any currency
4. Replace the hardcoded `<i>$</i>` at `front_payment_terminal.php:274` with `gbl_currency_symbol`
5. Hide Sphere (and Rainforest) from the `payment_gateway` selector when currency ≠ USD, or document them as unsupported

**Phase U2 — SCA compliance (blocking for go-live)**
6. Migrate `PaymentGateway` to `Omnipay::create('Stripe_PaymentIntents')`
7. Implement real `requires_action` / 3DS redirect handling in `submitPaymentToken()` — currently the redirect branch discards the challenge URL
8. Migrate client-side `stripe.createToken()` → `stripe.confirmCardPayment()` in `front_payment.php` and `portal/portal_payment.stripe.js`
9. Test against Stripe's 3DS test cards before go-live

**Phase U3 — Terminal in the UK**
10. Add Terminal Location management with GB address support, and a facility→Location mapping
11. Pass `location` to both `ConnectionToken::create()` and `discoverReaders()` (**§4 B3**)
12. Verify reader firmware ≥ `1.5.0.0` (WisePOS E)
13. Ensure reconciliation tolerates the GB soft-decline-then-authorize double charge record

**Phase U4 — Polish**
14. Fix `FormatMoney.php:43` symbol spacing
15. Consider a currency column on `ar_session` / `ar_activity` if multi-currency is ever in scope

### 11.11 Revised open questions

Superseding and adding to §9:

1. **Is the UK site a separate OpenEMR instance from any US site?** If yes, UK-5 (no currency column) is acceptable debt. If one instance serves both, it becomes blocking and Phase U4 item 15 moves to Phase U0.
2. **Is card-not-present (portal + manual back-office entry) in scope for UK, or is this in-person only?** If Terminal-only, Phase U2 can be deferred and the project is substantially smaller — Terminal satisfies SCA in hardware. **If the portal is in scope, Phase U2 is mandatory and blocking.**
3. **Is the Stripe account already a UK account?** Required for Terminal in GB; cannot be changed after creation, so this needs confirming before hardware is ordered.
4. **Should Sphere/Rainforest be hidden or left visible-but-broken on UK sites?** Recommend hiding.
5. Should `gbl_currency_code` be upstreamed to OpenEMR core as a standalone PR? It is genuinely useful beyond this deployment and would be an easy, uncontroversial merge — worth landing early to avoid carrying a fork.

---

## 12. Decisions log (2026-07-20 review session)

Answers to the open questions from §9 and §11.11, and how each reshapes the plan. Deferred items list a **working default** used for planning until resolved.

| # | Question | Decision | Impact |
|---|---|---|---|
| 1 | UK payment paths in scope | **Both in-person and card-not-present** | Phase U2 (SCA / PaymentIntents migration) is **mandatory and blocking**. Full scope stands. |
| 2 | Reader models | **S700 + WisePOS E + Verifone V660p** | Server-driven-capable set → **architecture is server-driven** (§7 Option B). Kills the entire B1 Chrome-142/LAN/HTTPS class of problems. Verifone UX700/P630/M425 explicitly out. |
| 3 | DB topology (UK-only vs shared) | **Deferred** — default: **separate UK-only instance** | If shared DB later, the currency column (UK-5) escalates from Phase U4 to Phase U0. Resolve before any schema work. |
| 4 | Stripe account is UK | **Yes — UK account exists.** Dev in US, QA/hardware in UK | Hardware + GB Locations unblocked. **US devs can't touch physical readers** → see below. |
| 5 | Plain-HTTP deployments | **Dissolved by #2** | Server-driven doesn't need the browser on the reader LAN. HTTPS still wanted for webhooks/security, no longer a hard gate on the Terminal feature. |
| 6 | Webhook reachability | **Both — webhooks primary, polling fallback** | Robust completion path; closes S6. Coordinate with issue #10334. |
| 7 | On-reader tipping | **Deferred** — default: **disabled (`skip_tipping`)** | Ship tipping-off (correct default for clinical context); adding later is clean, shipping-on-then-retracting is not. No ledger work in initial build. |
| 8 | Hide Sphere/Rainforest on UK sites | **Deferred** — default: **hide on non-USD sites** | Minor UI/globals work. |
| 9 | Upstream vs downstream | **Deferred** — leaning **hybrid** | Currency global + SCA migration upstream; UK/OPIT config downstream. |
| 10 | Standalone `gbl_currency_code` PR | **Deferred** — depends on #9 | Recommend landing early upstream once #9 settles. |

**§9 Q5 (module vs core) — resolved by research, not asked:** PR #10445's author wanted a module, found it infeasible given payments coupling, and built in `src/PaymentProcessing/` structured for later extraction. We follow that precedent: **build in core, structured for extraction.**

### 12.1 Consequence of the US-dev / UK-QA split (from #4)

US developers cannot exercise the physical readers. Two items are therefore promoted:

- **Simulated reader support (B5) moves from Phase U4 to the START of Phase U3.** It is a prerequisite for US devs to build the Terminal flow at all. Server-driven simulation (`simulated_wisepos_e`, `Terminal\Reader::presentPaymentMethod` test helpers) needs no hardware or browser — another point in favour of the server-driven architecture.
- **Establish the dev/QA loop up front:** US devs build against the simulator + a shared UK **test-mode** Stripe key; UK QA validates the same build against live S700 / WisePOS E / V660p hardware. Define this handoff before implementation starts.

### 12.2 Consolidated build order (post-decisions)

1. **Phase U0** — `gbl_currency_code` global (ISO-4217, default `USD`). *(If #3 → shared DB: also add currency columns to `ar_session`/`ar_activity`/`payments`.)*
2. **Phase U1** — Thread currency through the 5 charge-determining sites; fix Terminal amount conversion (B4) using `currency_decimals`; replace hardcoded `<i>$</i>`; hide Sphere/Rainforest on non-USD (default per #8).
3. **Phase U2 (blocking for go-live)** — Migrate `PaymentGateway` to `Stripe_PaymentIntents`; real 3DS/`requires_action` handling in `submitPaymentToken()`; client `createToken()` → `confirmCardPayment()` in `front_payment.php` + `portal/portal_payment.stripe.js`; test with 3DS cards.
4. **Phase U3** — Server-driven Terminal in `src/PaymentProcessing/Stripe/` (per #2): **start with simulated reader support (B5)**; GB Location management + facility mapping; reader selection UI (B2); `location` scoping on discovery/connection tokens (B3); webhook receiver + polling fallback (per #6, closes S6); reconciliation tolerant of the GB soft-decline-then-authorize double record.
5. **Phase U4** — `FormatMoney.php:43` symbol spacing; tipping (only if #7 flips); currency column (only if not already done in U0 per #3).

### 12.3 Still-open external dependencies to track

- **#3** — confirm deployment topology before schema work.
- **#7** — confirm tipping before Phase U4 (default off).
- **#9 / #10** — confirm contribution strategy before opening PRs (doesn't block design).
- Coordinate on issues **#10219** (payments driver-model refactor) and **#10334** (webhook infrastructure) before Phase U2/U3 — both have live upstream design debates that could otherwise invalidate the work.

---

## Sources

- [Stripe Terminal readers](https://docs.stripe.com/terminal/readers)
- [Terminal card reader end-of-life status](https://support.stripe.com/questions/end-of-life-status-for-terminal-card-readers-and-sdks)
- [Stripe Terminal JS SDK API reference](https://docs.stripe.com/terminal/references/api/js-sdk)
- [Server-driven integration](https://docs.stripe.com/terminal/payments/setup-integration?terminal-sdk-platform=server-driven)
- [Chrome 142 and the Terminal JS SDK](https://support.stripe.com/questions/ensuring-stripe-terminal-javascript-sdk-functionality-on-chrome-142)
- [Connect to a reader (JS, internet readers)](https://docs.stripe.com/terminal/payments/connect-reader?reader-type=internet&terminal-sdk-platform=js)
- [openemr/openemr#4404](https://github.com/openemr/openemr/pull/4404)
- [openemr/openemr#10445 — Rainforest gateway](https://github.com/openemr/openemr/pull/10445)
- [openemr/openemr#10219 — payments service refactor](https://github.com/openemr/openemr/issues/10219)
- [openemr/openemr#10334 — generic webhook infrastructure](https://github.com/openemr/openemr/issues/10334)
- [openemr/openemr#10448 — Sphere PAX terminal support](https://github.com/openemr/openemr/issues/10448)
- [Stripe Strong Customer Authentication readiness](https://docs.stripe.com/strong-customer-authentication)
- [Payment Intents API requirement for SCA compliance](https://support.stripe.com/questions/payment-intents-api-requirement-for-strong-customer-authentication-(sca)-compliance)
- [Stripe Terminal regional considerations — GB](https://docs.stripe.com/terminal/payments/regional?integration-country=GB)
- [Stripe Terminal country and currency availability](https://support.stripe.com/questions/stripe-terminal-country-and-currency-availability)
