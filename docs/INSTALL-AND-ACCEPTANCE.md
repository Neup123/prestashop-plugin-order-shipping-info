# Installation and acceptance — PrestaShop 9.2

## Automated verification

GitHub Actions runs PHP 8.2/8.4 lint and quote tests; a separate PrestaShop 9.2 Docker job installs the module and verifies database records, carrier eligibility, Greek addresses and freight-like sample quotes. The **Module ZIP** workflow packages a downloadable installable module ZIP with `grtransportshipping/` as its root directory.

## Manual install

1. Download the artifact `prestashop-grtransportshipping` from a successful GitHub Actions **Module ZIP** run; extract the artifact envelope to obtain `grtransportshipping.zip`.
2. On a **disposable PrestaShop 9.2** instance, open **Modules → Module Manager → Upload a module**, select `grtransportshipping.zip`, install.
3. Open **Module Manager → Greek public transport shipping → Configure**. Examine all seed records: the values are **demo/assumed parcel shipping costs**, not operator tariffs. Edit a route's station, price, prefix and pickup flag, save, refresh, verify it persisted; disable it to verify it no longer quotes.
4. Ensure the new carrier is active for Greece, required customer groups and supported weight ranges. Do not enable other delivery countries for this carrier.
5. Add an ordinary shippable product to the cart, enter a Greek address with a seeded postcode, choose transport carrier, verify the quoted price and **STATION PICKUP ONLY** notice naming the right station. Check several bus, ferry and flight cases.
6. Confirm a test order using a test payment method. Verify station information in back office and customer order details. Change route data afterward; historical order pickup information should remain stable.
7. Enter an unmapped postcode (e.g., 99999) and confirm carrier cannot be selected. Verify delivery to a non-Greek country cannot select this carrier.
8. Test Greek-script city input alongside the matching postcode. Check mobile checkout rendering.

## No live use of seed data

Demo fares are arbitrary estimates. Public transport passenger tickets and departure routes do **not** validate parcel/cargo acceptance. Only enable live checkout after merchant replaces demo records with actual freight terms, verifies collection points, operating conditions, and confirms food shipping safety/handling requirements with transport operators.

## Outstanding checks

Browser checkout acceptance on Classic/Hummingbird frontends, order-confirmation hook visual confirmation, complete admin CRUD UI, and real merchant route and fee entry remain mandatory before production release.
