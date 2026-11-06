# Kickoff brief — PrestaShop Greece public-transport shipping module

You are starting a NEW project from scratch, in this repository: Neup123/prestashop-plugin-order-shipping-info. All work lands here. Work on it continuously until it is complete end-to-end. If you stop, you will be told "continue" — keep going. Follow the engineering instructions in the GitHub repo Neup123/AI_knowledge_and_work_center (skills/ — especially engineering-discipline, including its Security work section: security has a bar, not a loop). Use GitHub with CI from the start: small PRs, merge on green CI, what is not on main does not exist. License: when you add one, use the owner's policy — BSL 1.1 with the Additional Use Grant described in the AI hub repo; if unsure, leave the license out and open an issue instead of picking a different license.

## What we are building

A module for PrestaShop (latest major version) for a food business based in Athens, Greece, that delivers orders all over Greece NOT by courier, but by public transport: intercity KTEL buses, public ferries, and sometimes domestic flights. Shipping cost therefore equals the public-transport cost of getting the parcel to the destination, and it varies a lot by destination.

### Core behaviour (the basis — build this first, complete)

1. The shop owner maintains a transport dataset in the module's admin: transport modes (bus / ferry / flight), routes/legs (origin station/port/airport → destination station/port/airport), prices, and which station serves which destination area. Origin is Athens.
2. At checkout, the customer enters a Greek delivery address; the module resolves the address to the serving destination/station, computes the shipping cost from the dataset, and shows it to the customer as the shipping price for the order.
3. Some destinations are station-pickup only: the parcel travels to the station and the customer collects it there. The module must tell the customer clearly which station they collect from (no door delivery implied).
4. Admin must be able to add/edit routes, stations, prices and pickup flags without code changes.

### Later / ideal features (after the core works end-to-end)

5. Strike awareness: periodically check public sources for Greek transport strikes (KTEL, ferries, flights) and automatically suspend/mark destinations whose route is on strike, so customers are not quoted deliveries that cannot run; restore when the strike ends.
6. Destination-tailored customer messages: when relevant to the requested destination, show the customer fitted notices — e.g. that flights to that destination may be cancelled (weather etc.), expected collection windows, or other per-destination messages the admin defines.
7. A visual map of stations/routes/pickup points (admin and/or customer facing).

### Demo data (important — do this early)

The owner has not entered his real routes yet. For development and demo, research the web yourself for how travel in Greece actually works — KTEL bus networks and fares, ferry routes and fares from Athens ports (Piraeus, Rafina, Lavrio), domestic flights — and seed the module with a realistic assumed dataset, clearly marked as demo/assumed data, so the whole flow can be tested end-to-end. The owner will replace it with his real data later.

### Definition of done

Module installable on a clean latest-version PrestaShop, demo dataset seeded, a customer in Athens checking out to several destinations (bus, ferry, flight, station-pickup) gets correct computed prices and correct pickup/messaging behaviour, admin can edit the dataset, CI green on GitHub, and installation + acceptance verified on a real instance. Report progress in percentages (built vs landed on main) when asked.
