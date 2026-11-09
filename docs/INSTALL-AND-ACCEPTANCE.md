# Installation and acceptance — PrestaShop 9.2

## Automated verification

GitHub Actions runs PHP 8.2/8.4 lint and quote tests. A real PrestaShop 9.2 Docker job installs the module and checks carrier eligibility, Greek addresses, and the native Cart shipping calculator with physical goods. Browser CI logs into the real PrestaShop back office to edit a price, then logs in as a Greek buyer and verifies ferry-station pickup disclosure at checkout. A separate hook integration smoke test checks persisted order pickup snapshots and their stability after route edits. A real PrestaShop pending-cheque order test also validates order creation, customer/admin pickup text and historical quote-based repair; neither test charges a payment card. The **Module ZIP** workflow packages a downloadable module ZIP with `grtransportshipping/` at its root.

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

## Additional pre-production checks beyond core issue #1

Automated browser coverage currently uses the CI shop's default front-end theme; Hummingbird-specific browser acceptance and a completed real test-payment order (including customer confirmation/account details) are still outstanding. Admin route add/edit/delete and stale-ID rejection are covered by the Chromium browser suite; live merchant route/fare verification remains mandatory before production release. Never mistake assumed demo parcel prices for operator freight tariffs.

## Route lifecycle and quote price integrity (0.4.0)

Routes have three explicit states: **Draft** (not quoted and does not prevent a regional fallback), **Available** (normal pricing), and **Suspended** (intentionally blocks broader regional offers). Suspended rows that overshadow available regional rows display an operator warning in the back office; no hidden suspension is created by an ordinary draft. Equal-specificity rules with different station, price, notice, demo flag or pickup policy are treated as ambiguous and are not sold. Confirming a demo rate records the employee and time; changes to a confirmed rate must be re-confirmed.

The same shipping quote is cached for the current request and **pinned for a persisted cart**, keyed by both the address ID and its country/postcode/city fingerprint. The pinned price and station/notice are used for display, charging and order metadata, even if the merchant edits the live tariff between requests. A pinned quote expires after 30 minutes or when delivery-address details change. Current pins cannot be cancelled instantly by a route suspension; operators should account for this short hold when temporarily closing service.

Upgrading from 0.3.x converts legacy active rules to Available and inactive rules to Suspended, preserving the prior veto behavior, while newly created routes default to Draft. The 0.4.0 migration adds verification provenance and persisted cart quote storage. The pure state/quote tests and live Docker checkout cover the new behavior; they do not simulate several simultaneous browser sessions editing the same cart.

## Order pickup snapshot failure and recovery

An order using this transport carrier must retain a station, quoted amount, pickup policy and customer notice independently of later changes to route data. The validation hook now checks and logs the snapshot write instead of ignoring database failure; repeated validation is idempotent. If an existing transport order has no snapshot, the administrator sees an explicit warning and an **Repair missing pickup snapshot** action on the order; customer order views show a contact-merchant warning rather than silently omitting pickup information.

Repair uses only the original cart's persisted pinned quote and compares it with the order's actual shipping charge. It will never infer a historical station or price from today's route table. If the original pinned quote no longer exists, repair fails visibly and requires manual investigation; it must not manufacture an estimate. Regeneration of an existing record requires the same CSRF-protected admin action and is audit-logged. The Docker real-order test deletes a snapshot, changes the live fare and verifies that the historical quote is restored unchanged. It does not inject a production database failure or verify mail/payment gateway behavior.

## Carrier identity and partial-install recovery

The module has one canonical carrier ID (`GRTRANS_CARRIER`). Its `id_carrier` PHP property is a PrestaShop adapter and is refreshed from the canonical, validated identity before charging an order. A deleted, missing, foreign-owned or disabled carrier is **not** silently used at checkout. The module configuration page shows the observed carrier state and an explicit, CSRF-checked **Repair transport carrier** action. Repair reuses an eligible owned carrier when possible, otherwise creates a replacement; it never adopts an unrelated module's carrier.

