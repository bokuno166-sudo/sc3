<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'nurse', 'doctor']);

$pageTitle = 'Admission Details';
$currentPage = 'admissions';

$conn = getDBConnection();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    setFlashMessage('error', 'Invalid admission ID.');
    redirect('modules/admission/admissions.php');
}

$sql = "SELECT a.*, p.first_name, p.last_name, p.patient_code, p.gender, p.date_of_birth,
               r.room_number, r.room_type, b.bed_number, u.full_name as doctor_name
        FROM admissions a
        JOIN patients p ON a.patient_id = p.id
        LEFT JOIN rooms r ON a.room_id = r.id
        LEFT JOIN beds b ON a.bed_id = b.id
        JOIN users u ON a.doctor_id = u.id
        WHERE a.id = ?
        LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $id);
$stmt->execute();
$res = $stmt->get_result();
if (!$res || $res->num_rows === 0) {
    setFlashMessage('error', 'Admission not found.');
    $stmt->close();
    $conn->close();
    redirect('modules/admission/admissions.php');
}

$admission = $res->fetch_assoc();
$stmt->close();

$isDischarged = strtolower((string)($admission['status'] ?? '')) === 'discharged';
$isAdmitted = strtolower((string)($admission['status'] ?? '')) === 'admitted';

include __DIR__ . '/../../includes/header.php';
?>

<style>
/* ── Progress Note Cards ─────────────────────────── */
.pn-note-card {
    border: 1px solid var(--border-color);
    border-radius: 12px;
    margin-bottom: 12px;
    overflow: hidden;
    transition: box-shadow 0.2s ease, border-color 0.2s ease;
    background: var(--surface-color);
}
.pn-note-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.07); }

