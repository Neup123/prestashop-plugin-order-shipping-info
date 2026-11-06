# PrestaShop Greece Public-Transport Shipping

A module for PrestaShop (latest version) for a food business in Athens that delivers all over Greece by **public transport** — KTEL intercity buses, public ferries, and sometimes domestic flights — instead of couriers.

The customer enters a Greek address at checkout; the module resolves it to the serving station/port/airport from an owner-maintained transport dataset (routes, stations, prices), computes the shipping cost, and tells the customer the price — including, for station-pickup destinations, exactly which station they collect their order from.

Planned beyond the core: live strike awareness (suspending destinations whose route is on strike), destination-tailored customer notices, and a visual map of stations and routes.

Full specification: [PROJECT-BRIEF.md](PROJECT-BRIEF.md). Development starts from seeded demo data researched from real Greek transport (KTEL networks, ferry routes from Piraeus/Rafina/Lavrio, domestic flights), to be replaced by the owner's real routes.

Status: in development.