Installation can be retried after a partial failure: the module reuses its existing PrestaShop module ID and carrier, fills only missing ranges/zones/delivery mappings and preserves existing merchant group restrictions. PrestaShop's carrier-clone update hook moves the canonical ID to the new owned carrier. Uninstall retires the canonical owned carrier without deleting historical route or order data. The final Docker integration smoke deliberately deletes a disposable carrier and verifies quote refusal, recovery and repeated-install idempotence; it does not test an actual production carrier deletion or every possible external plugin.

## Data definitions and operator feedback (0.5.0)

All route field lengths, mode options, form controls, validators and new-installation SQL columns come from `grtransportshipping/classes/RouteFields.php::spec()`. The `notice` limit is 2,000 characters; a DB `CHECK` enforces it on new installs and upgrade 0.5.0 adds the same field-spec-derived constraint to existing tables. If an existing shop contains longer notices, the upgrade refuses to truncate merchant data; shorten those notices before retrying. Route admin updates, initial demo imports and PHP test fixtures use `classes/RouteRepository.php` and named keys in `demo/routes.php`. Deleting a missing route now reports an error rather than success.

Demo seeding uses the `GRTRANS_SEED_INITIALIZED` one-time marker instead of inferring initialization from a route count. An operator's deliberately empty route table stays empty across module installation retries; the module does not reinsert assumed prices. In the module UI, **checkout, order confirmation, customer order detail and admin order** now render the same collection and unverified-price wording via a single renderer and English string table.

Coverage: PHP 8.2/8.4 checks validate the field contract, invalid values, and named demo fixtures; real PrestaShop 9.2.1 Docker checks native prices and order notices; browser CI checks route CRUD and the nonexistent-delete error. The test harness uses one `tests/bootstrap.php` assertion/bootstrap across PHP tests. These automated checks do not validate existing customer-specific merchant route values or every possible legacy MySQL upgrade; the migration preserves data and fails visibly when a long notice prevents the new DB constraint.


## Order shipping adjustments and charge integrity (#39)

A route's pinned `price` is its base carrier fare, not necessarily the final amount charged by PrestaShop. When **Delivery > Preferences > Handling fees** has a positive `PS_SHIPPING_HANDLING` and this particular carrier has `shipping_handling` enabled, the module adds the eligible, currency-converted handling fee **once** and pins it in the existing cart-quote record as `handling_fee`. The order's tax-exclusive shipping charge must equal the pinned base plus pinned handling, within EUR 0.02 for rounding. Handling fees remain separate from the route's historical base price in the order pickup snapshot.

A **genuinely applied free-shipping cart rule** may instead make order shipping zero. This exception is authorized only by the order's persisted, non-deleted `order_cart_rule.free_shipping=1`, never by an unsaved/cart-only coupon or user-supplied flag. PrestaShop versions may leave the shipping line intact while applying a matching discount to the order total; that is also valid when the line equals the expected amount. Other discrepancies are logged under the order and stop snapshot acceptance. Validation and historical snapshot regeneration call the same shared charge-checking helper (`classes/OrderCharge.php`), including when shop handling settings have changed since a quote was pinned.

**CI coverage:** PHP 8.2/8.4 checks cover fee/discount cases, rounding and rejection. The real PrestaShop 9.2.1 pending-cheque order smoke covers an applied free-shipping voucher, a carrier with a configured handling fee, regeneration after merchant settings change, and an unexplained saved-order shipping mismatch with an error log. **Not covered:** multi-package/multi-shipment allocation, payment-card gateways, external carrier hooks that rewrite shipping totals, or merchant-specific tax configurations. Those are not blanket exceptions to the charge invariant.


## Administrator route editor UX (0.5.x; issues #40–#42)

