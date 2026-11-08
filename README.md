# Greece public-transport shipping for PrestaShop 9

Work-in-progress module for Athens-origin parcel movement by KTEL, ferry and domestic flight, with destination-specific estimates and **station/port/airport pickup**.

**Demo amounts are invented illustrative parcel fees, not verified carrier freight tariffs.** Never operate a live shop with demo prices.

## Current scope

- Carrier pricing and route/destination administration, including explicitly retained demo/verified flags.
- Greek delivery address required; unmatched/disabled destinations unavailable.
- Demo routes represent plausible links but have no contractual or operational guarantee.
- For installation, copy the entire `grtransportshipping` directory into `modules/`, then install in Module Manager. The module creates a carrier and seeds assumed routes.
- Configure Greek zone/carrier restrictions and confirm checkout before production.
- PHP 8.2/8.4 lint, pure pricing tests, live PrestaShop 9.2 Docker native cart checks, and Chromium admin/checkout browser acceptance run in GitHub Actions.

## Security bar

Admin mutations use PrestaShop employee token verification, strict validated input and escaping; client-side prices are never trusted. Unknown destination => no quote. No credentials or external network access.

## Automated core acceptance and additional production readiness

A real PrestaShop 9.2.1 Docker instance now installs the module, exercises the native Cart price/zone path, and completes Chromium admin editing plus a Greek customer's ferry checkout pickup-notice flow. The order pickup snapshot is verified in an integration hook test. The disposable PrestaShop integration also validates a genuine pending-payment order, pickup snapshot and historical repair, while browser acceptance covers route add/edit/delete. Additional checks before production use: a completed live test-payment and customer account/confirmation review, Hummingbird 2 theme coverage, and the merchant's real transport contracts/fares.

## Route state and shipping consistency

New routes start as **Draft**; making them **Available** enables quotes. **Suspended** deliberately blocks any broader matching rate and is visibly warned about in the admin editor. Confirmed tariffs record operator ID/time, rather than silently losing the demo marker. Each persisted cart receives a pinned quote (station, notice and amount together) for at most 30 minutes, re-quoted after delivery-address changes. Upgrade 0.4.0 migrates earlier inactive routes to the explicit Suspended state.

## Single-source route definitions

Route SQL field widths, form limits, choices and validation are generated from `grtransportshipping/classes/RouteFields.php`. The one route writer (`classes/RouteRepository.php`) handles admin updates, named demo seed rows and fixtures. A single renderer produces consistent pickup/demo warnings in checkout and all order views. For a Greek delivery address with no priced route, PrestaShop hides the transport carrier; an independent Shipping Method hook now shows a clear unavailable-reason alert above the remaining carriers (including after an address change). Existing shops receive the hook on upgrade to 0.5.1. Route data is seeded only on first initialization, never again after merchants deliberately delete all rules. A failed deletion of a nonexistent route is reported as an error. The 0.5.0 upgrade adds a database notice-length constraint without truncating existing merchant text.

## Quote retention and schema upgrades (0.5.2)

New successfully resolved cart quotes opportunistically remove **at most 100** expired unconsumed pins per checkout pin operation. Expiry uses the same 30-minute hold as the quote resolver and an indexed `quote_status, created_at` lookup. Cleanup is incremental, not scheduled: if no new cart quotes are created, no cleanup runs until quoting resumes. **Consumed cart quotes are never pruned**; they remain the historical order-snapshot repair source.

On upgrading a previously installed module to **0.5.2**, PrestaShop adds the historically missing `lookup_prefix` route index and the new quote-pruning index. Both operations are idempotent; a fresh installation creates the indexes directly. Simply replacing module files does not run the schema upgrade: run **Module Manager → Upgrade** (or the documented PrestaShop module upgrade command).

## Multistore operating model (#51)

Route records in `grtrans_route` are **global to the PrestaShop installation**. This is deliberate: a change made while administering one shop changes price, station, availability/suspension and route matching in **every** shop in that installation. Carrier identity/configuration and delivery-zone/group eligibility are managed independently per shop; check each shop's active carrier settings. The route editor prominently discloses this shared scope. Do not rely on switching shop context to isolate route edits.

## Why demo routes seed Available (#52)

The ten invented demo routes intentionally start in the **Available** state so disposable installations immediately demonstrate real carrier quotations, pickup notices and order acceptance. This is a convenience for installation/testing, not a validated shipping price or a production-safe state. When the carrier is enabled, those Available records are **immediately sellable** despite the DEMO ESTIMATE notice. Before using live checkout, **mark demo routes Draft** (which neither quote nor veto broader rates), or replace their values with operator-confirmed tariffs. Existing merchant-controlled route states are never silently changed by upgrades. New manually added routes still default Draft.

## Scale envelope and known full scans (#53)

The current implementation deliberately loads **all** route rows on the first quote per cart (`grtransportshipping.php::quoteResult()`) before matching in PHP; the `lookup_prefix` index exists for schema parity but cannot accelerate this unfiltered read. The admin editor also loads all rows and `QuoteEngine::shadowedSuspensions()` can require O(N²) comparisons. Per-request caching and a persisted 30-minute pin reduce repeated work but not the initial scan. The CI pure matcher now tests **752 synthetic routes** with exact matching, suspension vetoes, regional fallback and admin shadow warnings, and the ordinary disposable PrestaShop integration tests the ten-row demo set. **Operational ceiling: plan for at most 750 merchant routes** until a realistic production-sized database benchmark and a bounded query strategy have been verified. This ceiling is a conservative tested functional envelope, **not** a latency or throughput guarantee. Larger datasets are unsupported until separately profiled; no undocumented SQL filtering shortcut is being claimed.

## English source and shipped Greek module translation (0.5.3; issue #50)

Module-authored storefront pickup/no-quote notices and back-office route controls now pass through PrestaShop's existing **classic module `l()` translation system**, with an `el` dictionary in `grtransportshipping/translations/el.php`. English is the source language and the fallback. The dictionary covers the safety-critical pickup-only, no-home-delivery, unverified-demo and no-route messages, plus route editor status, action and field labels. Merchant-entered route station names/notices and the ten demo seed rows intentionally remain **English sample/merchant content**: they are data, not module UI translations. Operators may customize them individually.

PrestaShop's native `carrier.name` is global rather than a multilingual field, so the module ships a short **bilingual English/Greek default name**; the actual `carrier_lang.delay` text is translated separately for Greek, with English for other languages. The 0.5.3 upgrade updates only the **unchanged stock text** on existing module-owned carriers; customized merchant carrier names/delays are preserved. Greek customers must have the Greek shop language installed and selected. Translations do not change route matching, rates, quote pinning or the native shipping-charge calculation.
