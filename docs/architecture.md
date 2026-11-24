# Architecture and data flow

This living orientation describes the PrestaShop 9 module at the 0.16.0 codebase. Keep it aligned with changes to subsystem boundaries, authentication, persisted data or deployment. The [operator guide](network-operator-guide.md) is the UI/API procedure; the [installation and acceptance guide](INSTALL-AND-ACCEPTANCE.md) records verification.

## Components

All paths below are relative to `grtransportshipping/` unless explicitly noted.

```text
PrestaShop back office (employee session + signed form token)
  -> grtransportshipping.php::getContent()
     -> RouteFields + RouteRepository
     -> NetworkAdmin -> NetworkFields + NetworkRepository
     -> NetworkSettings / NetworkSimulator
     -> HTML record lists, forms, map data
        -> configure-admin.js / compact-records.js / crm-records.js
        -> map-workspace.js / map-spatial-editor.js / network-admin.js
        -> Leaflet + coverage-geometry.js
             -> external map tiles / routing / postcode lookup

Authorized API client (scoped Bearer token)
  -> controllers/front/network.php -> NetworkApi
     -> NetworkRepository (same validated persistence)

Guest -> controllers/front/quote.php -> customerQuoteCheck / QuoteCheck
PrestaShop Cart -> getOrderShippingCostExternal -> quoteResult
Admin simulator -> NetworkSimulator
  -> flat QuoteEngine + NetworkQuote
     -> NetworkGraph + NetworkGeometry + NetworkTariff + NetworkTiming
     -> BranchAvailability + NetworkResolution
  -> quote / explicit unavailable reason
  -> checkout only: CartQuote -> OrderCharge -> native carrier price
     -> FarZoneConsent (if required)
     -> hookActionValidateOrder -> OrderSnapshot + CartQuote consume
     -> order displays / EstimateSettlement (external receipt)
```

## Configuration and validation

- `grtransportshipping.php` is the CarrierModule lifecycle, hook adapter and Configure entrypoint. `CarrierLifecycle.php` resolves the active carrier in the shop context.
- `RouteFields.php` defines flat-route fields; `RouteRepository.php` validates and persists routes, including seed rows. `RouteState.php` describes route availability. Draft is not sellable, Available is sellable, and Suspended vetoes broader matches.
- `NetworkFields.php` / `NetworkRepository.php` own validated stations, legs, transfers, branches and branch stock. `NetworkAdmin.php` receives authenticated admin actions. Creation returns HTTP 303 to a signed GET, preventing refresh replay; invalid submissions retain draft inputs. API import and UI edits share repository validation; bulk import uses a transaction.
- `NetworkSettings.php` defines operating defaults and validated bounds. Grouped autosave sends a signed form and reports server confirmation or failure. Settings persistence is not documented as atomic until #138 is resolved.
- `views/js/map-workspace.js` coordinates category navigation, selection, map camera and record forms. `map-spatial-editor.js` manages tentative geometry; save uses the existing form. `crm-records.js` handles signed inline edits/column choices; the server remains authoritative. No client-side visual representation establishes price or persistence.

## Quote and checkout flow

