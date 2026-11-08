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

## Acceptance still required

A real PrestaShop 9.2.1 Docker instance now installs the module, exercises the native Cart price/zone path, and completes Chromium admin editing plus a Greek customer's ferry checkout pickup-notice flow. The order pickup snapshot is verified in an integration hook test. Still needed: final paid-order confirmation/account acceptance, Hummingbird 2 coverage, full add/delete regression, and real transport contracts/fares. See issue #1.

## Route state and shipping consistency

New routes start as **Draft**; making them **Available** enables quotes. **Suspended** deliberately blocks any broader matching rate and is visibly warned about in the admin editor. Confirmed tariffs record operator ID/time, rather than silently losing the demo marker. Each persisted cart receives a pinned quote (station, notice and amount together) for at most 30 minutes, re-quoted after delivery-address changes. Upgrade 0.4.0 migrates earlier inactive routes to the explicit Suspended state.
