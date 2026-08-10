<?php
require_once __DIR__ . '/../../config/config.php';
$conn = getDBConnection();
$res = $conn->query('DESCRIBE admissions');
if ($res) {
    while ($r = $res->fetch_assoc()) {
        echo $r['Field'] . "\t" . $r['Type'] . "\n";
    }
} else {
    echo "Failed to describe admissions: " . $conn->error . "\n";
}
$conn->close();
