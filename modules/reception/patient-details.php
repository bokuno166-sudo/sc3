<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'staff', 'reception', 'nurse', 'doctor']);

header('Content-Type: application/json; charset=utf-8');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    echo json_encode(['error' => 'Invalid patient id']);
    exit;
}

$conn = getDBConnection();
$stmt = $conn->prepare("SELECT id, is_pregnant, weeks_of_pregnancy, expected_due_date FROM patients WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $id);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    // Cast booleans properly
    $row['is_pregnant'] = (bool)$row['is_pregnant'];
    echo json_encode($row);
} else {
    echo json_encode(['error' => 'Patient not found']);
}

$stmt->close();
$conn->close();
