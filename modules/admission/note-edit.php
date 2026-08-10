<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'nurse', 'doctor']);

$pageTitle   = 'Edit Progress Note';
$currentPage = 'admissions';

$conn   = getDBConnection();
$noteId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($noteId <= 0) {
    setFlashMessage('error', 'Invalid note ID.');
    redirect('modules/admission/admissions.php');
}

$currentUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

// Simple query: pn.* is safe — progress_notes has no first_name/last_name columns
// so the patient name aliases can never be shadowed.
$stmt = $conn->prepare(
    "SELECT pn.*,
            p.first_name AS patient_first_name,
            p.last_name  AS patient_last_name
     FROM progress_notes pn
     LEFT JOIN patients p ON pn.patient_id = p.id
     WHERE pn.id = ?
     LIMIT 1"
);
if (!$stmt) {
    setFlashMessage('error', 'DB error: ' . $conn->error);
    redirect('modules/admission/admissions.php');
    exit;
}
$stmt->bind_param('i', $noteId);
$stmt->execute();
$res = $stmt->get_result();
if (!$res || $res->num_rows === 0) {
    setFlashMessage('error', 'Note not found.');
    $stmt->close(); $conn->close();
    redirect('modules/admission/admissions.php');
    exit;
}
$note = $res->fetch_assoc();
$stmt->close();

$admissionId = (int)$note['admission_id'];

// ── Ownership check ──────────────────────────────────────────────
// The note belongs to the user if nurse_id OR doctor_id matches the current user.
// Admins can always edit.
$ownerId = (int)($note['nurse_id'] ?? $note['doctor_id'] ?? 0);
if (!hasRole(['admin']) && $ownerId !== $currentUserId) {
    setFlashMessage('error', 'You can only edit your own notes.');
    redirect('modules/admission/admission-view.php?id=' . $admissionId);
}

// ── Handle POST ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_note_edit'])) {
    $note_type         = sanitize($_POST['note_type']         ?? $note['note_type']);
    $blood_pressure    = sanitize($_POST['blood_pressure']    ?? '');
    $heart_rate        = sanitize($_POST['heart_rate']        ?? '');
    $temperature       = sanitize($_POST['temperature']       ?? '');
    $respiratory_rate  = sanitize($_POST['respiratory_rate']  ?? '');
    $oxygen_saturation = sanitize($_POST['oxygen_saturation'] ?? '');
    $general_condition = sanitize($_POST['general_condition'] ?? '');
    $observation       = sanitize($_POST['observation']       ?? '');
    $intervention      = sanitize($_POST['intervention']      ?? '');
    $patient_response  = sanitize($_POST['patient_response']  ?? '');
    $intake_output     = sanitize($_POST['intake_output']     ?? '');
    $medications_given = sanitize($_POST['medications_given'] ?? '');
    $notes             = sanitize($_POST['notes']             ?? '');
    $recorded_at       = sanitize($_POST['recorded_at']       ?? $note['recorded_at']);

    $upd = $conn->prepare("UPDATE progress_notes SET
                note_type=?, blood_pressure=?, heart_rate=?, temperature=?,
                respiratory_rate=?, oxygen_saturation=?, general_condition=?,
                observation=?, intervention=?, patient_response=?, intake_output=?,
                medications_given=?, notes=?, recorded_at=?
            WHERE id=?");
    $upd->bind_param('ssssssssssssssi',
        $note_type, $blood_pressure, $heart_rate, $temperature,
        $respiratory_rate, $oxygen_saturation, $general_condition,
        $observation, $intervention, $patient_response, $intake_output,
        $medications_given, $notes, $recorded_at, $noteId);
    if ($upd->execute()) {
        logActivity('update', 'progress_notes', $noteId);
        setFlashMessage('success', 'Note updated successfully.');
    } else {
        setFlashMessage('error', 'Update failed: ' . $upd->error);
    }
    $upd->close();
    redirect('modules/admission/admission-view.php?id=' . $admissionId);
}

include __DIR__ . '/../../includes/header.php';
?>

<style>
/* ── Edit Note Page ─────────────────────────────────────────── */
.en-card {
    background: var(--surface-color);
    border: 1px solid var(--border-color);
    border-radius: 16px;
    box-shadow: var(--box-shadow);
    overflow: hidden;
    max-width: 820px;
    margin: 0 auto;
}
.en-header {
    padding: 22px 28px;
    background: linear-gradient(135deg, rgba(14,165,233,0.06), rgba(6,182,212,0.06));
    border-bottom: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    gap: 14px;
}
.en-header-icon {
    width: 42px; height: 42px;
    background: rgba(14,165,233,0.12);
    color: #0ea5e9;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.1rem;
    flex-shrink: 0;
}
.en-header h2 { font-size: 1.1rem; font-weight: 700; margin: 0 0 2px; color: var(--text-color); }
.en-header p  { font-size: 0.8rem; color: var(--text-muted); margin: 0; }

