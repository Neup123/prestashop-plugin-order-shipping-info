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
