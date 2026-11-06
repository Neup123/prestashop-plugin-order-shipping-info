# Greece public-transport shipping for PrestaShop 9

Work-in-progress module for Athens-origin parcel movement by KTEL, ferry and domestic flight, with destination-specific estimates and **station/port/airport pickup**.

**Demo amounts are invented illustrative parcel fees, not verified carrier freight tariffs.** Never operate a live shop with demo prices.

## Current scope

- Carrier pricing and basic route/destination administration.
- Greek delivery address required; unmatched/disabled destinations unavailable.
- Demo routes represent plausible links but have no contractual or operational guarantee.
- For installation, copy the entire `grtransportshipping` directory into `modules/`, then install in Module Manager. The module creates a carrier and seeds assumed routes.
- Configure Greek zone/carrier restrictions and confirm checkout before production.
- PHP 8.2/8.4 lint and pure pricing tests run in GitHub Actions.

## Security bar

Admin mutations use PrestaShop employee token verification, strict validated input and escaping; client-side prices are never trusted. Unknown destination => no quote. No credentials or external network access.

## Acceptance still required

Real PrestaShop 9.2 install, carrier zone integration, checkout browser tests in Classic and Hummingbird 2, save order pickup information, and admin regression. See issue #1.