.en-body { padding: 28px; }

.en-owner-badge {
    display: inline-flex; align-items: center; gap: 6px;
    background: rgba(14,165,233,0.1);
    color: #0ea5e9;
    border: 1px solid rgba(14,165,233,0.25);
    border-radius: 50px;
    padding: 5px 13px;
    font-size: 0.77rem; font-weight: 600;
    margin-bottom: 22px;
}

.en-section-title {
    font-size: 0.72rem;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.6px;
    margin: 0 0 14px;
    display: flex; align-items: center; gap: 8px;
}
.en-section-title::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--border-color);
}

.en-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.en-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
@media (max-width: 600px) { .en-grid-2, .en-grid-3 { grid-template-columns: 1fr; } }

.en-field { margin-bottom: 16px; }
.en-label {
    display: block;
    font-size: 0.78rem; font-weight: 700;
    color: var(--text-color);
    margin-bottom: 6px;
}
.en-input {
    width: 100%; padding: 10px 13px;
    border: 1.5px solid var(--border-color);
    border-radius: 10px;
    font-size: 0.875rem; color: var(--text-color);
    background: var(--surface-muted);
    transition: border-color 0.18s ease, box-shadow 0.18s ease;
    box-sizing: border-box; font-family: inherit;
}
.en-input:focus {
    outline: none;
    border-color: #0ea5e9;
    box-shadow: 0 0 0 3px rgba(14,165,233,0.12);
    background: var(--surface-color);
}
textarea.en-input { resize: vertical; min-height: 80px; }
select.en-input { cursor: pointer; }

