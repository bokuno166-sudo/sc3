<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'staff']);

$pageTitle = 'Queue for Assessment';
$currentPage = 'visits';

$conn = getDBConnection();

// Get patient if ID provided
$patient = null;
if (isset($_GET['patient_id'])) {
    $patientId = (int)$_GET['patient_id'];
    $result = $conn->query("SELECT * FROM patients WHERE id = $patientId");
    $patient = $result->fetch_assoc();
}

// Search patients for dropdown
$patients = $conn->query("SELECT id, patient_code, first_name, last_name FROM patients WHERE status = 'active' ORDER BY first_name");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $patientId = (int)$_POST['patient_id'];
    $visitType = sanitize($_POST['visit_type']);
    $priority = sanitize($_POST['priority']);
    $chiefComplaint = sanitize($_POST['chief_complaint']);
    $checkupType = isset($_POST['checkup_type']) ? sanitize($_POST['checkup_type']) : null;
    
    if ($patientId <= 0) {
        setFlashMessage('error', 'Please select a valid patient.');
    } elseif (empty(trim($chiefComplaint))) {
        setFlashMessage('error', 'Chief Complaint / Reason for Assessment is required.');
    } else {
    
        // Generate queue number
        $queueResult = $conn->query("SELECT COUNT(*) as count FROM patient_visits WHERE visit_date = CURDATE()");
    $queueCount = $queueResult->fetch_assoc()['count'];
    $queueNumber = 'Q' . date('Ymd') . '-' . str_pad($queueCount + 1, 3, '0', STR_PAD_LEFT);
    
    // Ensure checkup_type column exists (for pregnant patients)
    $checkColumn = $conn->query("SHOW COLUMNS FROM patient_visits LIKE 'checkup_type'");
    if (!$checkColumn || $checkColumn->num_rows === 0) {
        $conn->query("ALTER TABLE patient_visits ADD COLUMN checkup_type VARCHAR(50) NULL DEFAULT NULL");
    }

    // Insert visit, include checkup_type when provided
    $stmt = $conn->prepare("INSERT INTO patient_visits (patient_id, queue_number, visit_date, visit_type, priority, chief_complaint, checkup_type, created_by) VALUES (?, ?, CURDATE(), ?, ?, ?, ?, ?)");
    $stmt->bind_param("isssssi", $patientId, $queueNumber, $visitType, $priority, $chiefComplaint, $checkupType, $_SESSION['user_id']);
    
        if ($stmt->execute()) {
        $visitId = $stmt->insert_id;
        logActivity('create', 'patient_visits', $visitId);
        setFlashMessage('success', 'Queued for Assessment successfully! Queue Number: ' . $queueNumber);
        // Notify staff that a patient is waiting
        try {
            $notifStmt = $conn->prepare("INSERT INTO notifications (recipient_user_id, title, message) VALUES (?, ?, ?)");
            $staffUsers = $conn->query("SELECT id FROM users WHERE role = 'staff' AND status = 'active'");
            $title = 'Patient waiting in queue';
            $message = 'Patient ID ' . intval($patientId) . ' is waiting. Queue: ' . $conn->real_escape_string($queueNumber);
            if ($staffUsers) {
                while ($s = $staffUsers->fetch_assoc()) {
                    $notifStmt->bind_param('iss', $s['id'], $title, $message);
                    $notifStmt->execute();
                }
                $notifStmt->close();
            }
        } catch (Exception $e) {
            // ignore notification errors
        }
        redirect('modules/reception/queue.php');
        } else {
            setFlashMessage('error', 'Error registering to assessment queue: ' . $stmt->error);
        }
        
        $stmt->close();
    }
}

$conn->close();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Queue for Assessment</h1>
        <p class="page-subtitle">Register a patient for assessment</p>
    </div>
    <a href="queue.php" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to Queue
    </a>
</div>

