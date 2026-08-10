<?php
require_once __DIR__ . '/../config.php';
$conn = getDBConnection();
// find an existing patient and doctor
$patientRes = $conn->query("SELECT id FROM patients WHERE status='active' LIMIT 1");
$doctorRes = $conn->query("SELECT id FROM users WHERE role='doctor' AND status='active' LIMIT 1");
if (!$patientRes || !$doctorRes) {
    echo "Missing patients or doctors\n";
    exit(1);
}
$patient = $patientRes->fetch_assoc();
$doctor = $doctorRes->fetch_assoc();
$patient_id = intval($patient['id']);
$doctor_id = intval($doctor['id']);
$room_id = null;
$bed_id = null;
$admission_date = date('Y-m-d H:i:s');
$status = 'admitted';
$notes = 'Test admission via CLI';
$created_by = $doctor_id;

// Find or create visit_id for this admission
$visit_id = null;
$visitRes = $conn->query("SELECT id FROM patient_visits WHERE patient_id = $patient_id AND status NOT IN ('discharged', 'cancelled') ORDER BY id DESC LIMIT 1");
if ($visitRes && $visitRes->num_rows > 0) {
    $visit_id = (int)$visitRes->fetch_assoc()['id'];
    $conn->query("UPDATE patient_visits SET status = 'admitted' WHERE id = $visit_id");
} else {
    $queueResult = $conn->query("SELECT COUNT(*) as count FROM patient_visits WHERE visit_date = CURDATE()");
    $queueCount = $queueResult ? $queueResult->fetch_assoc()['count'] : 0;
    $queueNumber = 'Q' . date('Ymd') . '-' . str_pad($queueCount + 1, 3, '0', STR_PAD_LEFT);
    
    $visit_date = date('Y-m-d');
    $visit_type = 'walk-in';
    $visit_status = 'admitted';
    $priority = 'normal';
    $chief_complaint = 'Direct admission to room/bed (CLI)';
    
    $vStmt = $conn->prepare("INSERT INTO patient_visits (patient_id, queue_number, visit_date, visit_type, status, priority, chief_complaint, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    if ($vStmt) {
        $vStmt->bind_param('issssssi', $patient_id, $queueNumber, $visit_date, $visit_type, $visit_status, $priority, $chief_complaint, $created_by);
        if ($vStmt->execute()) {
            $visit_id = $vStmt->insert_id;
        }
        $vStmt->close();
    }
}

if (!$visit_id) {
    echo "Failed to find or create a visit\n";
    exit(1);
}

$sql = 'INSERT INTO admissions (visit_id, patient_id, room_id, bed_id, doctor_id, admission_date, status, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)';
$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo "Prepare failed: " . $conn->error . "\n";
    exit(1);
}
// types: i (visit_id), i (patient_id), i (room_id), i (bed_id), i (doctor_id), s (admission_date), s (status), s (notes), i (created_by)
$stmt->bind_param('iiiiisssi', $visit_id, $patient_id, $room_id, $bed_id, $doctor_id, $admission_date, $status, $notes, $created_by);
$ok = $stmt->execute();
if ($ok) {
    echo "Inserted admission id: " . $stmt->insert_id . "\n";
} else {
    echo "Failed to insert: " . $stmt->error . "\n";
}
$stmt->close();
$conn->close();
