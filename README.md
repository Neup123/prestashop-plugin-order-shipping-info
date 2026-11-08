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
