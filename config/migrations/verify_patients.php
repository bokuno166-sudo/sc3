<?php
require_once __DIR__ . '/../config.php';

$conn = getDBConnection();
$res = $conn->query("DESCRIBE patients");
if (!$res) {
    echo "Failed to describe patients: " . $conn->error . "\n";
    exit(1);
}

echo "patients table columns:\n";
while ($row = $res->fetch_assoc()) {
    echo $row['Field'] . "\t" . $row['Type'] . "\n";
}

$conn->close();

?>
