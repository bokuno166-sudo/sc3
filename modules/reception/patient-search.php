<?php
require_once __DIR__ . '/../../config/config.php';

header('Content-Type: application/json');

$q = '';
if (isset($_GET['q'])) {
    $q = trim($_GET['q']);
}

if ($q === '') {
    echo json_encode([]);
    exit;
}

$conn = getDBConnection();
$results = [];

// If the query is numeric, allow searching by id or patient_code
if (ctype_digit($q)) {
    // Search by id or code
    $like = '%' . $q . '%';
    $stmt = $conn->prepare("SELECT id, patient_code, first_name, last_name FROM patients WHERE id = ? OR patient_code LIKE ? LIMIT 20");
    $stmt->bind_param('is', $q, $like);
} else {
    $like = '%' . $q . '%';
    $stmt = $conn->prepare("SELECT id, patient_code, first_name, last_name FROM patients WHERE CONCAT(first_name, ' ', last_name) LIKE ? OR patient_code LIKE ? LIMIT 20");
    $stmt->bind_param('ss', $like, $like);
}

if ($stmt->execute()) {
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $label = $r['patient_code'] . ' - ' . $r['first_name'] . ' ' . $r['last_name'];
        $results[] = ['id' => $r['id'], 'label' => $label];
    }
}

echo json_encode($results);

$stmt->close();
$conn->close();

?>
