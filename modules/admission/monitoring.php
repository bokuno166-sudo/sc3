<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'nurse', 'doctor']);

$pageTitle = 'Admission Monitoring';
$currentPage = 'admission';

$conn = getDBConnection();

// Check if the optional monitoring table exists
$hasMonitoring = false;
$tblRes = $conn->query("SHOW TABLES LIKE 'admission_monitoring'");
if ($tblRes && $tblRes->num_rows > 0) {
    $hasMonitoring = true;
}

if ($hasMonitoring) {
    // Fetch active admissions and latest monitoring per admission
    $sql = "
        SELECT a.*, p.first_name, p.last_name, p.patient_code,
            (
                SELECT am2.recorded_at FROM admission_monitoring am2 WHERE am2.admission_id = a.id ORDER BY am2.recorded_at DESC LIMIT 1
            ) as last_recorded_at,
            (
                SELECT am2.temperature FROM admission_monitoring am2 WHERE am2.admission_id = a.id ORDER BY am2.recorded_at DESC LIMIT 1
            ) as last_temperature,
            (
                SELECT am2.pulse FROM admission_monitoring am2 WHERE am2.admission_id = a.id ORDER BY am2.recorded_at DESC LIMIT 1
            ) as last_pulse,
            (
                SELECT am2.bp FROM admission_monitoring am2 WHERE am2.admission_id = a.id ORDER BY am2.recorded_at DESC LIMIT 1
            ) as last_bp
        FROM admissions a
        JOIN patients p ON a.patient_id = p.id
        WHERE a.status = 'admitted'
        ORDER BY a.admission_date DESC
    ";
} else {
    // Fallback: return admissions without vitals columns (aliases to NULL)
    $sql = "
        SELECT a.*, p.first_name, p.last_name, p.patient_code,
            NULL as last_recorded_at,
            NULL as last_temperature,
            NULL as last_pulse,
            NULL as last_bp
        FROM admissions a
        JOIN patients p ON a.patient_id = p.id
        WHERE a.status = 'admitted'
        ORDER BY a.admission_date DESC
    ";
}

$admissionsResult = $conn->query($sql);

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Admission Monitoring</h1>
        <p class="page-subtitle">Active admissions and latest vital signs</p>
    </div>
    <div>
        <a href="../admission/admissions.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Active Admissions</h3>
    </div>
    <div class="card-body" style="padding:0;">
        <?php if ($admissionsResult && $admissionsResult->num_rows > 0): ?>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Admission ID</th>
                        <th>Patient</th>
                        <th>Ward/Room</th>
                        <th>Admission Date</th>
                        <th>Latest Vitals</th>
                        <th>Last Recorded</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($a = $admissionsResult->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo $a['id']; ?></td>
                        <td><?php echo htmlspecialchars($a['first_name'] . ' ' . $a['last_name']); ?> <br><small class="text-muted"><?php echo htmlspecialchars($a['patient_code']); ?></small></td>
                        <td><?php echo htmlspecialchars($a['ward'] ?? $a['room'] ?? '—'); ?></td>
                        <td><?php echo formatDateTime($a['admission_date']); ?></td>
                        <td>
                            <?php if ($a['last_temperature'] || $a['last_pulse'] || $a['last_bp']): ?>
                                <?php echo $a['last_temperature'] ? htmlspecialchars($a['last_temperature']) . '°C ' : ''; ?>
                                <?php echo $a['last_pulse'] ? 'Pulse ' . htmlspecialchars($a['last_pulse']) . 'bpm ' : ''; ?>
                                <?php echo $a['last_bp'] ? 'BP ' . htmlspecialchars($a['last_bp']) : ''; ?>
                            <?php else: ?>
                                <span class="text-muted">No vitals</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $a['last_recorded_at'] ? formatDateTime($a['last_recorded_at']) : '—'; ?></td>
                        <td class="table-actions">
                            <a href="admission-view.php?id=<?php echo $a['id']; ?>" class="btn btn-sm btn-info"><i class="fas fa-eye"></i></a>
                            <a href="monitoring-add.php?admission_id=<?php echo $a['id']; ?>" class="btn btn-sm btn-primary"><i class="fas fa-notes-medical"></i> Add</a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div style="padding:30px; text-align:center; color:#999;">No active admissions found.</div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