<form method="POST" action="">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Assessment Information</h3>
        </div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group" style="grid-column: span 2;">
                    <label class="form-label">Select Patient <span style="color: red;">*</span></label>
                    <?php if ($patient): ?>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($patient['patient_code'] . ' - ' . $patient['first_name'] . ' ' . $patient['last_name']); ?>" disabled>
                        <input type="hidden" name="patient_id" value="<?php echo $patient['id']; ?>">
                    <?php else: ?>
                        <input type="text" id="patient_search" class="form-control" placeholder="Search by name or patient ID..." autocomplete="off" required>
                        <input type="hidden" name="patient_id" id="patient_id">
                        <div id="patient_suggestions" class="autocomplete-suggestions" style="position:relative;"></div>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if ($patient): ?>
            <div class="patient-info-card" style="margin: 20px 0;">
                <div class="patient-info-header">
                    <h2><?php echo $patient['first_name'] . ' ' . $patient['last_name']; ?></h2>
                    <span class="patient-code"><?php echo $patient['patient_code']; ?></span>
                </div>
                <div class="patient-info-grid">
                    <div class="patient-info-item">
                        <label>Age</label>
                        <span><?php echo calculateAge($patient['date_of_birth']); ?> years old</span>
                    </div>
                    <div class="patient-info-item">
                        <label>Gender</label>
                        <span><?php echo $patient['gender']; ?></span>
                    </div>
                    <div class="patient-info-item">
                        <label>Blood Type</label>
                        <span><?php echo $patient['blood_type']; ?></span>
                    </div>
                    <div class="patient-info-item">
                        <label>Contact</label>
                        <span><?php echo $patient['contact_number']; ?></span>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Assessment Type <span style="color: red;">*</span></label>
                    <select name="visit_type" class="form-control" required>
                        <option value="walk-in">Walk-in</option>
                        <option value="emergency">Emergency</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Priority <span style="color: red;">*</span></label>
                    <select name="priority" class="form-control" required>
                        <option value="normal">Normal</option>
                        <option value="high">High</option>
                        <option value="emergency">Emergency</option>
                    </select>
                </div>
            </div>
            
            <!-- Pregnant checkup type selector (shown when selected patient is pregnant) -->
            <div class="form-row" id="pregnant_checkup_row" style="display: none;">
                <div class="form-group" style="grid-column: span 2;">
                    <label class="form-label">Pregnancy Check-up Type</label>
                    <select name="checkup_type" id="checkup_type" class="form-control">
                        <option value="">-- Select if applicable --</option>
                        <option value="consultation">General Consultation (Basic Check)</option>
                        <option value="maternity">Maternity Check-up (Prenatal)</option>
                    </select>
                    <small class="text-muted">If this patient is pregnant, select the appropriate check-up to streamline triage.</small>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group" style="grid-column: span 2;">
                    <label class="form-label">Chief Complaint / Reason for Assessment<span style="color: red;">*</span></label>
                    <textarea name="chief_complaint" class="form-control" rows="3" placeholder="Describe the patient's symptoms or reason for assessment..." required></textarea>
                </div>
            </div>
        </div>
        </div>
    </div>
    <div class="form-group" style="text-align: right;">
        <a href="queue.php" class="btn btn-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-clipboard-check"></i> Register for Assessment
        </button>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const visitTypeSelect = document.querySelector('select[name="visit_type"]');
    const prioritySelect = document.querySelector('select[name="priority"]');
    
    function updatePriority() {
        if (visitTypeSelect.value === 'emergency') {
            prioritySelect.value = 'emergency';
            prioritySelect.disabled = true;
        } else {
            prioritySelect.value = 'normal';
            prioritySelect.disabled = false;
        }
    }
    
    // Initial check
    updatePriority();
    
    // Listen for changes
    visitTypeSelect.addEventListener('change', updatePriority);

    // Patient search autocomplete
    const patientSearch = document.getElementById('patient_search');
    const patientIdField = document.getElementById('patient_id');
    const suggestionsBox = document.getElementById('patient_suggestions');

    if (patientSearch) {
        let debounceTimer = null;

        function clearSuggestions() {
            suggestionsBox.innerHTML = '';
        }

        function renderSuggestions(items) {
            clearSuggestions();
            const list = document.createElement('div');
            list.className = 'suggestion-list';
            list.style.position = 'absolute';
            list.style.zIndex = '9999';
            list.style.background = '#fff';
            list.style.border = '1px solid #ddd';
            list.style.width = patientSearch.offsetWidth + 'px';
            items.forEach(item => {
                const row = document.createElement('div');
                row.className = 'suggestion-item';
                row.style.padding = '8px';
                row.style.cursor = 'pointer';
                row.textContent = item.label;
                row.dataset.id = item.id;
                row.addEventListener('click', function() {
                    patientSearch.value = this.textContent;
                    patientIdField.value = this.dataset.id;
                    clearSuggestions();
                        // After selecting a patient, fetch patient details to determine pregnancy status
                        fetch('patient-details.php?id=' + encodeURIComponent(this.dataset.id))
                            .then(r => r.json())
                            .then(data => {
                                if (data && data.is_pregnant) {
                                    document.getElementById('pregnant_checkup_row').style.display = 'block';
                                } else {
                                    document.getElementById('pregnant_checkup_row').style.display = 'none';
                                    document.getElementById('checkup_type').value = '';
                                }
                            }).catch(() => {
                                // ignore errors silently
                            });
                });
                list.appendChild(row);
            });
            suggestionsBox.appendChild(list);
        }

        patientSearch.addEventListener('input', function() {
            const q = this.value.trim();
            patientIdField.value = '';
            if (debounceTimer) clearTimeout(debounceTimer);
            if (!q) { clearSuggestions(); return; }
            debounceTimer = setTimeout(() => {
                fetch('patient-search.php?q=' + encodeURIComponent(q))
                    .then(r => r.json())
                    .then(data => {
                        if (Array.isArray(data) && data.length) {
                            renderSuggestions(data);
                        } else {
                            clearSuggestions();
                        }
                    }).catch(() => clearSuggestions());
            }, 250);
        });

        // Close suggestions when clicking outside
        document.addEventListener('click', function(e) {
            if (!suggestionsBox.contains(e.target) && e.target !== patientSearch) {
                clearSuggestions();
            }
        });
    }
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