1. Flat manual matches use `QuoteEngine::resolve()`. A quoted manual route wins; suspended or ambiguous rules veto the network fallback. Network fallback requires valid geographic coordinates and reachable explicit directed legs/transfers. Direct-area services are terminal, not invented feeder legs.
2. `NetworkTariff.php` applies parcel rates and fallback; `NetworkGraph.php` evaluates reachable paths and geographical candidates using `NetworkGeometry.php`. `NetworkResolution.php` applies explicit overlap/link precedence before cheapest-reachable selection. `NetworkTiming.php` supplies time windows where available.
3. `BranchAvailability.php` restricts origins to branches able to fulfil the whole exact cart; no split order is invented. A public quote without a cart cannot prove branch stock. `NetworkSimulator.php` can test address or station and optionally a cart.
4. `quoteResult()` builds an address/cart fingerprint, checks an existing `CartQuote` and rechecks network-path and branch eligibility. A fresh quote is resolved server-side and pinned with its handling-fee adjustment. Quote reuse must honor configured expiry (#141); consumed pins are retained for audit/repair. No offer returns false to the carrier, with a separate unavailable-reason hook.
5. `OrderCharge.php` computes the actual checkout shipping amount. Estimated freight uses a disclosed range and zero shipping charge at checkout, not a fictional exact payment. `FarZoneConsent.php` binds affirmative FAR consent to cart, delivery address and quote; order validation checks it independently of browser JavaScript.
6. `OrderSnapshot.php` freezes the selected order-time route and pickup information from the pin. Order displays use this snapshot, not current tariffs. Repair requires the original cart quote; it is not a live recomputation. `EstimateSettlement.php` records independently confirmed external receipts, not payment capture.

## Data ownership and scope

Table names below omit the PrestaShop installation prefix.

| Data | Owner | Scope / lifecycle |
| --- | --- | --- |
| `grtrans_route` | RouteRepository | Installation-global flat routes; seed once, do not re-seed deleted merchant records |
| `grtrans_station`, `grtrans_leg`, `grtrans_transfer` | NetworkRepository | Installation-global transport topology; explicit dependent-link deletion guards |
| `grtrans_branch`, `grtrans_branch_stock` | NetworkRepository / BranchAvailability | Current shop (`id_shop`); product/combination dispatch capacity, not PrestaShop stock ledger |
| `grtrans_cart_quote` | CartQuote | Cart/address/fingerprint; pinned then consumed; expiry/prune only unconsumed pins |
| `grtrans_order` | OrderSnapshot / EstimateSettlement | Order-time frozen route; settlement metadata separate from original quote |
| PrestaShop Configuration / carrier | NetworkSettings / NetworkApi / CarrierLifecycle | Operate using PrestaShop shop context and configuration semantics; check each shop's carrier eligibility; never assume route topology is isolated by switching shop |

## Map and trust boundaries

The back-office renderer consumes saved station, service, geometry and server resolution data. Source circles/polygon rings remain authoritative visual geometry. Coverage layers, local transfer seams and road/sea/air symbols have distinct meanings. The map's route-preview helper is not the sale-authorizing checkout resolver; parity gaps are tracked in #139 and #140. The public map exposes only the selected customer pin, not the network dataset.

- Back-office mutations require an employee and valid signed token; API uses independent read/write Bearer tokens with hash-only storage and one-time display of rotated plaintext. Keep secrets out of URLs, logs and repository files.
- Public quote checks need no account or cart and do not create a checkout pin. Public inputs are validated and output escaped; note transport privacy is tracked in #153. FAR checkout confirmation is separate and signed.
- Browser integrations include OpenStreetMap tile requests, OSRM road geometry and geographic lookup services; hosts receive coordinates/tile parameters and normal request metadata. The customer pin uses Nominatim reverse geocoding after map interaction. Bundled Leaflet does not require remote script execution. Provider outages affect visualization/lookup; no external tile or routing response authorizes a price. The saved connection renderer retains stored geometry or a visible straight fallback; the interactive route-preview error is distinct.
- Do not treat demo data, external map availability or a successful quote simulation as verified freight contracts.

## Build, upgrade and deployment

Repository workflows: `.github/workflows/ci.yml` (PHP lint/contracts and Node tests), `prestashop-install.yml` (disposable installed shop), `browser-admin.yml` (authenticated Chromium and checkout), `package.yml` (installable module ZIP). Evaluate the exact commit's results and exclusions, not merely a green historical run. Install/upgrade via Module Manager; the module has numbered PHP upgrade scripts under `upgrade/`, not a standalone web application. Copying files is insufficient for schema migrations. Preserve merchant data and order snapshots, and confirm the module version and actual schema after upgrade. The Coolify demo is a separate hosted PrestaShop instance, not the Git repository's module runtime; demo promotion/recovery instructions are tracked in #150. A Git merge alone does not prove that the module is deployed.

Release checks still include merchant-specific tariffs, shop/theme/payment verification, historical migration coverage and explicit demo acceptance. See the acceptance guide rather than assuming every workflow is automated.
