<?php
require_once __DIR__ . '/../config/config.php';
$conn = getDBConnection();
$codes = ['SUP-GLOVES', 'SUP-SYRINGE'];
$placeholders = implode(',', array_fill(0, count($codes), '?'));
$sql = "UPDATE inventory_items SET item_type = 'Supply' WHERE item_code IN ($placeholders)";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo "Prepare failed: " . $conn->error . PHP_EOL;
    exit(1);
}
$types = str_repeat('s', count($codes));
$stmt->bind_param($types, ...$codes);
$ok = $stmt->execute();
if ($ok) {
    echo "Updated items to item_type = Supply. Affected rows: " . $stmt->affected_rows . PHP_EOL;
} else {
    echo "Execute failed: " . $stmt->error . PHP_EOL;
}
$stmt->close();
$conn->close();
