<?php
/** Approximate terminal locations, not geocoded customer addresses or tariffs.
 * Separate from demo route writes so upgrades never overwrite merchant prices.
 */
$kifisos = ['name' => 'Athens Kifisos KTEL', 'lat' => 37.9988, 'lng' => 23.6996];
$piraeus = ['name' => 'Port of Piraeus', 'lat' => 37.9475, 'lng' => 23.6370];
$lavrio = ['name' => 'Port of Lavrio', 'lat' => 37.7102, 'lng' => 24.0635];
$airport = ['name' => 'Athens International Airport', 'lat' => 37.9364, 'lng' => 23.9445];
return [
    ['name' => 'Patras KTEL station', 'lat' => 38.2490, 'lng' => 21.7350, 'postcode' => '26221', 'cities' => ['Patras', 'Πάτρα'], 'origin' => $kifisos],
    ['name' => 'Thessaloniki KTEL Macedonia', 'lat' => 40.6663, 'lng' => 22.8970, 'postcode' => '54628', 'cities' => ['Thessaloniki', 'Θεσσαλονίκη'], 'origin' => $kifisos],
    ['name' => 'Kalamata KTEL', 'lat' => 37.0470, 'lng' => 22.1115, 'postcode' => '24100', 'cities' => ['Kalamata', 'Καλαμάτα'], 'origin' => $kifisos],
    ['name' => 'Naxos Port', 'lat' => 37.1065, 'lng' => 25.3740, 'postcode' => '84300', 'cities' => ['Naxos', 'Νάξος'], 'origin' => $piraeus],
    ['name' => 'Syros Port', 'lat' => 37.4405, 'lng' => 24.9415, 'postcode' => '84100', 'cities' => ['Syros', 'Σύρος', 'Ermoupoli', 'Ερμούπολη'], 'origin' => $piraeus],
    ['name' => 'Andros Gavrio Port', 'lat' => 37.8830, 'lng' => 24.7365, 'postcode' => '84501', 'cities' => ['Andros', 'Άνδρος', 'Gavrio', 'Γαύριο'], 'origin' => ['name' => 'Port of Rafina', 'lat' => 38.0235, 'lng' => 24.0095]],
    ['name' => 'Kea Korissia Port', 'lat' => 37.6585, 'lng' => 24.3125, 'postcode' => '84002', 'cities' => ['Kea', 'Κέα', 'Korissia', 'Κορησσία'], 'origin' => $lavrio],
    ['name' => 'Kythnos Merichas Port', 'lat' => 37.3890, 'lng' => 24.3975, 'postcode' => '84006', 'cities' => ['Kythnos', 'Κύθνος', 'Merichas', 'Μέριχας'], 'origin' => $lavrio],
    ['name' => 'Heraklion Airport', 'lat' => 35.3397, 'lng' => 25.1803, 'postcode' => '71601', 'cities' => ['Heraklion', 'Ηράκλειο'], 'origin' => $airport],
    ['name' => 'Rhodes Airport', 'lat' => 36.4054, 'lng' => 28.0862, 'postcode' => '85106', 'cities' => ['Rhodes', 'Ρόδος'], 'origin' => $airport],
];
