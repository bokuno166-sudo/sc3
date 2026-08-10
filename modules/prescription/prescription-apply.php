<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['doctor']);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    // fallback to form-encoded
    $data = $_POST;
}

$visitId = isset($data['visit_id']) ? (int)$data['visit_id'] : 0;
$meds = isset($data['medications']) && is_array($data['medications']) ? $data['medications'] : [];

if ($visitId <= 0 || empty($meds)) {
    echo json_encode(['success' => false, 'message' => 'Missing visit id or medications']);
    exit;
}

$conn = getDBConnection();

// Find visit and patient
$res = $conn->query("SELECT * FROM patient_visits WHERE id = " . (int)$visitId . " LIMIT 1");
if (!$res || $res->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Visit not found']);
    exit;
}
$visit = $res->fetch_assoc();
$patientId = (int)$visit['patient_id'];
$doctorId = (int)($_SESSION['user_id'] ?? 0);

// Try find existing consultation for this visit
$consultRes = $conn->query("SELECT id FROM consultations WHERE visit_id = " . (int)$visitId . " ORDER BY created_at DESC LIMIT 1");
if ($consultRes && $consultRes->num_rows > 0) {
    $consultRow = $consultRes->fetch_assoc();
    $consultationId = (int)$consultRow['id'];
} else {
    // create minimal consultation record
    $stmt = $conn->prepare("INSERT INTO consultations (visit_id, patient_id, doctor_id, outcome) VALUES (?, ?, ?, ?)");
    $outcome = 'prescription-only';
    $stmt->bind_param('iiis', $visitId, $patientId, $doctorId, $outcome);
    if (!$stmt->execute()) {
        echo json_encode(['success' => false, 'message' => 'Failed to create consultation: ' . $stmt->error]);
        exit;
    }
    $consultationId = $stmt->insert_id;
    $stmt->close();
}

$insertStmt = $conn->prepare("INSERT INTO prescriptions (consultation_id, patient_id, doctor_id, medication_name, dosage, frequency, duration, instructions, quantity) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
if (!$insertStmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare statement: ' . $conn->error]);
    exit;
}

$inserted = 0;
foreach ($meds as $m) {
    $medName = sanitize($m['medication'] ?? '');
    if ($medName === '') continue;
    $dosage = sanitize($m['dose'] ?? '');
    $frequency = sanitize($m['frequency'] ?? '');
    $duration = sanitize($m['duration'] ?? '');
    $instructions = sanitize($m['instructions'] ?? '');
    $quantity = isset($m['quantity']) ? (int)$m['quantity'] : 0;

    $insertStmt->bind_param('iiisssssi', $consultationId, $patientId, $doctorId, $medName, $dosage, $frequency, $duration, $instructions, $quantity);
    if ($insertStmt->execute()) {
        $inserted++;
        logActivity('create', 'prescriptions', $insertStmt->insert_id);
    }
}
$insertStmt->close();

echo json_encode(['success' => true, 'inserted' => $inserted, 'consultation_id' => $consultationId]);
$conn->close();
exit;

?>