/* Header row inside each card */
.pn-note-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 12px 16px;
    background: var(--surface-muted);
    border-bottom: 1px solid var(--border-color);
}
.pn-note-meta { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.pn-note-author {
    font-size: 0.9rem; font-weight: 700; color: var(--text-color);
    display: flex; align-items: center; gap: 5px;
}
.pn-note-time { font-size: 0.75rem; color: var(--text-muted); }

.pn-note-right {
    display: flex; align-items: center; gap: 8px; flex-shrink: 0; flex-wrap: wrap;
}

/* Owner action buttons */
.pn-owner-actions { display: flex; align-items: center; gap: 5px; }
.pn-action-btn {
    width: 30px; height: 30px;
    border-radius: 8px;
    border: none; background: none;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; font-size: 0.75rem;
    transition: all 0.18s ease; text-decoration: none;
}
.pn-edit-btn { color: #0ea5e9; background: rgba(14,165,233,0.1); }
.pn-edit-btn:hover { background: rgba(14,165,233,0.22); color: #0284c7; }
.pn-delete-btn { color: #ef4444; background: rgba(239,68,68,0.1); }
.pn-delete-btn:hover { background: rgba(239,68,68,0.22); color: #dc2626; }

/* View-only badge for other users */
.pn-readonly-badge {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 0.7rem; color: var(--text-muted);
    background: var(--border-color);
    padding: 3px 9px; border-radius: 50px;
}

/* Note body */
.pn-note-body { padding: 12px 16px; font-size: 0.875rem; }

.pn-vitals-row {
    display: flex; align-items: center; gap: 8px;
    flex-wrap: wrap; margin-bottom: 8px;
}
.pn-vitals-label { font-size: 0.78rem; font-weight: 600; color: var(--text-muted); }
.pn-vital-chip {
    background: rgba(14,165,233,0.09);
    border: 1px solid rgba(14,165,233,0.18);
    color: var(--text-color);
    font-size: 0.78rem; padding: 3px 9px;
    border-radius: 6px; white-space: nowrap;
}

.pn-detail-row { margin-bottom: 6px; line-height: 1.55; color: var(--text-color); }
.pn-detail-row:last-child { margin-bottom: 0; }
</style>

<?php
// Fetch progress notes for this admission (visible to staff)
$conn2 = getDBConnection();
// Ensure status column exists in progress_notes dynamically
$colCheck = $conn2->query("SHOW COLUMNS FROM progress_notes LIKE 'status'");
if (!$colCheck || $colCheck->num_rows === 0) {
    $conn2->query("ALTER TABLE progress_notes ADD COLUMN status VARCHAR(20) DEFAULT 'completed'");
}

$pnStmt = $conn2->prepare("SELECT pn.*, un.full_name AS nurse_name, ud.full_name AS doctor_name FROM progress_notes pn LEFT JOIN users un ON pn.nurse_id = un.id LEFT JOIN users ud ON pn.doctor_id = ud.id WHERE pn.admission_id = ? AND (pn.status IS NULL OR pn.status != 'draft') ORDER BY pn.recorded_at DESC");
if ($pnStmt) {
    $pnStmt->bind_param('i', $admission['id']);
    $pnStmt->execute();
    $pnRes = $pnStmt->get_result();
    $progressNotes = $pnRes ? $pnRes->fetch_all(MYSQLI_ASSOC) : [];
    $pnStmt->close();
} else {
    $progressNotes = [];
}

// Check for active draft doctor round
$hasDraftRound = false;
$draftCheck = $conn2->query("SELECT id FROM progress_notes WHERE admission_id = " . intval($admission['id']) . " AND note_type = 'doctor-round' AND status = 'draft' LIMIT 1");
if ($draftCheck && $draftCheck->num_rows > 0) {
    $hasDraftRound = true;
}

$conn2->close();

// Current logged-in user (for note ownership checks)
$currentUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$currentIsAdmin = hasRole(['admin']);
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Admission #<?php echo htmlspecialchars((string)($admission['admission_code'] ?: $admission['id'])); ?></h1>
        <p class="page-subtitle">Details for admitted patient</p>
        <?php if ($isDischarged): ?>
            <span class="badge badge-danger" style="margin-top:8px; text-transform:uppercase;">Discharged</span>
        <?php elseif ($isAdmitted): ?>
            <span class="badge badge-success" style="margin-top:8px; text-transform:uppercase;">Active Admission</span>
        <?php else: ?>
            <span class="badge badge-warning" style="margin-top:8px; text-transform:uppercase;"><?php echo htmlspecialchars(ucfirst($admission['status'] ?? '')); ?></span>
        <?php endif; ?>
    </div>
    <div>
        <a href="admissions.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <h3>Patient</h3>
        <p>
            <strong><?php echo htmlspecialchars((string)($admission['first_name'] . ' ' . $admission['last_name'])); ?></strong><br>
            <small class="text-muted"><?php echo htmlspecialchars($admission['patient_code'] ?? ''); ?> | <?php echo calculateAge($admission['date_of_birth']); ?> yrs | <?php echo htmlspecialchars($admission['gender'] ?? ''); ?></small>
            <br>
            <a href="../reception/patient-view.php?id=<?php echo $admission['patient_id']; ?>" class="btn btn-sm btn-info" style="margin-top:8px;">View Patient</a>
        </p>

        <h3>Admission Details</h3>
        <table class="table table-striped">
            <tr><th>Admission Code</th><td><?php echo htmlspecialchars($admission['admission_code'] ?? ''); ?></td></tr>
            <tr><th>Doctor</th><td><?php echo htmlspecialchars($admission['doctor_name'] ?? ''); ?></td></tr>
            <tr><th>Room</th><td><?php echo $admission['room_number'] ? 'Room ' . htmlspecialchars((string)$admission['room_number']) : '<span class="text-muted">Not assigned</span>'; ?></td></tr>
            <tr><th>Bed</th><td><?php echo $admission['bed_number'] ? htmlspecialchars((string)$admission['bed_number']) : '<span class="text-muted">Not assigned</span>'; ?></td></tr>
            <tr><th>Admission Date</th><td><?php echo formatDateTime($admission['admission_date']); ?></td></tr>
            <tr><th>Status</th><td><?php echo $isDischarged ? '<span class="badge badge-danger">Discharged</span>' : htmlspecialchars($admission['status'] ?? ''); ?></td></tr>
            <tr><th>Notes</th><td><?php echo nl2br(htmlspecialchars($admission['notes'] ?? '')); ?></td></tr>
        </table>

        <?php if ($isDischarged): ?>
            <div class="alert alert-warning" style="margin-top:12px;">
                This admission has already been discharged. Editing, adding notes, and discharge actions are no longer available.
            </div>
        <?php endif; ?>

        <div class="text-right">
            <?php if (hasRole(['admin', 'nurse', 'doctor']) && !$isDischarged): ?>
                <a href="admission-edit.php?id=<?php echo $admission['id']; ?>" class="btn btn-info"><i class="fas fa-edit"></i> Edit Admission</a>
            <?php elseif (hasRole(['admin', 'nurse', 'doctor'])): ?>
                <button class="btn btn-secondary" disabled><i class="fas fa-edit"></i> Edit Admission</button>
            <?php endif; ?>
            <?php if (hasRole(['admin', 'nurse']) && !$isDischarged): ?>
                <a href="monitoring-add.php?admission_id=<?php echo $admission['id']; ?>" class="btn btn-primary"><i class="fas fa-notes-medical"></i> Add Note</a>
            <?php elseif (hasRole(['admin', 'nurse'])): ?>
                <button class="btn btn-secondary" disabled><i class="fas fa-notes-medical"></i> Add Note</button>
            <?php endif; ?>
            <?php if (hasRole(['admin', 'doctor']) && $isAdmitted): ?>
                <a href="../consultation/doctor-round.php?admission_id=<?php echo $admission['id']; ?>" class="btn btn-success">
                    <i class="fas fa-stethoscope"></i> <?php echo $hasDraftRound ? 'Resume Doctor Round' : 'Doctor Round'; ?>
                </a>
            <?php elseif (hasRole(['admin', 'doctor'])): ?>
                <button class="btn btn-secondary" disabled><i class="fas fa-stethoscope"></i> Doctor Round</button>
            <?php endif; ?>
            <?php if (hasRole(['admin', 'doctor']) && !$isDischarged): ?>
                <a href="discharge.php?admission_id=<?php echo $admission['id']; ?>" class="btn btn-warning"><i class="fas fa-sign-out-alt"></i> Discharge</a>
            <?php elseif (hasRole(['admin', 'doctor'])): ?>
                <button class="btn btn-secondary" disabled><i class="fas fa-sign-out-alt"></i> Discharge</button>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <h3>Progress Notes</h3>
        <div style="margin-bottom:12px; display:flex; flex-wrap:wrap; gap:8px;">
            <span class="badge badge-primary">Vital Signs</span>
            <span class="badge badge-info">Nursing Note</span>
            <span class="badge badge-success">Doctor Round</span>
            <span class="badge badge-warning">Medication</span>
            <span class="badge badge-secondary">Procedure</span>
            <span class="badge badge-dark">With Doctor</span>
        </div>
        <?php if (empty($progressNotes)): ?>
            <p class="text-muted">No progress notes yet.</p>
        <?php else: ?>
            <?php foreach ($progressNotes as $pn):
                $noteOwnerId  = (int)($pn['nurse_id'] ?? $pn['doctor_id'] ?? 0);
                $isNoteOwner  = ($noteOwnerId === $currentUserId) || $currentIsAdmin;
                $noteType     = $pn['note_type'] ?: 'with-doctor';
                $noteBadgeClass = match($noteType) {
                    'vital-signs'   => 'badge-primary',
                    'nursing-note'  => 'badge-info',
                    'doctor-round'  => 'badge-success',
                    'medication'    => 'badge-warning',
                    'procedure'     => 'badge-secondary',
                    default         => 'badge-dark',
                };
            ?>
                <div class="pn-note-card" id="pn-<?php echo $pn['id']; ?>">
                    <!-- Note header row -->
                    <div class="pn-note-header">
                        <div class="pn-note-meta">
                            <strong class="pn-note-author">
                                <i class="fas fa-user-nurse" style="font-size:0.75rem; opacity:.7;"></i>
                                <?php echo htmlspecialchars($pn['nurse_name'] ?: $pn['doctor_name'] ?: 'Staff'); ?>
                            </strong>
                            <div class="pn-note-time"><?php echo formatDateTime($pn['recorded_at']); ?></div>
                        </div>
                        <div class="pn-note-right">
                            <span class="badge <?php echo $noteBadgeClass; ?>">
                                <?php echo htmlspecialchars(ucwords(str_replace('-', ' ', $noteType))); ?>
                            </span>
                            <?php if ($isNoteOwner): ?>
                            <span class="pn-owner-actions">
                                <a href="note-edit.php?id=<?php echo $pn['id']; ?>"
                                   class="pn-action-btn pn-edit-btn"
                                   title="Edit this note">
                                    <i class="fas fa-pen"></i>
                                </a>
                                <button type="button"
                                        class="pn-action-btn pn-delete-btn"
                                        title="Delete this note"
                                        onclick="deleteProgressNote(<?php echo $pn['id']; ?>, <?php echo $admission['id']; ?>)">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            </span>
                            <?php else: ?>
                            <span class="pn-readonly-badge" title="You can view but not edit this note">
                                <i class="fas fa-eye" style="font-size:0.65rem;"></i> View only
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Note body -->
                    <div class="pn-note-body">
                        <?php if (!empty($pn['blood_pressure']) || !empty($pn['heart_rate']) || !empty($pn['temperature']) || !empty($pn['respiratory_rate']) || !empty($pn['oxygen_saturation'])): ?>
                            <div class="pn-vitals-row">
                                <span class="pn-vitals-label"><i class="fas fa-heartbeat"></i> Vitals:</span>
                                <?php if (!empty($pn['blood_pressure'])): ?><span class="pn-vital-chip"><b>BP</b> <?php echo htmlspecialchars($pn['blood_pressure']); ?></span><?php endif; ?>
                                <?php if (!empty($pn['heart_rate'])): ?><span class="pn-vital-chip"><b>HR</b> <?php echo htmlspecialchars($pn['heart_rate']); ?> bpm</span><?php endif; ?>
                                <?php if (!empty($pn['temperature'])): ?><span class="pn-vital-chip"><b>Temp</b> <?php echo htmlspecialchars($pn['temperature']); ?>°C</span><?php endif; ?>
                                <?php if (!empty($pn['respiratory_rate'])): ?><span class="pn-vital-chip"><b>RR</b> <?php echo htmlspecialchars($pn['respiratory_rate']); ?></span><?php endif; ?>
                                <?php if (!empty($pn['oxygen_saturation'])): ?><span class="pn-vital-chip"><b>SpO₂</b> <?php echo htmlspecialchars($pn['oxygen_saturation']); ?>%</span><?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($pn['general_condition'])): ?><div class="pn-detail-row"><strong>General Condition / Assessment:</strong> <?php echo nl2br(htmlspecialchars($pn['general_condition'])); ?></div><?php endif; ?>
                        <?php if (!empty($pn['observation'])): ?><div class="pn-detail-row"><strong>Observation:</strong> <?php echo nl2br(htmlspecialchars($pn['observation'])); ?></div><?php endif; ?>
                        <?php if (!empty($pn['intervention'])): ?><div class="pn-detail-row"><strong>Intervention:</strong> <?php echo nl2br(htmlspecialchars($pn['intervention'])); ?></div><?php endif; ?>
                        <?php if (!empty($pn['patient_response'])): ?><div class="pn-detail-row"><strong>Patient Response:</strong> <?php echo nl2br(htmlspecialchars($pn['patient_response'])); ?></div><?php endif; ?>
                        <?php if (!empty($pn['intake_output'])): ?><div class="pn-detail-row"><strong>Intake / Output:</strong> <?php echo nl2br(htmlspecialchars($pn['intake_output'])); ?></div><?php endif; ?>
                        <?php if (!empty($pn['medications_given'])): ?><div class="pn-detail-row"><strong>Medications Given:</strong> <?php echo nl2br(htmlspecialchars($pn['medications_given'])); ?></div><?php endif; ?>
                        <?php if (!empty($pn['notes'])): ?>
                            <div class="pn-detail-row"><strong>Notes:</strong>
                                <?php
                                $notesHtml = htmlspecialchars($pn['notes']);
                                $notesHtml = preg_replace('@(https?://\S+)@', '<a href="$1" target="_blank">$1</a>', $notesHtml);
                                echo nl2br($notesHtml);
                                ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<script>
/**
 * deleteProgressNote – shows a styled confirmation dialog before redirecting
 * to note-delete.php. This avoids a plain window.confirm so we can show
 * a nicer UI hint about the irreversible action.
 */
function deleteProgressNote(noteId, admissionId) {
    // Use native confirm as a lightweight approach (consistent with existing codebase)
    if (!confirm('Delete this progress note?\n\nThis action cannot be undone. Only your own notes can be deleted.')) {
        return;
    }
    // Redirect to the delete handler
    window.location.href = 'note-delete.php?id=' + noteId + '&admission_id=' + admissionId;
}
</script>