.en-footer {
    padding: 20px 28px;
    border-top: 1px solid var(--border-color);
    display: flex; align-items: center; justify-content: flex-end; gap: 12px;
    background: var(--surface-muted);
}
.en-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 10px 20px; border-radius: 10px;
    font-size: 0.875rem; font-weight: 600;
    cursor: pointer; border: none;
    transition: all 0.18s ease; text-decoration: none;
    font-family: inherit;
}
.en-btn-primary {
    background: linear-gradient(135deg, #0ea5e9, #06b6d4);
    color: #fff;
    box-shadow: 0 3px 10px rgba(14,165,233,0.3);
}
.en-btn-primary:hover { transform: translateY(-1px); filter: brightness(1.07); }
.en-btn-secondary {
    background: var(--surface-color);
    color: var(--text-muted);
    border: 1px solid var(--border-color);
}
.en-btn-secondary:hover { border-color: #94a3b8; color: var(--text-color); }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">Edit Progress Note</h1>
        <p class="page-subtitle">Update note for <strong><?php echo htmlspecialchars(($note['patient_first_name'] ?? '') . ' ' . ($note['patient_last_name'] ?? '')); ?></strong></p>
    </div>
    <div>
        <a href="admission-view.php?id=<?php echo $admissionId; ?>" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back
        </a>
    </div>
</div>

<div class="en-card">
    <div class="en-header">
        <div class="en-header-icon"><i class="fas fa-file-medical-alt"></i></div>
        <div>
            <h2>Edit Progress Note #<?php echo $noteId; ?></h2>
            <p>Only you can save changes. Others can only view this note.</p>
        </div>
    </div>

    <form method="POST" action="">
        <input type="hidden" name="save_note_edit" value="1">

        <div class="en-body">

            <div class="en-owner-badge">
                <i class="fas fa-lock" style="font-size:0.65rem;"></i>
                You are the note owner — edit is permitted
            </div>

            <!-- Note type & date -->
            <p class="en-section-title"><i class="fas fa-tag"></i> Note Info</p>
            <div class="en-grid-2">
                <div class="en-field">
                    <label class="en-label">Note Type</label>
                    <select name="note_type" class="en-input">
                        <?php
                        $types = ['vital-signs'=>'Vital Signs','nursing-note'=>'Nursing Note','medication'=>'Medication','procedure'=>'Procedure','with-doctor'=>'With Doctor'];
                        foreach ($types as $val => $lbl):
                        ?>
                        <option value="<?php echo $val; ?>" <?php echo (($note['note_type'] ?? '') === $val ? 'selected' : ''); ?>>
                            <?php echo $lbl; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="en-field">
                    <label class="en-label">Date &amp; Time Recorded</label>
                    <?php
                    $recVal = !empty($note['recorded_at'])
                        ? (new DateTime($note['recorded_at']))->format('Y-m-d\TH:i')
                        : date('Y-m-d\TH:i');
                    ?>
                    <input type="datetime-local" name="recorded_at" class="en-input"
                           value="<?php echo htmlspecialchars($recVal); ?>">
                </div>
            </div>

            <!-- Vitals -->
            <p class="en-section-title"><i class="fas fa-heartbeat"></i> Vital Signs</p>
            <div class="en-grid-3">
                <div class="en-field">
                    <label class="en-label">Blood Pressure</label>
                    <input type="text" name="blood_pressure" class="en-input" placeholder="e.g. 120/80"
                           value="<?php echo htmlspecialchars($note['blood_pressure'] ?? ''); ?>">
                </div>
                <div class="en-field">
                    <label class="en-label">Heart Rate (bpm)</label>
                    <input type="number" name="heart_rate" class="en-input" placeholder="e.g. 72" min="0"
                           value="<?php echo htmlspecialchars($note['heart_rate'] ?? ''); ?>">
                </div>
                <div class="en-field">
                    <label class="en-label">Temperature (°C)</label>
                    <input type="number" name="temperature" class="en-input" placeholder="e.g. 36.5" step="0.1"
                           value="<?php echo htmlspecialchars($note['temperature'] ?? ''); ?>">
                </div>
                <div class="en-field">
                    <label class="en-label">Respiratory Rate</label>
                    <input type="number" name="respiratory_rate" class="en-input" placeholder="breaths/min"
                           value="<?php echo htmlspecialchars($note['respiratory_rate'] ?? ''); ?>">
                </div>
                <div class="en-field">
                    <label class="en-label">SpO₂ (%)</label>
                    <input type="number" name="oxygen_saturation" class="en-input" placeholder="e.g. 98" min="0" max="100"
                           value="<?php echo htmlspecialchars($note['oxygen_saturation'] ?? ''); ?>">
                </div>
            </div>

            <!-- Narrative fields -->
            <p class="en-section-title"><i class="fas fa-notes-medical"></i> Clinical Narrative</p>
            <div class="en-field">
                <label class="en-label">General Condition / Assessment</label>
                <textarea name="general_condition" class="en-input"><?php echo htmlspecialchars($note['general_condition'] ?? ''); ?></textarea>
            </div>
            <div class="en-field">
                <label class="en-label">Observation</label>
                <textarea name="observation" class="en-input"><?php echo htmlspecialchars($note['observation'] ?? ''); ?></textarea>
            </div>
            <div class="en-field">
                <label class="en-label">Intervention (actions taken)</label>
                <textarea name="intervention" class="en-input"><?php echo htmlspecialchars($note['intervention'] ?? ''); ?></textarea>
            </div>
            <div class="en-field">
                <label class="en-label">Patient Response</label>
                <textarea name="patient_response" class="en-input"><?php echo htmlspecialchars($note['patient_response'] ?? ''); ?></textarea>
            </div>
            <div class="en-field">
                <label class="en-label">Intake / Output</label>
                <textarea name="intake_output" class="en-input"><?php echo htmlspecialchars($note['intake_output'] ?? ''); ?></textarea>
            </div>
            <div class="en-field">
                <label class="en-label">Medications Given</label>
                <input type="text" name="medications_given" class="en-input"
                       value="<?php echo htmlspecialchars($note['medications_given'] ?? ''); ?>"
                       placeholder="Comma-separated medication names">
            </div>
            <div class="en-field">
                <label class="en-label">Additional Notes</label>
                <textarea name="notes" class="en-input" style="min-height:100px;"><?php echo htmlspecialchars($note['notes'] ?? ''); ?></textarea>
            </div>

        </div><!-- /.en-body -->

        <div class="en-footer">
            <a href="admission-view.php?id=<?php echo $admissionId; ?>" class="en-btn en-btn-secondary">
                <i class="fas fa-times"></i> Cancel
            </a>
            <button type="submit" class="en-btn en-btn-primary">
                <i class="fas fa-save"></i> Save Changes
            </button>
        </div>
    </form>
</div>

<?php
$conn->close();
include __DIR__ . '/../../includes/footer.php';
?>
