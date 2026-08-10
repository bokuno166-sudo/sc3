<?php
require_once __DIR__ . '/../../config/config.php';

$conn = getDBConnection();
echo "<h2>Rooms Table Schema & Data</h2>";

// 1. DESCRIBE rooms
echo "<h3>Schema:</h3>";
$res = $conn->query("DESCRIBE rooms");
if ($res) {
    echo "<table border='1'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Default</th></tr>";
    while ($row = $res->fetch_assoc()) {
        echo "<tr>";
        echo "<td>{$row['Field']}</td>";
        echo "<td>{$row['Type']}</td>";
        echo "<td>{$row['Null']}</td>";
        echo "<td>" . ($row['Default'] ?? 'NULL') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Check if daily_rate exists
    $hasDailyRate = false;
    while ($row = $res->fetch_assoc()) {
        if ($row['Field'] === 'daily_rate') $hasDailyRate = true;
    }
    echo "<p><strong>Has daily_rate column: " . ($hasDailyRate ? 'YES' : 'NO') . "</strong></p>";
} else {
    echo "Error: " . $conn->error;
}

// 2. Sample rooms data
echo "<h3>Sample Rooms (LIMIT 10):</h3>";
$res = $conn->query("SELECT id, room_number, room_type, capacity, status FROM rooms LIMIT 10");
if ($res && $res->num_rows) {
    echo "<table border='1'>";
    echo "<tr><th>ID</th><th>Room #</th><th>Type</th><th>Capacity</th><th>Status</th></tr>";
    while ($row = $res->fetch_assoc()) {
        echo "<tr>";
        foreach ($row as $val) echo "<td>$val</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// 3. Check database name
echo "<h3>Current Database:</h3>";
$res = $conn->query("SELECT DATABASE() as db");
echo $res->fetch_assoc()['db'];

$conn->close();
echo "<p><a href='invoices.php'>← Back to Invoices</a></p>";
?>

