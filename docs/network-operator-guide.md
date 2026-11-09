# grtransportshipping — operator guide

This is the English operator guide for the PrestaShop 9 transport module. All prices are unaccompanied parcel prices in EUR. Demo fares are **invented** and are not valid transport contracts.

## Contents

1. [Installation and existing flat Routes](#installation-and-existing-flat-routes)
2. [Stations and two coverage rings (#64)](#stations-and-two-coverage-rings-64)
3. [Service legs and direct area services (#65)](#service-legs-and-direct-area-services-65)
4. [Transfer links (#66)](#transfer-links-66)
5. [Visual coverage map (#68)](#visual-coverage-map-68)
6. [Network CRUD and import API (#70)](#network-crud-and-import-api-70)
7. [Safety and troubleshooting](#safety-and-troubleshooting)

## Installation and existing flat Routes

Install `grtransportshipping` from its module ZIP using **Module Manager**. Confirm its carrier is active for Greek delivery zones and the correct customer groups. Fresh installations seed ten illustrative flat Routes; **mark demo routes Draft or replace them with verified freight terms before live checkout**. To upgrade an existing installation, upload the module files and select **Module Manager → Upgrade**. Version 0.6.0 creates three empty network tables without modifying merchant flat Routes or historical orders. See [Install and acceptance](INSTALL-AND-ACCEPTANCE.md) for step-by-step checks.

Under **Configure → Greek public transport routes**, flat Routes still support manually priced destinations. Enter a mode, origin, destination pickup station, city and/or postcode prefix, price, and notice. **Draft** does not quote, **Available** can quote, **Suspended** blocks broader matching rules. The route edit form retains values after validation failures. Deletion requires confirmation. The checkout carrier has a pickup-only warning where relevant, and missing routes do not show a false price. A successful cart quote is pinned for up to 30 minutes; historical pickup details on an order are saved independently from later route edits.

## Stations and two coverage rings (#64)

At **Configure → Transport network · coverage map**, select **Stations** in the Manage toolbar. Add a station with a name, bus/ferry/airport/other type, postal address text, and location. Enter WGS84 latitude/longitude or click the map to populate the coordinate fields. Set **Inner radius km** (normal walking distance) and **Outer radius km** (extended coverage, longer walk). The outer radius must be positive and at least as large as the inner. The station immediately appears as a strong inner disc and faint outer disc after saving and refreshing the map.

Select **Is origin hub** for any station where the shop can dispatch parcels. Several origin hubs may coexist; the customer never chooses one. A station's map coverage indicates candidate geography, not an actual freight service; a priced path must exist before a network quote can be offered.

## Service legs and direct area services (#65)

Select **Legs**, choose **From station**, **To station**, mode (bus, ferry, flight or other), operator/line label, EUR price and whether the service runs in both directions. One leg is a priced journey. Two legs A→B and B→C can connect at shared station B, but **no travel is invented between distinct stations**.

For a local courier delivering to an address, choose **Direct area (no pickup station)** as To station. Enter an area name, centre latitude/longitude and radius. A map click can fill the centre fields. This is a terminal service: its coverage area is drawn on the map, and a price of **€0** is valid. It does not create a feeder to a ferry or flight. Existing flat Routes remain in their original table.

## Transfer links (#66)

A transfer carries a parcel physically between separate stations (for example a bus terminal and a port). On the coverage map, tap the first station, press **Link selected station**, then tap the second station:

- When the **inner rings overlap**, the current conservative definition of “nearby”, a free `walk` link is created directly.
- Otherwise a small form appears. Enter the real connection type, such as `taxi`, and its price (zero is allowed), then save. A priced transfer appears as a **short local dashed stitch** near its station patches, not a line across the journey. When patches are separated, the two short facing-rim marks leave the uncovered gap blank.
- Links have a direction from first to second. To enable reverse travel, define a reverse transfer. Use the **Transfers** grid to edit or remove links.

The issue does not specify a numerical near/far threshold. Using overlapping inner walking rings avoids assuming a fixed walking distance that would be unsafe in every location.

## Visual coverage map (#68)

The operator map uses bundled **Leaflet**, OpenStreetMap tiles and the dedicated `grtrans-coverage-patches` pane (z-index 390). Its visual acceptance reference is `docs/map-model/connection-model.svg` (panels A–I, v8) and the owner's original sketch.

**A — Source-defined reach areas.** Stations now accept `coverage_kind` (`radius`, `postal_polygon`, `walking_isochrone`) plus optional validated WGS84 `coverage_geojson` (`Polygon`/`MultiPolygon`). Dynamic direct-area services have `area_coverage_kind` and `area_coverage_geojson`. Stored postal and isochrone polygons are drawn using their actual vertices, including coverage islands/holes; a saved radius remains a circle, softened visually. The **quote engine also tests polygon membership** (including hole exclusion), so the map is not a decoration that disagrees with prices. No postal dataset or walking isochrone is invented: import actual source geometry when available. An idempotent `upgrade-0.11.0.php` migration adds the fields to existing tables; old records default to radius.

**B — Overlap without a link.** Unrelated overlapping patches simply blend. There is no stitch, line or inferred service relation.

**C — Priced transfer seam.** A priced link across touching areas marks the transition with a short **zigzag on the winning reach boundary**, entirely inside the linked overlap **only if an actual winner-to-loser boundary exists**. Today the map's resolver uses cheapest reachable station cost to identify the winning boundary; a tie has no cheaper-to-dearer transfer and adds no seam. **The renderer consumes a resolution result** (`boundaryOwner` or an explicit `boundaryShape`), not a hard-coded winner comparison. A future override-aware resolver can provide a different winner under issue #107 without changing the drawing code; **#107 itself is not implemented here**. The local mark has total length at most **1.8 km**, 1.25px width, 55% opacity, `2 5` dash, no ticks, and a larger invisible tooltip target. Without a valid saved priced link, there is no seam. The old broad #99 sewn ribbons and paired rim lines are removed.

**D — Air.** Distant airport patches have a symbolic **dashed true semicircular arc** and arrowhead toward the destination (two arrowheads if bidirectional). Hovering the arc or either endpoint darkens both endpoint patches together, but the air line does not assert corridor coverage.

**E — Sea.** Each individually priced ferry origin–destination record produces a **straight dashed connection**, clipped at its endpoint patches, with arrow and pair-scoped mental hover. Distinct prices/destinations are distinct vessels, never a merged fan-out. The origin only anchors boarding; the sea leg has no area reach.

**F — Station-bound land at equal price.** Overlapping stations connected by a saved same-service land leg **merge into one union patch**: the former four station circle paths (two rings each) become **one outer union silhouette with zero buried/internal arcs**. Panel I adds a separate, borderless normal-tier inner fill. Equal-cost links do **not** draw a fake zigzag within the merged service; there is no price handover to mark. The continuous transport line follows persisted `route_geojson` where supplied; otherwise it asks OSRM for the real road, falling back to a straight station-to-station line only when the road is unavailable. Its opacity is higher than the patches and its colour matches the service. The transport line never fills a route-length coverage strip.

**G — Station-bound land at different prices.** Different station costs prevent patch merging. A segmented **continuous colour gradient** along the actual/derived road darkens toward the higher-priced station, while the two patches stay independently visible.

**H — Dynamic vehicles (v7 owner precision).** A priced taxi/courier direct-area service is one **unbroken coverage sea**. Other mode patches are painted as translucent islands above it: the sea fill still visibly shows inside each island; there are no holes or subtractive masks. An island **explicitly linked** by that direct-area priced leg has a **full-circumference closed zigzag stitch** around its outer edge, not a short point mark. The special full-perimeter seam may exceed 1.8 km because it traces the entire island; its width/opacity/dash and tooltips remain subtle. Unlinked islands retain a plain, unstitched edge. Mere containment does not manufacture a priced link.

**I — Normal service and FAR-zone tiers (v8).** Each station's saved `inner_radius_km` and `outer_radius_km` define a **normal inner reach** and a **lighter, longer-distance FAR spread**. Rendering is **tier-driven**, with the normal fill denser and far fill lighter. For polygons, normal reach is clipped to the actual stored footprint; pricing uses the same existing inner/outer radius classification after checking polygon coverage. The customer quote page warns **“Far from the station”** and requires an explicit checkbox and **Proceed** action before displaying the far-zone offer. At checkout, a separate explicit checkbox is saved via a signed same-origin POST tied to the cart, delivery address and pinned quote. Changing any of them invalidates the saved consent. JavaScript blocks ordinary delivery-step continuation while unconfirmed, and **server-side order validation independently refuses** an unconfirmed far-zone transport order. Normal-zone addresses do not require approval. The tier model can grow without rewriting the polygon painter.

**Operational boundaries.** The admin preview can deliberately show road/ferry routing while hiding the symbolic connection layers, without hiding coverage patches. No new remote JavaScript library was introduced. Service geometry is editable as validated GeoJSON and is never silently generated from the station name. The checkout's warning/confirmation is a user choice about a far-zone route, not an assertion of home delivery or a new freight charge.

**Origin pins and routed preview:** dispatch/origin stations have visually distinct admin-only pins. Press **Preview route between two pins**, then click two map points or station markers. Pins must be inside a stored station's outer radius; the preview follows valid directed legs/transfers, preferring the lowest configured price. Road segments use on-demand OSRM driving geometry; ferry segments are dashed port-to-port approximations, **not** recorded sailing tracks or schedules. If OSRM is unavailable or a directed path cannot be calculated, the status gives a visible error with **no straight-line fallback**. Flight geometry and unconnected direct-area services cannot be previewed. The schematic seam overlays are hidden during preview and return on **Clear preview**. This preview does not save routes or alter pricing.

Edits made through the form or API appear on refresh. The map is optional: all data can also be managed in the tables or through the API.

## Network CRUD and import API (#70)

Generate credentials in **Configure → Programmatic API credentials**. **Read** and **write** tokens are independent. The plaintext is shown once, and only its hash is retained. Generating a replacement immediately revokes the old token. Write tokens are administrative credentials; keep them secret. Use HTTPS and the HTTP header `Authorization: Bearer <token>`; query-string tokens are not accepted.

Base URL, using your shop hostname/subdirectory:

`https://shop.example/index.php?fc=module&module=grtransportshipping&controller=network`

| Request | Operation | Token |
|---|---|---|
| `GET` (no entity) | Full network snapshot | read/write |
| `GET ?entity=stations` | List stations (also legs, transfers) | read/write |
| `GET ?entity=stations&id=4` | Read record | read/write |
| `GET ?entity=stations&format=csv` | CSV export | read/write |
| `POST ?entity=stations` | Create from JSON object | write |
| `PATCH ?entity=stations&id=4` | Update supplied fields | write |
| `DELETE ?entity=stations&id=4` | Delete (if unreferenced) | write |
| `POST ?bulk=1` | Atomic JSON bulk import | write |
| `POST ?entity=legs` with `text/csv` body | Atomic CSV import | write |

JSON batch example:

```json
{
  "stations": [
    {"client_ref":"athens","name":"Athens hub","type":"bus","address_text":"Athens","lat":37.99,"lng":23.70,"inner_radius_km":1,"outer_radius_km":5,"is_origin_hub":1}
  ],
  "legs": [
    {"from_ref":"athens","to_station_id":12,"mode":"bus","operator_label":"Bus A","price":10,"bidirectional":0}
  ]
}
```

Temporary station `client_ref` aliases can be referenced by `from_ref`/`to_ref` in the same batch. Only a complete, validated batch is committed. CSV headers use the exact field names from the API. Unknown station IDs, negative prices, bad coordinates, invalid radii and self-links are rejected. A station with dependent legs/transfers must have those removed before deletion.

These tables, like flat Routes, are shared across shops in a multistore installation.

## Safety and troubleshooting

An address with no actual directed transport path must not receive a made-up network quote. In particular, geographic proximity does not give permission to transfer a parcel between terminals. Confirm physical arrangements with the operator and enter only proven legs/links. Test unfamiliar routes in a disposable PrestaShop shop before real customers use them.

If the map is unavailable, use the table forms or API. If an API request returns 403, verify the Bearer header, token scope, and whether an administrator rotated the token. If a station does not appear, check its coordinates and ring radii; if a connection is missing, inspect its references and direction.

Later PRs extend these instructions with the cheapest-path checkout engine, variable tariffs, snapshots, delivery timing, data freshness and branch-aware origins. Parcel tracking/status (#74) and station contact fields (#75) remain explicitly post-first-release.

## Cheapest-route quotes and simulator (#67, #77)

A network quote is computed from origin hubs across explicitly configured **directed** service legs and transfer links, summing every edge's price. When several origin hubs or destination-station coverage rings overlap, the cheapest **complete** valid route wins. Direct-area couriers compete only if the address is within their delivery circle and the service can be reached from an origin hub; they never become unconfigured feeder routes. Adding a cheaper leg can change the quote without editing destination prices.

**Manual flat Routes remain overrides:** an available flat matching row wins even when a network alternative is cheaper; a Suspended or ambiguous flat result cannot be bypassed through the network. When the network has no valid route, existing postcode-prefix/city matching applies as before.

**Geography and address safety:** standard PrestaShop delivery addresses only have city/postcode, not guaranteed coordinates. This module intentionally does not guess coordinates or send customer addresses to an external geocoder. The network resolver operates when a trustworthy WGS84 destination coordinate is provided through an address extension or the quote-page map pin. Otherwise the existing flat Routes matching remains responsible. Invalid or distant pins are not matched to a fictional station. The customer quote page's legacy demo terminal mapping remains supported. This limits network-geography coverage for shops without a source of customer coordinates, and should be verified on the target shop before network service is enabled.

**Long walk warning:** a destination inside an outer station radius but outside all inner rings shows a prominent extended pickup warning in checkout, sourced from **Configure → Extended pickup notice**. Leave the field empty for the built-in English explanation. Inner-ring quotes have no long-walk warning.

**Admin quote simulator:** open **Configure → Network quote simulator**. Select a destination station to test its exact path or supply a destination postcode/city and verified latitude/longitude. Parcel weight/volume inputs are shown for preparation; tariff calculation from these fields arrives in #71. Results show the winning hub, ring, station or direct area, per-leg and per-transfer price, and total EUR. A no-path result is explicit. The simulator calls the same PHP resolver as checkout, so path costs never rely on UI-only logic.

A network quote is pinned in the existing cart quote store with its selected path and handling fee. The historical full-edge snapshot and estimate-handling upgrades arrive in #72. Test customer-facing addresses with the real PrestaShop 9.2 rig, not just the pure PHP tests.

## Parcel tariffs, estimates and frozen order charge (#71, #72)

### Configure tariff types on a service leg

Open **Transport network → Legs → Edit**. Each leg now has:

| Field | Meaning |
|---|---|
| `price` | Flat fare in EUR; for variable tariffs, the guaranteed fixed component |
| `tariff_type` | **flat**, **weight** (kg), or **volume** (cubic metres) |
| `rate_per_unit` | Additional EUR per kg or per m³ for weight/volume tariffs |
| `estimate` | Operator has not confirmed an exact freight amount |
| `estimate_low_factor` | Multiplier for the lower plausible leg cost (default 0.90) |
| `estimate_high_factor` | Multiplier for the upper plausible leg cost (default 1.10) |

For example, a weight leg with base €4 + €2/kg for a 3 kg parcel is €10. If marked as an estimate with lower factor 0.8 and upper factor 1.2, it contributes **€8–€12**. The graph compares estimate paths conservatively by the **upper bound**, while showing both bounds to the customer. Fixed-price transfers are added to both bounds.

**Product measurements:** the module totals the cart's product weights and uses product width × height × depth × quantity to compute volume. The volume conversion assumes the shop's product dimension unit is **centimetres**; if another unit is configured, these rates must not be enabled before adapting the conversion. If any relevant product is missing weight or dimensions, the incomplete measurement is not treated as zero. The leg uses only its configured positive flat component, marks the quote as a **missing-measurement fallback**, and displays a warning; if there is no positive fallback, that leg is excluded.

### Estimate never becomes a confirmed charge at checkout

If any selected leg is marked estimate, checkout **charges €0 shipping**, and the pickup notice clearly says **SHIPPING ESTIMATE — NOT CHARGED AT CHECKOUT** with the low–high range in EUR. The carrier's native shipping price field can show €0 because PrestaShop requires a scalar price; this is not an assertion that freight is free. The more prominent carrier notice explains separate later freight collection. No invented exact charge or automatic price capture is performed.

The merchant must obtain the **customer's explicit approval** of the confirmed freight amount and use a separately supported payment method to collect it. In the administrator's order view, use **Record confirmed external freight receipt** only after real payment has been confirmed. Enter the exact amount and a genuine external transaction ID, and confirm customer approval. Duplicate/different receipts are rejected; an amount above the original estimated ceiling requires new customer approval and must not be silently recorded. This module only records the external receipt; **it is not itself a payment gateway and cannot capture card funds.** Do not confuse an administrator's receipt entry with a payment action.

### Immutable original dispatch details

When the order is created, the full selected path (origin hub, station/area, service legs and transfers, per-leg price components, parcel inputs, notice and range) is copied from the pinned checkout quote into `grtrans_order.route_snapshot` as JSON. It is never derived from today's merchant-edited network. The order admin and customer details show the **order-time route**. Settlement stores the independently received actual amount, receipt reference and time in separate columns without rewriting the original priced path or checkout amount.

Existing legacy **Regenerate original pickup snapshot** remains available for previous flat-route orders, sourced only from the original cart quote; a **settled estimate** cannot be regenerated. Repair of missing historical data also requires its original pin, never current network tariff values.

### Preconditions before live use

**Do not promise automated payment capture:** the current implementation safely records a verified payment externally, but cannot initiate a second charge through an arbitrary shop payment gateway. To capture the charge inside the shop, a merchant-specific gateway integration and customer authorization flow are required. The exact constraint is recorded in the PR.

Review [installation and acceptance](INSTALL-AND-ACCEPTANCE.md) for the test matrix. Tracking/pickup status notifications (#74) remain post-first-release.

## Configurable operating defaults (owner decision, 2026-11-09)

Open **Module Manager → Greek public transport shipping → Configure → Transport network operating defaults**. All settings are initialized automatically on install/upgrade; the defaults apply even if an older installation has no configuration row. This is an **operator customization**, not a first-run requirement. Changing a default never rewrites existing stations, pinned checkout quotes or frozen orders.

| Setting / Configuration key | Default | Purpose |
|---|---:|---|
| Customer map-pin matching radius · `GRTRANS_PIN_RADIUS_KM` | **25 km** | Public customer map pin chooses the nearest catalogued demo station within the inclusive radius; ties retain catalogue order |
| New station inner ring · `GRTRANS_STATION_INNER_KM` | **1 km** | Initial inner walking coverage on new station form |
| New station outer ring · `GRTRANS_STATION_OUTER_KM` | **5 km** | Initial extended-service radius on new station form |
| Maximum allowed network radius · `GRTRANS_MAX_COVERAGE_KM` | **500 km** | Upper input bound for station/area coverage, not the actual radius of any station |
| Extra nearby transfer gap · `GRTRANS_NEARBY_GAP_KM` | **0 km** | Free walk link when centres are within the sum of inner radii plus this additional gap; otherwise the operator defines transport type and price |
| Default estimate low factor · `GRTRANS_DEFAULT_ESTIMATE_LOW` | **0.9** | New estimate leg lower multiplier |
| Default estimate high factor · `GRTRANS_DEFAULT_ESTIMATE_HIGH` | **1.1** | New estimate leg upper multiplier |
| Cart quote hold · `GRTRANS_QUOTE_TTL_MIN` | **30 minutes** | Time until an unconsumed quote must be recomputed; previously consumed quotes stay immutable |
| Weight-unit conversion · `GRTRANS_WEIGHT_TO_KG` | **1** | Number of kilograms per product weight unit, for shops not storing products in kg |
| Dimension-unit conversion · `GRTRANS_DIMENSION_TO_CM` | **1** | Number of centimetres per product dimension unit; set to 2.54 for inches, 100 for metres |
| Maximum freight amount · `GRTRANS_MAX_FREIGHT_EUR` | **€100,000** | Operator-adjustable maximum for a leg or separately recorded freight receipt |

The **extended pickup notice** is separately editable, with a built-in safe English message if left blank. Existing PrestaShop **handling fees** use PrestaShop's own carrier/handling settings; there is no duplicated module default to configure. An individual leg's price, transfer fee and an individual station's radii are merchant **record values**, not global defaults.

Technical constants remain fixed: WGS84 latitude/longitude bounds, Haversine Earth radius 6371.0088 km, JSON/CSV request size and 1,000-row import caps, field lengths/DB numeric precision, price-rounding tolerance, and 100-record quote cleanup batches are protocol, resource or correctness safeguards. Visual map opacity/color is presentation, not merchant service policy.

## Delivery-time windows per leg (#73)

In **Transport network → Legs**, set **Preparation hours** (the shop's parcel handling before hand-in), **Lead hours** (the operator's advance notice / delay before departure), and **Transit min / max hours** (estimated time in transit). Enter both transit values as positive hours, maximum at least minimum. A leg with the default zero transit values is **not timed**; it never produces a false exact arrival estimate.

The route quote sums each selected leg's preparation + lead + minimum transit for the earliest estimate and preparation + lead + maximum transit for the latest. Checkout, the public customer price-check page, the admin simulator and order confirmation all show the same quoted time window. A confirmed order retains the original window in its frozen snapshot; changing the live leg later changes *new* quotes, not the historical snapshot.

For example, a bus with 2 h preparation, 3 h lead and 4–6 h transit, followed by a ferry with 1 h preparation, 2 h lead and 8–10 h transit, gives **20–24 hours**. A connection type/price without an explicitly modelled transfer duration is labelled *transfer travel time not included*: do not interpret the range as a guaranteed delivery deadline.

To switch off time information, go to **Transport network operating defaults → Show delivery-time windows**, enter **0** (off), and save. The default is **1** (on), with an initial per-leg time of zero so untimed services simply show price. Disabling hides all time information on current quote, customer quote page, simulator and historical order notice without deleting stored timing data. The maximum individual time input is configurable (`GRTRANS_MAX_LEG_HOURS`, default **168 hours**); enter a higher limit before saving unusually long journeys.

## Leg and transfer last-verified freshness (#76)

Every service **leg** and **transfer link** has an operator verification timestamp (`verified_at`, in UTC). Creating or editing a record automatically stamps the date as current. To confirm an unchanged tariff/service, open **Configure → Transport network**, find the leg or transfer row and press **Confirm current**. The timestamp is recorded without changing the price, route or direction. In the machine API, use `POST ?entity=legs&id=123&confirm=1` (or `transfers`) with the **write token**; read-only tokens cannot confirm prices.

The coverage map displays a **faded line / faded service circle and STALE label** for any unverified or old leg/link. By default, an entry becomes stale after **6 calendar months**; change **Mark leg/transfer data stale after months** in the network operating defaults (`GRTRANS_STALE_AFTER_MONTHS`). This is a reminder only; it does not automatically suspend the service or rewrite customer prices. A price is not externally verified merely because the module timestamp changed: the operator must actually confirm current freight terms with the transport operator.

To hide freshness overlays entirely, set **Show stale leg/transfer markings on map** (`GRTRANS_SHOW_STALE_MAP`) to **0**, then save. Default is **1/on**. Disabling visualization **retains all dates** and has no effect on quote eligibility, network paths, charges, or order history. Existing database rows upgraded to 0.9.0 have a null timestamp and are shown as **Not yet verified**, rather than being falsely declared current.

## Branch-aware origin stock (#78)

Branches are **optional**. With **no branches defined** for this PrestaShop shop, the network quotes from all marked origin hubs exactly as before. You can adopt this feature later without configuring anything during installation.

A branch is a named physical stock location **inside one PrestaShop shop**; it links to exactly **one existing station flagged Is origin hub**. It does **not** replace PrestaShop's own shop-level product stock and does not split orders between branches.

### Set up branches and product/combination availability

1. Go to **Module Manager → Greek public transport shipping → Configure → Transport network**, then **Manage → Branches**. Enter the branch name (for example *Athens Warehouse*) and choose its **Origin station ID** from the dropdown. Only a station flagged as a dispatch hub can be saved as an origin.
2. Open **Manage → Branch Stock**. Select the branch ID; enter the exact PrestaShop **product ID**, the **combination ID** (or 0 for a product without combinations), and the branch quantity (whole units). Save. Repeat for products/variants the location holds. Quantities of 0 mean unavailable.
3. Add a second branch and its own stock. If a cart contains product A at branch 1 and product B only at branch 2, **neither branch can fulfil the complete cart**: the network does not invent a split shipment. Other carriers and any existing manually priced flat Route remain available under their normal rules.
4. Each quote checks that **one** branch has enough quantity of **every** exact cart product+combination, including duplicate lines and requested quantities. If more than one branch qualifies, the path engine computes the complete journey from each qualifying branch's origin hub and selects the cheapest total. If two eligible branches share an origin station, the lower branch ID is chosen deterministically.
5. Checkout and order confirmation show the **Dispatch branch**, alongside the origin terminal; the complete chosen branch and route are frozen in the original order snapshot. Subsequent merchant edits do not rewrite a placed order's dispatch history.

The branch stock record is *supplemental dispatch availability*: the normal PrestaShop `stock_available`, stock deductions, product restrictions and checkout validation still operate unchanged. **Branch inventory does not automatically update when PrestaShop products sell**, so staff or an external inventory synchronizer must maintain these quantities. This is an explicit limitation of the first version, not automatic stock synchronization.

**Cart-quote safety:** adding/removing items, variants, quantities or parcel measurements changes the cart quote fingerprint and re-evaluates branch eligibility. Already pinned network quotes are refused if the recorded branch loses the capacity to fulfil their current cart within the normal quote hold. If the branch is no longer eligible, a new quote may require expiration of the existing pin or a new address/cart; the module never silently reassigns an already pinned dispatch.

**Customer quote-check page:** without a cart, a public map pin cannot verify branch-specific stock. If branches exist and no manually priced flat Route matches, the page explains that **cart items are required for branch availability** rather than advertising a potentially impossible network price. The back-office simulator can use an **existing Cart ID in this same shop** for exact quote parity. Without a cart ID, it refuses branch-restricted network pricing; a station-only test is availability-blind only when no branches are configured.

### Branch API and bulk import

The existing authenticated network endpoint now accepts `entity=branches` and `entity=branch_stock` for GET/POST/PATCH/DELETE and `format=csv`, with the **same read/write tokens** as stations/legs/transfers. Both entities are always scoped to the current shop context, not installation-wide. API writes never accept a caller-supplied `id_shop`.

- `branches`: `name`, `origin_station_id`.
- `branch_stock`: `id_branch`, `id_product`, `id_product_attribute` (0 if none), `quantity` (nonnegative whole number).

The atomic `POST ?bulk=1` importer also handles branch and combination-stock arrays, in one checked transaction. A newly imported station can be referenced from a branch by `origin_ref` pointing at the station's `client_ref`; a newly imported branch can be referenced from stock rows by `branch_ref`. CSV uses the numeric IDs of already saved stations/branches. Invalid shop ownership, foreign combination, nonexistent product, or negative quantity is refused. A branch with stock rows cannot be deleted until those records are removed; a hub with dependent branches cannot be deleted.

This is dispatch availability, not the official inventory ledger. Keep the per-branch quantities synchronized with real stock and test the flow in the disposable shop before selling live.
