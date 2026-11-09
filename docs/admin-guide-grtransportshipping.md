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
- Otherwise a small form appears. Enter the real connection type, such as `taxi`, and its price (zero is allowed), then save. The transfer appears as a distinct dashed connection.
- Links have a direction from first to second. To enable reverse travel, define a reverse transfer. Use the **Transfers** grid to edit or remove links.

The issue does not specify a numerical near/far threshold. Using overlapping inner walking rings avoids assuming a fixed walking distance that would be unsafe in every location.

## Visual coverage map (#68)

The back-office map uses locally bundled **Leaflet** and OpenStreetMap tiles. Every station is shown by two mode-colored concentric discs: the inner is approximately two-thirds opaque and the outer one-third opaque. Legs have labeled connecting lines, distinct color shades for different operators, and price tooltips. Transfers are separate dashed connections with their connection type and price. Direct-area services appear as their own coverage discs.

Station coverage is drawn in descending reachable-price order so cheaper service is visually above more expensive coverage. The costs come from the shared directed graph used by the quote system, not manually entered map prices. Unreachable station discs are editing aids, not promised delivery coverage. No disc means no network-geography coverage.

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