The route list is a horizontally scrollable back-office table, with human-readable **Draft**, **Available**, or **Suspended** status labels. Demo estimates are labeled **Unverified estimate** and have a warm highlight; operator-confirmed entries are labeled **Operator-confirmed**. Suspended rows use a different warning treatment. Station-pickup values read **Yes/No**, not storage flags. The transport carrier displays a standard ready/unavailable back-office alert. Delete remains a separate danger-styled button with confirmation.

Clicking **Edit** scrolls to an unmistakable **Editing route #N** panel. Failed saves leave the operator's original submitted values intact, show each invalid field in the error summary and beside the field, and return to the panel via the URL anchor. The field's accessible invalid state is also set. Add and edit share the same form and server-side route validation.

The route's primary key is **not editable**. Adding a route has no ID input; editing uses the route selected from the grid, an employee-bound signed edit intent, and the existing admin CSRF check. A forged or mismatched target is rejected before the single `RouteRepository::save()` writer is invoked. A direct administrator who wishes to change another route must open that route's own Edit action. No new persistent states or permissions are created.

**Automated acceptance:** `tests/route_fields_test.php` covers field definitions, field-specific errors, and removal of the ID editor. Chromium in the disposable PrestaShop 9.2.1 back office checks visible badges and actual CSS background, table containment, anchor scrolling and highlighted editor, failed edit and add retention, field errors, signed-target and injected-ID rejection, add/edit/delete, and suspended/verified labels. **Excluded:** narrow-screen/touch ergonomics, third-party admin themes, concurrent administrator changes, and production merchant data. These remain outside the automated CI scope.

## Back-office order pickup history: confirmation before regeneration (#43)

On a PrestaShop order **with an existing stored pickup snapshot**, **Regenerate original pickup snapshot** opens an explicit browser confirmation before the form is submitted. The prompt identifies the order and warns that regeneration **overwrites its saved historical pickup details** with the original checkout quote. Dismissing the prompt cancels submission. This uses the same standard browser confirmation pattern as the route Delete action, attached to the form so clicking the button and submitting with the keyboard both trigger it.

For an order **missing** its pickup snapshot, **Repair missing pickup snapshot** deliberately remains one-click: it creates the missing data rather than overwriting an existing historical record. Both confirmed regeneration and missing-snapshot repair still use the original PrestaShop admin CSRF token, the existing `grtrans_repair_order` POST handler and the one source-verified `GrTransportOrderSnapshot::repair()` writer. No change is made to snapshot content, its validity checks, or its audit log.

**Acceptance coverage:** The real PrestaShop 9.2.1 pending-payment order smoke checks that the stored-order form contains the overwrite warning and confirmation, while the missing-order form does not. In the disposable shop, Chromium loads the actual admin order page, dismisses the confirmation and verifies **no repair POST**, then accepts it and verifies **one POST and a successful regeneration message**. It does not block deliberately crafted HTTP requests from an authorized administrator, and it does not replace CSRF protections or test non-JavaScript browser settings. No production order is touched.

## Checkout no-quote visibility (issue #44; module 0.5.1)

**Checkout contract — actual Shipping Method step in the default Hummingbird theme:** PrestaShop hides a carrier when its external price callback returns `false`; `displayCarrierExtraContent` is therefore not a viable place for the reason. We subscribe to core `displayBeforeCarrier` instead, rendered by `CheckoutDeliveryStep` independently of the delivery-option list. When a delivery address exists and no Greek transport quote is available, this hook shows a styled informational alert above the remaining shipping choices, explaining **why Greek public transport is unavailable**. The ordinary shipping choices, carrier eligibility and route rejection semantics are unchanged. A matched address shows no no-quote alert and retains the existing station-pickup/demo disclosure attached to its carrier option.

