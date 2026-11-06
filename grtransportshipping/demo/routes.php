<?php
/**
 * Illustrative parcel shipping assumptions, NOT official KTEL/ferry/air cargo tariffs.
 * No schedules or transport contracts are represented.
 */
return [
    ['bus', 'Athens Kifisos KTEL', 'Patras KTEL station', '', '262', 12.50, 1, 'Pickup at Patras KTEL station; call ahead.'],
    ['bus', 'Athens Kifisos KTEL', 'Thessaloniki KTEL Macedonia', '', '546', 18.00, 1, 'Pickup from KTEL Macedonia terminal.'],
    ['bus', 'Athens Kifisos KTEL', 'Kalamata KTEL', '', '241', 13.00, 1, 'Pickup at Kalamata KTEL.'],
    ['ferry', 'Port of Piraeus', 'Naxos Port', '', '843', 29.00, 1, 'Port pickup only; ferry schedules are subject to weather.'],
    ['ferry', 'Port of Piraeus', 'Syros Port', '', '841', 24.00, 1, 'Port pickup only; confirm the vessel arrival.'],
    ['ferry', 'Port of Rafina', 'Andros Gavrio Port', '', '845', 22.00, 1, 'Gavrio port pickup; sailing subject to weather.'],
    ['flight', 'Athens International Airport', 'Heraklion Airport', '', '716', 45.00, 1, 'Airport collection only, conditional on cargo acceptance and flight operation.'],
    ['flight', 'Athens International Airport', 'Rhodes Airport', '', '851', 55.00, 1, 'Airport collection only; flights may be delayed or cancelled.'],
];
