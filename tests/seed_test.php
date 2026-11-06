<?php
$rows = require __DIR__ . '/../grtransportshipping/demo/routes.php';
if (count($rows) < 6) { throw new RuntimeException('Demo coverage insufficient'); }
foreach ($rows as $row) {
    if (count($row) !== 8 || !in_array($row[0], ['bus','ferry','flight'], true) || !is_numeric($row[5]) || $row[5] <= 0) {
        throw new RuntimeException('Invalid demo row');
    }
}
echo "Demo seed tests passed\n";