| Cart/address result | Carrier and checkout rendering |
|---|---|
| Matched and priced | Greek transport option appears; selecting it shows the station, pickup-only condition and demo estimate notice through `displayCarrierExtraContent`. No unavailable alert. |
| Greek destination with no matching price | Greek transport option is absent; `displayBeforeCarrier` shows **No priced transport route matches this destination.** |
| Only matching Draft route | No transport offer; independent alert explains Draft is not for sale (without blocking any available regional route). |
| Matching Suspended route | No transport offer; independent alert explains the suspension prevents broader regional fallback. |
| Equally specific conflicting routes | No transport offer; independent alert explains that the merchant must resolve ambiguous routes. |
| Non-Greek delivery country | No transport offer; delivery-step alert says service is in Greece only. |
| Invalid but nonzero address ID | No transport offer; delivery-step alert says the address could not be loaded. |
| No delivery address chosen | No transport offer and no route-specific warning while the buyer is still entering an address. |

All reasons derive from the existing route matcher and `quoteResult()` and are mapped to copy once in the module. The quote, order charge, and delivery-option rejection paths are not forked to serve the UI. Existing installations register the new hook through the idempotent `upgrade-0.5.1.php`; clean installs register it during installation.

**Acceptance evidence/limits:** A real PrestaShop 9.2.1 cart-quote integration check confirms matched-address silence and unmatched-address alert markup. The Hummingbird Chromium checkout test verifies the **visible** matched pickup notice, switches the selected Greek delivery address from priced Naxos (84300) to unmatched 99999, asserts a **visible styled alert in the Shipping Method step**, verifies the transport option is absent, and reloads the unmatched checkout to confirm the warning persists. This is actual DOM visibility/computed styling, not a hook-output-only test. Other shop themes, mobile layout, multishipping, and direct HTTP requests are not covered by this browser acceptance.


## Matching, fare input and pinned-quote expiry (issues #45–#47)

