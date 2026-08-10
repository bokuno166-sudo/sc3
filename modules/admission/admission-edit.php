<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'nurse', 'doctor']);

$pageTitle = 'Edit Admission';
$currentPage = 'admissions';

$conn = getDBConnection();
$admissionId = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['admission_id']) ? (int)$_POST['admission_id'] : 0);

if ($admissionId <= 0) {
    setFlashMessage('error', 'Invalid admission selected.');
    redirect('modules/admission/admissions.php');
}

$admission = null;
$admissionRes = $conn->query("SELECT a.*, p.first_name, p.last_name, p.patient_code, p.gender, p.date_of_birth
    FROM admissions a
    JOIN patients p ON a.patient_id = p.id
    WHERE a.id = $admissionId LIMIT 1");
if ($admissionRes && $admissionRes->num_rows > 0) {
    $admission = $admissionRes->fetch_assoc();
}

if (!$admission) {
    setFlashMessage('error', 'Admission not found.');
    $conn->close();
    redirect('modules/admission/admissions.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $doctor_id = isset($_POST['doctor_id']) ? (int)$_POST['doctor_id'] : 0;
    $room_id = isset($_POST['room_id']) && $_POST['room_id'] !== '' ? (int)$_POST['room_id'] : null;
    $admission_date = sanitize($_POST['admission_date'] ?? '');
    $notes = sanitize($_POST['notes'] ?? '');
    $status = sanitize($_POST['status'] ?? 'admitted');

    if ($doctor_id <= 0 && $singleDoctor) {
        $doctor_id = (int)$singleDoctor['id'];
    }

    if ($doctor_id <= 0) {
        setFlashMessage('error', 'A doctor must be selected.');
        $conn->close();
        redirect('modules/admission/admission-edit.php?id=' . $admissionId);
    }

    $stmt = $conn->prepare("UPDATE admissions SET doctor_id = ?, room_id = ?, admission_date = ?, notes = ?, status = ? WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param('iisssi', $doctor_id, $room_id, $admission_date, $notes, $status, $admissionId);
        $executed = $stmt->execute();
        $stmt->close();

        if ($executed) {
            logActivity('update', 'admissions', $admissionId, null, json_encode($_POST));
            setFlashMessage('success', 'Admission updated successfully.');
            $conn->close();
            redirect('modules/admission/admission-view.php?id=' . $admissionId);
        } else {
            setFlashMessage('error', 'Failed to update admission.');
        }
    } else {
        setFlashMessage('error', 'Unable to prepare update statement.');
    }
}

$doctorsRes = $conn->query("SELECT id, full_name FROM users WHERE role = 'doctor' AND status = 'active' ORDER BY full_name ASC");
$doctors = [];
if ($doctorsRes) {
    while ($doctor = $doctorsRes->fetch_assoc()) {
        $doctors[] = $doctor;
    }
}
$singleDoctor = null;
if (count($doctors) === 1) {
    $singleDoctor = $doctors[0];
}

$currentRoomId = !empty($admission['room_id']) ? (int)$admission['room_id'] : 0;

$roomsRes = $conn->query("SELECT r.*, (SELECT COUNT(*) FROM admissions a WHERE a.room_id = r.id AND a.status = 'admitted') AS occupied_count
    FROM rooms r
    WHERE (r.status = 'available' OR r.id = $currentRoomId)
      AND ((SELECT COUNT(*) FROM admissions a WHERE a.room_id = r.id AND a.status = 'admitted') < r.capacity OR r.id = $currentRoomId)
    ORDER BY r.room_number ASC");
$rooms = [];
if ($roomsRes) {
    while ($room = $roomsRes->fetch_assoc()) {
        $rooms[] = $room;
    }
}

$conn->close();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Edit Admission</h1>
        <p class="page-subtitle">Adjust room, bed, doctor, and notes for admission #<?php echo htmlspecialchars((string)($admission['admission_code'] ?: $admission['id'])); ?></p>
    </div>
    <div>
        <a href="admission-view.php?id=<?php echo $admissionId; ?>" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <form method="post" action="admission-edit.php?id=<?php echo $admissionId; ?>">
            <input type="hidden" name="admission_id" value="<?php echo $admissionId; ?>">

            <div class="form-row">
                <div class="form-group col-md-6">
                    <label>Patient</label>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($admission['first_name'] . ' ' . $admission['last_name']); ?>" readonly>
                </div>
                <div class="form-group col-md-6">
                    <label>Doctor</label>
                    <?php if ($singleDoctor): ?>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($singleDoctor['full_name']); ?>" readonly>
                        <input type="hidden" name="doctor_id" value="<?php echo (int)$singleDoctor['id']; ?>">
                    <?php else: ?>
                        <select name="doctor_id" class="form-control" required>
                            <option value="">Select doctor</option>
                            <?php foreach ($doctors as $doctor): ?>
                                <option value="<?php echo (int)$doctor['id']; ?>" <?php echo ((int)$doctor['id'] === (int)$admission['doctor_id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($doctor['full_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group col-md-6">
                    <label>Room</label>
                    <select name="room_id" class="form-control">
                        <option value="">No room</option>
                        <?php foreach ($rooms as $room): ?>
                            <option value="<?php echo (int)$room['id']; ?>" <?php echo ((int)$room['id'] === (int)$admission['room_id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($room['room_number'] . ' — ' . $room['room_type'] . ' (' . ($room['occupied_count'] ?? 0) . '/' . ($room['capacity'] ?? 0) . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group col-md-6">
                    <label>Admission Date</label>
                    <input type="datetime-local" name="admission_date" class="form-control" value="<?php echo htmlspecialchars(str_replace(' ', 'T', (string)($admission['admission_date'] ?? ''))); ?>">
                </div>
            </div>

            <div class="form-group">
                <label>Status</label>
                <select name="status" class="form-control">
                    <?php foreach (['admitted', 'transferred', 'discharged', 'absconded'] as $statusOption): ?>
                        <option value="<?php echo htmlspecialchars($statusOption); ?>" <?php echo (($admission['status'] ?? 'admitted') === $statusOption) ? 'selected' : ''; ?>><?php echo htmlspecialchars(ucfirst($statusOption)); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Notes</label>
                <textarea name="notes" class="form-control" rows="4"><?php echo htmlspecialchars($admission['notes'] ?? ''); ?></textarea>
            </div>

            <div class="form-group text-right">
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
