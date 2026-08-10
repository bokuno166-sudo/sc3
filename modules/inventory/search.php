<?php
require_once __DIR__ . '/../../config/config.php';
// Allow doctors and inventory staff to query inventory for matching medications
requireRole(['admin','inventory','doctor','nurse']);

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$conn = getDBConnection();

$safe = $conn->real_escape_string($q);
$sql = "SELECT i.id, i.item_name, i.item_code, COALESCE(SUM(s.quantity_in_stock - s.quantity_reserved),0) as available FROM inventory_items i LEFT JOIN inventory_stock s ON i.id = s.item_id WHERE i.status = 'active' ";
if ($safe !== '') {
    $sql .= " AND (i.item_name LIKE '%" . $safe . "%' OR i.item_code LIKE '%" . $safe . "%') ";
}
$sql .= " GROUP BY i.id ORDER BY i.item_name ASC LIMIT 20";

$res = $conn->query($sql);
$out = [];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $out[] = [
            'id' => (int)$row['id'],
            'item_name' => $row['item_name'],
            'item_code' => $row['item_code'],
            'available' => (int)$row['available']
        ];
    }
}

header('Content-Type: application/json');
echo json_encode($out);
exit;