**Greek city names (#45).** Destination-city matching uses a comparison key only: it folds Greek uppercase, standard tonos/dialytika, decomposed Greek combining accents and final sigma (ς/σ), in addition to existing whitespace/case normalization. Stored route cities and customer address spelling remain unchanged; postal-prefix matching and Latin city behavior remain unchanged. Thus a city-only route for Νάξος (empty postal prefix) accepts Ναξος, ΝΑΞΟΣ or Νάξοσ, and Χαϊδάρι accepts ΧΑΪΔΑΡΙ. A genuinely different city such as Λάρισα is still unquoted. Two previously distinct stored spellings that now compare equal can compete at the **same specificity**. Existing price/station/notice/verification ambiguity checks continue to fail closed when their offer data differ; identical offers remain usable. These cases have explicit pure regression tests, tests against real PrestaShop Greek Addresses, and an actual Hummingbird customer checkout with an unmatched postcode.

**European price input (#46).** The route editor accepts EUR 12,50 and 12.50 through its existing text input and single \`RouteFields::normalize()\` validator; both become the numeric value 12.50. Empty, nonnumeric, multiple-separator, zero/negative and out-of-bounds amounts remain field-named errors. The positive amount maximum is **EUR 100,000**, defined only in the field spec (with a lower bound strictly above zero). This explicitly sets the permitted positive bound, replacing the prior overly broad 999999/zero-allowed validation. The admin's original submitted value survives a failed save. A real PrestaShop admin browser test checks a malformed \`12,5,0\` entry yields a named error and retained input, then enters \`16,25\` and sees the persisted \`16.25\` route.

**Pinned quote expiry (#47).** An *unconsumed* cart quote is held for \`CartQuote::TTL_SECONDS\` (1,800 seconds). A future request after its creation timestamp is older than this limit ignores it; a newly resolved eligible route overwrites and **re-pins** a current quote. If the route is now Suspended, no new price is offered. The saved quote for a successfully placed order is marked **consumed** and retained as an immutable historical repair source even when older than the checkout hold. The resolver and single pin/consume writers remain unchanged.

The deterministic disposable-shop regression tests use **the real persisted SQL quote row**, age \`created_at\` beyond the constant in the database (no 30-minute sleep), create a new module instance to model the next request, and verify both current price and suspension outcomes. The real native pending-cheque order smoke further proves EUR 29 remains held before expiry, re-quotes at a revised EUR 39 after expiry, and the actual order is charged/snapshotted EUR 39 with a consumed pinned quote; its historical quote remains readable after aging. **Coverage exclusions:** time travel is simulated at the database timestamp, not by waiting through a real 30-minute browser session; no simultaneous checkout tabs, multishipping, other DB engines, or payment-card gateways. The real browser tests cover Hummingbird on desktop and default BO styling, not all themes or touch devices.

## Multistore route ownership and demo admission policy (#51/#52)

The module explicitly uses **installation-global route data** with independently configured carrier identity/eligibility for each shop context. Editing, deleting or suspending a route in one shop affects quotes in all shops sharing this PrestaShop database, and the configuration page warns the operator at the point of editing. Verify country/zone and group carrier rules for *every shop* before enabling checkout; merely changing shop context does not isolate route records. This is a documented design decision, not a shop-scoped migration.

The ten seed entries remain **Available** by decision, for disposable demo visibility. They offer prices immediately if the carrier becomes active; the demo notice does not make an invented tariff safe for live selling. The admin alert explains this and directs operators to **Draft** unverified seeded entries or replace them with real operator-confirmed terms. Manually added routes still default Draft, and upgrades do not reset existing merchant state. The demo seed content intentionally remains English sample text, not an operator-verified Greek logistics notice.

## Full route scan scale contract (#53)

Checkout's first quote per cart loads the full route table and matches in PHP. The `lookup_prefix` index (repaired by the 0.5.2 upgrade) is part of the schema contract, but the present unfiltered query cannot use it to bound the scan. Shadowed-suspension warnings in the admin can be O(N²). The CI pure matcher verifies a **752-row synthetic dataset** with matching and shadow-warning invariants; the live disposable PrestaShop/Chromium suite covers a small ten-route demo dataset. Plan for **750 or fewer** merchant routes. This is a functional test envelope, not a measured response-time guarantee; real DB, theme and device performance were not benchmarked at 750 routes. Beyond it, add a truly bounded query that preserves Greek city normalization, specificity, ambiguity and suspension-veto rules and validate with a production-sized integration test.

## Module translations (0.5.3; #50)

The module now routes its built-in route editor strings and customer pickup/no-quote warnings through PrestaShop's classic `l()` mechanism and ships the `translations/el.php` dictionary. English remains the untranslated source and fallback; Greek text is shown only for the appropriate shop/employee language. Admin messages, labels and grid actions can be translated without changing stored route fields. Check that the Greek language is installed in PrestaShop, then open a Greek-language storefront to verify the pickup-only/no-home-delivery and demo warning as well as the unavailable-reason alert for a Greek address without a priced route. The real PrestaShop CI smoke exercises the installed dictionary; browser CI covers the actual checkout UI.

**Carrier platform constraint:** Core `carrier.name` is one global field, not a per-language carrier name. The stock name is therefore bilingual for every locale. `carrier_lang.delay` is truly per-language, and Greek gets the Greek pickup instruction. Upgrade 0.5.3 applies this conversion only to still-default carrier names/delays, without rewriting merchant customization. The ten English demo seed pickup station names and notices are sample route **data**, not module UI strings, and are intentionally unchanged; production merchants should replace them with verified operator/customer wording for their audience. Other merchant-authored notices stay as entered.


## Network model install/upgrade and acceptance (0.6.0; #64–#70)

**Important:** Module ZIP must contain the entire module folder, including the locally bundled Leaflet assets used by the customer quote page and the new network-admin map. No external JavaScript CDN is required; OpenStreetMap tiles still require map-tile access.

1. Upgrade by copying/uploading the 0.6.0 module files and running **Module Manager → Upgrade**; merely copying files does not execute migrations. A clean installation calls the same network schema installer.
2. Confirm the three new empty tables exist: `ps_grtrans_station`, `ps_grtrans_leg`, and `ps_grtrans_transfer` (replace `ps_` with the installation's database prefix). Existing flat Routes and order history must remain unchanged.
3. Open **Configure → Transport network · coverage map**. Create Athens bus and port stations, select map coordinates, set inner/outer radii and flag the departure station as an origin hub. Refresh and verify two rings and origin-hub label.
4. Create a priced directional bus leg to a second bus station and a ferry leg from a different port. Before creating a transfer, verify the graph has no connection between the bus station and the port.
5. On the map select the bus station, click **Link**, and select the port. For non-overlapping inner rings verify the taxi/type/price popup, save, and verify a separately drawn dashed transfer. For overlapping rings verify the free walk shortcut. Edit and delete a transfer in its table.
6. Create a free Athens direct-area courier leg with a circle as service area. It must not connect to a remote ferry destination by itself.
7. Generate a write token in Configure, copy it once, call the module `network` endpoint with `Authorization: Bearer ...` and verify station, leg and link JSON CRUD. Generate a read token and verify reads succeed but writes return 403. Rotate a token and verify its previous value returns 403.
8. Import JSON stations with temporary references and legs using those references, then compare the map and exported CSV to what the same records look like when entered via the forms. Invalid input must roll back, not partially import. Delete a station still referenced by a leg and verify the request is rejected.
9. After network setup, test an existing flat Route in checkout. The addition of network tables must not change flat-route prices or existing order snapshots before the dedicated cheapest-path integration PR (#67).

**Acceptance boundary:** Pure PHP 8.2/8.4 tests cover station/leg/transfer validation, graph topology, direct-area inclusion, API token scopes/rotation and CSV parsing. The map/editor are not yet covered by a real authenticated PrestaShop browser acceptance test; verify steps 3–8 on the disposable PrestaShop 9.2 rig before declaring this feature production-ready. Real parcel tariffs, pickup agreements, and live route feasibility are operator obligations. Issues #74 and #75 remain post-first-release.


## Cheapest-path quote / simulator acceptance (0.6.1; #67, #77)

1. In a disposable PrestaShop 9.2 shop, set up two origins, two overlapping destination station rings, and alternative priced paths. Supply a verified WGS84 destination coordinate (the standard address class alone has none). Confirm the cheaper **complete** path is quoted even if the destination station is slightly farther away.
2. Add a strictly cheaper service leg; after the cart's existing 30-minute quote expires or a new address is used, confirm the displayed price changes. A pinned quote/order must not be silently rewritten.
3. Place a bus terminal and port near each other but do not add a transfer link. Assert the network cannot quote across the gap. Explicitly add the connection with its price; check both the per-edge simulator breakdown and checkout price.
4. Test the overlap of a free direct-area courier with a station route in Athens. The complete free route should win; an island address must never receive a courier-to-ferry concatenation without a real link.
5. Test a customer destination in the inner station ring, another in the outer-only ring, and an uncovered area. Outer-only renders **EXTENDED PICKUP AREA — LONG WALK** plus the configurable text; uncovered uses the pre-existing flat-route fallback or hides the carrier.
6. Open the **Network quote simulator**, enter station ID or postcode/city with known coordinates and the same parcel inputs as checkout, and verify hub, station, ring, edge list, per-edge cost and final total agree.
7. Confirm flat Routes remain deliberate overrides and suspended/ambiguous destinations never silently bypass their safety state via network matching.

**Important integration limitation:** PrestaShop's standard address stores city and postcode, not coordinates. The network engine does not use an undocumented external geocoder; without a trusted coordinate, ordinary checkout retains flat-route pricing. The customer quote-page map pin can supply coordinates. Do not claim full postcode-to-coverage parity on a standard address-only shop until the owner supplies a verified coordinate source. This limitation is explicit in the PR and operator guide.


## Variable tariff and estimated freight verification (0.7.0; #71/#72)

1. Create flat, weight-per-kg and cubic-metre legs with verified base prices. In the disposable shop use two products of distinct weight/dimensions and different quantities; calculate the expected sum by hand. **Shop product dimensions must be in centimetres** for this iteration's m³ conversion. Check that missing weight or one missing dimension causes a conspicuous flat-fallback warning; with no positive fallback the leg must not quote.
2. Mark one leg an **estimate** with a meaningful noncollapsed low/high factor; check the chosen complete path's upper bound drives selection, and both lower and upper estimates include every service leg and transfer. It must not be presented as an exact quote.
3. With a real PrestaShop test cart and payment-method stub, select the estimated carrier: **the actual shipping charge must be €0, including handling fee**. Carrier extra content must explain the range and clearly say the final freight is **not charged at checkout**. Do not interpret the native €0 carrier label as free freight.
4. Place an order in the disposable shop. Verify the order's `grtrans_order.route_snapshot` JSON has the complete origin, destination station/area, each service/transfer, its values and measurement inputs, plus `estimate_min`, `estimate_max` and pending state. Edit every underlying leg, station and tariff afterward and verify both admin/customer original journey displays remain identical.
5. Confirm a separate external payment in the disposable environment, then provide its transaction reference, final EUR amount and explicit customer approval in the admin order view. Verify the stored settlement status, amount, ref and audit log. Repeating the same receipt must be idempotent; a different receipt must be rejected; a settled estimate must not be regenerated.
6. If the confirmed actual amount exceeds the original max, reject the receipt pending fresh approval. Never record a payment unless an actual external processor has confirmed capture. The carrier module **does not capture funds itself**, and a gateway-specific integration remains necessary to do so in-store.
7. For an exact flat tariff, verify checkout still charges the historical amount with its eligible handling fee and all previous order charge-divergence and free-shipping voucher tests continue to pass.

The pure PHP `network_tariff_test.php` covers rates, ranges, cart dimensions, missing measurements, snapshot JSON and separate-payment validation. The real PrestaShop 9.2 CI job checks existing exact-shipping behavior; **a complete external second-payment capture is not simulated or claimed.**

## Configurable defaults and customer map radius upgrade (0.7.1)

1. Upgrade to module 0.7.1 and confirm all eleven new `GRTRANS_...` settings get their documented defaults without visiting Configure. Re-running the upgrade must preserve saved operator values.
2. In Configure change **Customer map-pin matching radius** from 25 km to 10 km. On the customer quote-check page, verify the help text displays 10 km, a pin just inside the radius returns the nearest catalogue station, a pin just outside does not, and equal-distance tie ordering remains the same. Reset to 25 km, verify the original boundary.
3. Change inner/outer station **form defaults** and confirm new records prefill them, but existing station records do not move. Verify an invalid outer smaller than inner is refused.
4. Set an extra nearby-link gap and verify the map's walk-link shortcut honors the gap, while far-apart stations still require an explicit priced/type popup. An ordinary missing link must never be guessed by the quote graph.
5. Change an estimate multiplier, product-unit conversion or quote hold in the disposable shop; check new rates and new holds follow the setting, with already consumed quote/order history preserved. Restore defaults.
6. Invalid bounds, noninteger hold minutes, lower factor ≥ upper, or radius defaults greater than the allowed maximum must be rejected server-side with no partial configuration save.

These tests are separate from the original #62 static quote-page acceptance. The same changes are covered by `tests/network_settings_test.php` on both PHP CI versions, but the real PrestaShop browser/admin checks remain part of post-merge shop acceptance.

## Leg delivery-time window acceptance (0.8.0; #73)

1. Upgrade to 0.8.0; confirm four new fields (`lead_hours`, `preparation_hours`, `transit_min_hours`, `transit_max_hours`) exist on `grtrans_leg`, default to zero, and migrate without changing prices or historical orders. Repeated upgrade must succeed.
2. Define a two-leg route with a preparation, lead and min–max transit time on both legs; confirm each field appears and is editable through the same API and admin forms as other leg attributes.
3. In the admin simulator verify the total earliest/latest hours. Verify exactly the same window accompanies the fare at checkout, on the customer quote page and on order confirmation.
4. Edit a leg's lead hours; a fresh quote must change its delivery window without changing its price. Previously placed order snapshot and time must not change.
5. Disable **Show delivery-time windows (0=off)**; all storefront/order/simulator notices must hide the window. Price and preserved history remain the same. Re-enable to restore new quote timing.
6. Unconfigured zero transit must not make a misleading zero-hour delivery promise. If the quoted path includes transfers whose time is not recorded by #73, show **Transfer travel time not included** as a limitation.

Pure regression checks are in `tests/network_timing_test.php` and the PHP 8.2/8.4 CI matrix. Manual real-shop browser testing is required for visual acceptance on the merchant's theme.

## Data verification/staleness upgrade and acceptance (0.9.0; #76)

1. Upgrade from 0.8.0 to 0.9.0. Check nullable `verified_at` on both leg and transfer tables. Unverified old rows remain NULL, not stamped with a fabricated upgrade date; retrying migration is safe.
2. Create a leg and transfer through the admin and API. Check automatic current UTC timestamp. Modify the price/details and confirm the timestamp refreshes. Use **Confirm current** for an unchanged record; check the same via write-token `POST ?entity=legs&id=N&confirm=1` and read-token rejection.
3. Set **Stale after months** to a small value, use a date older than the threshold in the disposable test database, and check the coverage line/area fades with an explicit stale tooltip. Newly confirmed services remain vivid; exactly-on-threshold timestamps are not stale.
4. Disable **Show stale map markings** (0). Every faded style/STALE label disappears from the map, but original dates and prices must remain identical in data/API.
5. Confirm historical order snapshots and checkout prices are unaffected by the visualization settings.

`tests/network_freshness_test.php` covers missing/old/fresh timestamps, calendar-month threshold, turn-off, and explicit confirmation without changing other data. Browser appearance verification remains a separate real-Shop acceptance step.

## Optional branch stock / cart origins (0.10.0; #78)

1. Upgrade the module to 0.10.0. Verify the new `grtrans_branch` and `grtrans_branch_stock` tables and their indexes; existing PrestaShop `stock_available` and existing route/order data must remain unchanged. Reinstall/upgrade retry is safe and creates **zero** branches by default.
2. Before configuring branches, test the same known network cart/address; its cheapest origin and price remain exactly as before.
3. Mark two station records as origin hubs. Create *Athens* and *Thessaloniki* branches, each linked to one hub, in the module's Branches admin editor. Both must belong to the **current shop**. A non-hub station ID must be rejected.
4. Enter product A quantity 5 in Athens and product B combination X quantity 3 in Thessaloniki, both under Branch Stock. Verify new stock entries are visible via `GET ?entity=branch_stock` and CSV, and that the read-token cannot modify them.
5. Quote A-only and B-only carts. The selected path must begin at the corresponding branch station and the computed amounts should differ when the two hub paths have different prices. The admin simulator using the same **Cart ID** must return the same origin/price. The placed order snapshot must include branch ID/name.
6. Quote a cart with A+B where no single branch has both. The network carrier must not offer a route, but manually priced flat Routes/other carriers remain unaffected. No automatic split shipments.
7. Verify exact `id_product_attribute`, not the product's combined stock: a different combination or insufficient quantity must make the branch ineligible. Changing the cart's items or quantities must invalidate its old pinned quote fingerprint.
8. In a second shop under the same PrestaShop installation, confirm the first shop's branches/quantities are invisible and that empty branch configuration retains the availability-blind origin model. The shared stations/legs themselves remain installation-wide.
9. Use the JSON bulk API to import a branch with `client_ref` and a stock row using `branch_ref`; verify all-or-nothing handling of an invalid row, including cross-shop record IDs. Confirm branch inventory is a separate record system and is **not** deducted by PrestaShop core on sale.

The pure regression suite `tests/network_branch_test.php` exercises distinct products/combinations, full-cart eligibility, changing cheapest origin, no splits, fallback and simulator parity. Additional real PrestaShop 9.2 rig tests are required for admin forms/API/whole-cart checkout and multistore isolation.
