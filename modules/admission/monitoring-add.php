<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'nurse', 'doctor']);

$pageTitle = 'Add Progress Note';
$currentPage = 'admissions';

$conn = getDBConnection();

$admission_id = isset($_GET['admission_id']) ? (int)$_GET['admission_id'] : (isset($_POST['admission_id']) ? (int)$_POST['admission_id'] : 0);
if ($admission_id <= 0) {
    setFlashMessage('error', 'Invalid admission id.');
    redirect('modules/admission/admissions.php');
}

// load admission to get patient_id
$stmt = $conn->prepare('SELECT a.id, a.patient_id, p.first_name, p.last_name FROM admissions a JOIN patients p ON a.patient_id = p.id WHERE a.id = ? LIMIT 1');
$stmt->bind_param('i', $admission_id);
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $note_type = sanitize($_POST['note_type'] ?? 'nursing-note');
    $blood_pressure = sanitize($_POST['blood_pressure'] ?? '');
    $heart_rate = sanitize($_POST['heart_rate'] ?? '');
    $temperature = sanitize($_POST['temperature'] ?? '');
    $respiratory_rate = sanitize($_POST['respiratory_rate'] ?? '');
    $oxygen_saturation = sanitize($_POST['oxygen_saturation'] ?? '');
    $general_condition = sanitize($_POST['general_condition'] ?? '');
    $observation = sanitize($_POST['observation'] ?? '');
    $intervention = sanitize($_POST['intervention'] ?? '');
    $patient_response = sanitize($_POST['patient_response'] ?? '');
    $intake_output = sanitize($_POST['intake_output'] ?? '');
    // medications_given can be an array (multiple) or string
    if (isset($_POST['medications_given'])) {
        if (is_array($_POST['medications_given'])) {
            $medications_given = sanitize(implode(', ', $_POST['medications_given']));
        } else {
            $medications_given = sanitize($_POST['medications_given']);
        }
    } else {
        $medications_given = '';
    }
    $notes = sanitize($_POST['notes'] ?? '');
    $recorded_at = sanitize($_POST['recorded_at'] ?? date('Y-m-d H:i:s'));

    $patient_id = intval($admission['patient_id']);
    $user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

    // Ensure progress_notes table has expected TEXT columns
    $col = $conn->query("SHOW COLUMNS FROM progress_notes LIKE 'general_condition'");
    if (!$col || $col->num_rows === 0) {
        $conn->query("ALTER TABLE progress_notes ADD COLUMN general_condition TEXT NULL");
    }
    $col = $conn->query("SHOW COLUMNS FROM progress_notes LIKE 'observation'");
    if (!$col || $col->num_rows === 0) {
        $conn->query("ALTER TABLE progress_notes ADD COLUMN observation TEXT NULL");
    }
    $col = $conn->query("SHOW COLUMNS FROM progress_notes LIKE 'intervention'");
    if (!$col || $col->num_rows === 0) {
        $conn->query("ALTER TABLE progress_notes ADD COLUMN intervention TEXT NULL");
    }
    $col = $conn->query("SHOW COLUMNS FROM progress_notes LIKE 'patient_response'");
    if (!$col || $col->num_rows === 0) {
        $conn->query("ALTER TABLE progress_notes ADD COLUMN patient_response TEXT NULL");
    }
    $col = $conn->query("SHOW COLUMNS FROM progress_notes LIKE 'intake_output'");
    if (!$col || $col->num_rows === 0) {
        $conn->query("ALTER TABLE progress_notes ADD COLUMN intake_output TEXT NULL");
    }
    $col = $conn->query("SHOW COLUMNS FROM progress_notes LIKE 'notes'");
    if (!$col || $col->num_rows === 0) {
        $conn->query("ALTER TABLE progress_notes ADD COLUMN notes TEXT NULL");
    }
    $col = $conn->query("SHOW COLUMNS FROM progress_notes LIKE 'recorded_at'");
    if (!$col || $col->num_rows === 0) {
        $conn->query("ALTER TABLE progress_notes ADD COLUMN recorded_at DATETIME NULL");
    }
    $col = $conn->query("SHOW COLUMNS FROM progress_notes LIKE 'medications_given'");
    if (!$col || $col->num_rows === 0) {
        $conn->query("ALTER TABLE progress_notes ADD COLUMN medications_given TEXT NULL");
    }

    // Decide whether to set nurse_id or doctor_id based on role
    $nurse_id = null;
    $doctor_id = null;
    if (hasRole(['nurse'])) {
        $nurse_id = $user_id;
    } elseif (hasRole(['doctor'])) {
        $doctor_id = $user_id;
    } else {
        // admin or others - set as nurse by default
        $nurse_id = $user_id;
    }

    // Use NULLIF so passing 0 becomes SQL NULL (foreign keys allow NULL)
    $insertSql = 'INSERT INTO progress_notes (admission_id, patient_id, nurse_id, doctor_id, note_type, blood_pressure, heart_rate, temperature, respiratory_rate, oxygen_saturation, general_condition, observation, intervention, patient_response, intake_output, medications_given, notes, recorded_at) VALUES (?, ?, NULLIF(?,0), NULLIF(?,0), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
    $stmt2 = $conn->prepare($insertSql);
    if (!$stmt2) {
        setFlashMessage('error', 'Failed to prepare statement: ' . $conn->error);
        redirect('modules/admission/admission-view.php?id=' . $admission_id);
    }
    // bind parameters (use 0 sentinel for NULL ids; NULLIF will convert to SQL NULL)
    $bind_nurse = $nurse_id ?? 0;
    $bind_doctor = $doctor_id ?? 0;
    $stmt2->bind_param('iiiissidiissssssss', $admission_id, $patient_id, $bind_nurse, $bind_doctor, $note_type, $blood_pressure, $heart_rate, $temperature, $respiratory_rate, $oxygen_saturation, $general_condition, $observation, $intervention, $patient_response, $intake_output, $medications_given, $notes, $recorded_at);
    $ok = $stmt2->execute();
    if ($ok) {
        $progressNoteId = $stmt2->insert_id;

        // If medications were given, attempt to deduct from inventory and add to billing
        if (!empty($medications_given)) {
            // medications_given stored as comma-separated names — try to handle multiple
            $medList = array_map('trim', explode(',', $medications_given));

            // Find or create a pending invoice for this admission
            $invoiceId = null;
            $invRes = $conn->query("SELECT * FROM invoices WHERE admission_id = " . intval($admission_id) . " AND status IN ('pending','partial') LIMIT 1");
            if ($invRes && $invRes->num_rows > 0) {
                $invRow = $invRes->fetch_assoc();
                $invoiceId = (int)$invRow['id'];
            } else {
                // create new invoice shell
                $pId = intval($patient_id);
                $createdBy = (int)($user_id ?? 0);
                $insSql = "INSERT INTO invoices (invoice_number, patient_id, visit_id, admission_id, total_amount, discount_amount, tax_amount, net_amount, paid_amount, balance_amount, status, created_by) VALUES ('TBD', $pId, NULL, " . intval($admission_id) . ", 0, 0, 0, 0, 0, 0, 'pending', $createdBy)";
                if ($conn->query($insSql)) {
                    $invoiceId = (int)$conn->insert_id;
                    $invoiceNumber = generateCode('INV', $invoiceId);
                    $conn->query("UPDATE invoices SET invoice_number = '" . $conn->real_escape_string($invoiceNumber) . "' WHERE id = $invoiceId");
                }
            }

            // For each medication, try to find inventory item and issue 1 unit
            foreach ($medList as $medName) {
                if ($medName === '') continue;
                $esc = $conn->real_escape_string($medName);
                // try exact match first, fallback to LIKE
                $itRes = $conn->query("SELECT id, unit_cost FROM inventory_items WHERE item_name = '" . $esc . "' LIMIT 1");
                if (!$itRes || $itRes->num_rows === 0) {
                    $itRes = $conn->query("SELECT id, unit_cost FROM inventory_items WHERE item_name LIKE '%" . $esc . "%' OR item_code LIKE '%" . $esc . "%' LIMIT 1");
                }
                if ($itRes && $itRes->num_rows > 0) {
                    $it = $itRes->fetch_assoc();
                    $itemId = (int)$it['id'];
                    $unitPrice = (float)($it['unit_cost'] ?? 0.0);
                    $quantityNeeded = 1; // default to 1 unit per selection

                    // Check availability
                    $r = $conn->query("SELECT SUM(quantity_in_stock - quantity_reserved) as available FROM inventory_stock WHERE item_id = $itemId");
                    $avail = $r ? (int)$r->fetch_assoc()['available'] : 0;
                    if ($avail >= $quantityNeeded) {
                        $need = $quantityNeeded;
                        $stockRes = $conn->query("SELECT id, quantity_in_stock, quantity_reserved FROM inventory_stock WHERE item_id = $itemId AND (quantity_in_stock - quantity_reserved) > 0 ORDER BY expiry_date ASC, id ASC");
                        while ($stockRes && $stockRes->num_rows > 0 && $need > 0) {
                            while ($row = $stockRes->fetch_assoc()) {
                                $stockId = (int)$row['id'];
                                $stockQty = (int)$row['quantity_in_stock'];
                                $reserved = (int)$row['quantity_reserved'];
                                $availableBatch = $stockQty - $reserved;
                                if ($availableBatch <= 0) continue;
                                $take = min($availableBatch, $need);
                                $newQty = $stockQty - $take;
                                $uStmt = $conn->prepare('UPDATE inventory_stock SET quantity_in_stock = ?, last_movement_date = NOW() WHERE id = ?');
                                $uStmt->bind_param('ii', $newQty, $stockId);
                                $uStmt->execute();
                                $uStmt->close();

                                $performedBy = (int)($_SESSION['user_id'] ?? 0);
                                $conn->query("INSERT INTO inventory_transactions (item_id, transaction_type, quantity, unit_cost, reference_type, reference_id, notes, performed_by) VALUES ($itemId, 'issue', $take, " . ($unitPrice>0 ? $unitPrice : 'NULL') . ", 'patient', $progressNoteId, 'Issued for progress note ID $progressNoteId', $performedBy)");

                                $need -= $take;
                                if ($need <= 0) break;
                            }
                            break;
                        }

                        // Add to invoice as an item
                        if ($invoiceId) {
                            $desc = $conn->real_escape_string($medName);
                            $q = 1;
                            $unit = $unitPrice;
                            $total = $unit * $q;
                            $conn->query("INSERT INTO invoice_items (invoice_id, service_id, item_description, quantity, unit_price, total_price, reference_type, reference_id) VALUES ($invoiceId, NULL, '" . $desc . "', $q, $unit, $total, 'inventory', $itemId)");
                        }
                    } else {
                        // insufficient stock: append note to progress_notes
                        $conn->query("UPDATE progress_notes SET notes = CONCAT(IFNULL(notes,''), ' [Note: insufficient stock for " . $conn->real_escape_string($medName) . "]') WHERE id = $progressNoteId");
                    }
                } else {
                    // no matching inventory item — append note
                    $conn->query("UPDATE progress_notes SET notes = CONCAT(IFNULL(notes,''), ' [Note: inventory item not found for " . $conn->real_escape_string($medName) . "]') WHERE id = $progressNoteId");
                }
            }

            // Recalculate invoice totals
            if ($invoiceId) {
                $sumRes = $conn->query("SELECT COALESCE(SUM(total_price),0) as total FROM invoice_items WHERE invoice_id = $invoiceId");
                $totalAmount = $sumRes && $sumRes->num_rows ? (float)$sumRes->fetch_assoc()['total'] : 0.0;
                // for simplicity, keep discount and tax as-is
                $invRow2 = $conn->query("SELECT paid_amount, discount_amount, tax_amount FROM invoices WHERE id = $invoiceId");
                $paid = 0.0; $discount = 0.0; $tax = 0.0;
                if ($invRow2 && $invRow2->num_rows) {
                    $r2 = $invRow2->fetch_assoc();
                    $paid = (float)$r2['paid_amount'];
                    $discount = (float)$r2['discount_amount'];
                    $tax = (float)$r2['tax_amount'];
                }
                $net = $totalAmount - $discount + $tax;
                $balance = $net - $paid;
                $conn->query("UPDATE invoices SET total_amount = $totalAmount, net_amount = $net, balance_amount = $balance WHERE id = $invoiceId");
            }
        }

        logActivity('create', 'progress_notes', $progressNoteId, null, json_encode(['admission_id'=>$admission_id,'patient_id'=>$patient_id]));
        setFlashMessage('success', 'Progress note added.');
        $stmt2->close();
        $conn->close();
        redirect('modules/admission/admission-view.php?id=' . $admission_id);
    } else {
        setFlashMessage('error', 'Failed to add note: ' . $stmt2->error);
        $stmt2->close();
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Add Progress Note</h1>
        <p class="page-subtitle">Admission for <?php echo htmlspecialchars($admission['first_name'] . ' ' . $admission['last_name']); ?></p>
    </div>
    <div>
        <a href="admission-view.php?id=<?php echo $admission_id; ?>" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <form method="post" action="monitoring-add.php">
            <input type="hidden" name="admission_id" value="<?php echo $admission_id; ?>">
            <div class="form-row">
                <div class="form-group col-md-3">
                    <label>Note Type</label>
                    <select name="note_type" class="form-control">
                        <option value="vital-signs">Vital Signs</option>
                        <option value="nursing-note" selected>Nursing Note</option>
                        <option value="medication">Medication</option>
                        <option value="procedure">Procedure</option>
                        <option value="with-doctor">With Doctor</option>
                    </select>
                </div>
                <div class="form-group col-md-3">
                    <label>Date &amp; Time</label>
                    <input type="datetime-local" name="recorded_at" class="form-control" value="<?php echo date('Y-m-d\TH:i'); ?>">
                </div>
                <div class="form-group col-md-3">
                    <label>BP</label>
                    <input type="text" name="blood_pressure" class="form-control" placeholder="e.g., 120/80">
                </div>
                <div class="form-group col-md-1">
                    <label>HR</label>
                    <input type="number" name="heart_rate" class="form-control">
                </div>
                <div class="form-group col-md-1">
                    <label>Temp (°C)</label>
                    <input type="number" step="0.1" name="temperature" class="form-control">
                </div>
                <div class="form-group col-md-1">
                    <label>RR</label>
                    <input type="number" name="respiratory_rate" class="form-control">
                </div>
                <div class="form-group col-md-1">
                    <label>SpO2</label>
                    <input type="number" name="oxygen_saturation" class="form-control">
                </div>
            </div>

            <div class="form-group">
                <label>General Condition / Assessment</label>
                <select id="general_choice" class="form-control">
                    <option value="">-- Select condition --</option>
                    <option value="Stable">Stable</option>
                    <option value="Improving">Improving</option>
                    <option value="Unstable">Unstable</option>
                    <option value="Deteriorating">Deteriorating</option>
                    <option value="Critical">Critical</option>
                    <option value="Other">Other (specify)</option>
                </select>
                <textarea name="general_condition" id="general_condition" class="form-control" rows="2" style="margin-top:8px;"></textarea>
            </div>

            <div class="form-group">
                <label>Observation</label>
                <select id="observation_choice" class="form-control">
                    <option value="">-- Select observation --</option>
                    <option value="Normal">Normal</option>
                    <option value="Wound clean">Wound clean</option>
                    <option value="Bleeding">Bleeding</option>
                    <option value="Signs of infection">Signs of infection</option>
                    <option value="Other">Other (specify)</option>
                </select>
                <textarea name="observation" id="observation" class="form-control" rows="2" style="margin-top:8px;"></textarea>
            </div>
            <div class="form-group">
                <label>Given Medication?</label>
                <select id="med_given_choice" name="med_given_choice" class="form-control">
                    <option value="No">No</option>
                    <option value="Yes">Yes</option>
                </select>
                <div id="med_block" style="display:none;">
                    <label style="margin-top:8px; display:block;">Select medication</label>
                    <select id="medications_select" name="medications_given[]" class="form-control" multiple style="min-height:120px;">
                    <?php
                    // Ensure inventory_items has item_type to distinguish Medicine vs Supply
                    $col = $conn->query("SHOW COLUMNS FROM inventory_items LIKE 'item_type'");
                    if (!$col || $col->num_rows === 0) {
                        $conn->query("ALTER TABLE inventory_items ADD COLUMN item_type VARCHAR(32) DEFAULT 'Medicine'");
                    }
                    // Only include items marked as Medicine and with stock > 0
                    // Aggregate stock by item name so identical medicines (same name/strength)
                    // are merged and quantities summed instead of showing duplicate options.
                    $inv = $conn->query("SELECT i.item_name, COALESCE(SUM(IFNULL(s.quantity_in_stock,0) - IFNULL(s.quantity_reserved,0)),0) AS qty FROM inventory_items i LEFT JOIN inventory_stock s ON i.id = s.item_id WHERE i.status = 'active' AND (i.item_type = 'Medicine' OR i.item_type = 'medicine') GROUP BY i.item_name ORDER BY i.item_name ASC");
                    if ($inv) {
                        while ($it = $inv->fetch_assoc()) {
                            if ((int)$it['qty'] <= 0) continue;
                            echo '<option value="' . htmlspecialchars($it['item_name']) . '">' . htmlspecialchars($it['item_name']) . ' (' . intval($it['qty']) . ')</option>';
                        }
                    }
                    ?>
                    </select>
                </div>

            <div class="form-group">
                <label>Intervention (Tagalog — ginawa ng nurse)</label>
                <textarea name="intervention" class="form-control" rows="2" placeholder="Halimbawa: binigyan ng gamot, nilinis ang sugat, inilagay sa IV"></textarea>
            </div>

            <div class="form-group">
                <label>Patient Response</label>
                <textarea name="patient_response" class="form-control" rows="2"></textarea>
            </div>

           
            </div>

            <div class="form-row">
                <div class="form-group col-md-6">
                    <label>Intake / Output (if monitored)</label>
                    <input type="text" name="intake_output" class="form-control" placeholder="e.g., PO 200ml; urine 300ml">
                </div>
            </div>

            <div class="form-group text-right">
                <button type="submit" class="btn btn-primary">Add Note</button>
            </div>
        </form>
    </div>
</div>

<script>
// Local save for daily checks (no DB)
const admissionId = <?php echo (int)$admission_id; ?>;
const nurseName = <?php echo json_encode($_SESSION['full_name'] ?? ''); ?>;

function isoDateFromInput(dtLocal){
    if (!dtLocal) return new Date().toISOString().slice(0,10);
    // dtLocal expected as YYYY-MM-DDTHH:MM
    return dtLocal.split('T')[0];
}

function collectFormData(){
    const f = document.forms[0];
    return {
        admission_id: admissionId,
        recorded_at: f.recorded_at.value || new Date().toISOString(),
        note_type: f.note_type.value || 'nursing-note',
        blood_pressure: f.blood_pressure.value || '',
        heart_rate: f.heart_rate.value || '',
        temperature: f.temperature.value || '',
        respiratory_rate: f.respiratory_rate.value || '',
        oxygen_saturation: f.oxygen_saturation.value || '',
        general_condition: f.general_condition.value || '',
        observation: f.observation.value || '',
        intervention: f.intervention.value || '',
        patient_response: f.patient_response.value || '',
        intake_output: f.intake_output.value || '',
        notes: f.notes.value || '',
        nurse: nurseName || ''
    };
}

function storageKey(admId, dateStr){
    return `daily_checks_${admId}_${dateStr}`;
}

document.getElementById('saveLocalBtn').addEventListener('click', function(){
    const data = collectFormData();
    const dateKey = isoDateFromInput(data.recorded_at);
    const key = storageKey(admissionId, dateKey);
    let arr = [];
    try { arr = JSON.parse(localStorage.getItem(key) || '[]'); } catch(e){ arr = []; }
    arr.push(data);
    localStorage.setItem(key, JSON.stringify(arr));
    alert('Saved locally for ' + dateKey + '. You can preview or print the daily note.');
});

document.getElementById('previewTodayBtn').addEventListener('click', function(){
    const f = document.forms[0];
    const dateKey = isoDateFromInput(f.recorded_at.value || '');
    const url = '<?php echo BASE_URL; ?>modules/nursing/daily-note-preview.php?admission_id='+admissionId+'&date='+encodeURIComponent(dateKey);
    window.open(url, '_blank');
});
</script>

<script>
// General Condition dropdown + Other handling
(function(){
    var choice = document.getElementById('general_choice');
    var field = document.getElementById('general_condition');
    var existing = <?php echo json_encode($_POST['general_condition'] ?? ''); ?>;

    function sync() {
        var val = choice.value;
        if (val === 'Other') {
            field.style.display = '';
            field.readOnly = false;
            if (!field.value && existing) field.value = existing;
        } else if (val === '') {
            field.style.display = 'none';
            if (!existing) field.value = '';
        } else {
            field.style.display = 'none';
            field.value = val;
            field.readOnly = true;
        }
    }

    // initialize
    if (existing && ["Stable","Improving","Unstable","Deteriorating","Critical"].indexOf(existing) !== -1) {
        choice.value = existing;
    } else if (existing && existing !== '') {
        choice.value = 'Other';
    } else {
        choice.value = '';
    }

    choice.addEventListener('change', sync);
    field.addEventListener('input', function(){ /* keep current text */ });
    sync();
})();
// Observation dropdown + Other handling
(function(){
    var choice = document.getElementById('observation_choice');
    var field = document.getElementById('observation');
    var existing = <?php echo json_encode($_POST['observation'] ?? ''); ?>;

    function sync() {
        var val = choice.value;
        if (val === 'Other') {
            field.style.display = '';
            field.readOnly = false;
            if (!field.value && existing) field.value = existing;
        } else if (val === '') {
            field.style.display = 'none';
            if (!existing) field.value = '';
        } else {
            field.style.display = 'none';
            field.value = val;
            field.readOnly = true;
        }
    }

    if (existing && ["Normal","Wound clean","Bleeding","Signs of infection"].indexOf(existing) !== -1) {
        choice.value = existing;
    } else if (existing && existing !== '') {
        choice.value = 'Other';
    } else {
        choice.value = '';
    }

    choice.addEventListener('change', sync);
    field.addEventListener('input', function(){});
    sync();
})();

// Medication given handling — searchable + scrollable multi-select using Choices.js
(function(){
    var medChoice = document.getElementById('med_given_choice');
    var medSelect = document.getElementById('medications_select');
    if (!medChoice || !medSelect) return;
    var choicesMed = null;

    function initChoices(){
        if (typeof Choices === 'undefined') return;
        try {
            choicesMed = new Choices(medSelect, {
                removeItemButton: false,
                searchEnabled: true,
                shouldSort: false,
                placeholder: true,
                placeholderValue: 'Search medications...',
                searchPlaceholderValue: 'Type to search'
            });

            // make choices container scrollable and reasonably tall
            var container = medSelect.parentNode.querySelector('.choices');
            if (container) {
                container.style.maxHeight = '220px';
                container.style.overflowY = 'auto';
            }
        } catch (e) {
            // fail silently — fallback to native select
        }
    }

    function getChoicesContainer(){
        return medSelect.parentNode ? medSelect.parentNode.querySelector('.choices') : null;
    }

    function syncMed(){
        var container = getChoicesContainer();
        var medBlock = document.getElementById('med_block');
        if (medChoice.value === 'Yes') {
            if (medBlock) medBlock.style.display = '';
            if (container) container.style.display = '';
        } else {
            if (medBlock) medBlock.style.display = 'none';
            if (container) container.style.display = 'none';
        }
    }

    document.addEventListener('DOMContentLoaded', function(){
        // restore previous selection from server if any
        var prevMedGiven = <?php echo json_encode($_POST['med_given_choice'] ?? 'No'); ?>;
        var prevMeds = <?php echo json_encode($_POST['medications_given'] ?? []); ?>;
        if (prevMedGiven && medChoice) medChoice.value = prevMedGiven;
        if (prevMeds && medSelect) {
            for (var i=0;i<medSelect.options.length;i++){
                var opt = medSelect.options[i];
                if (prevMeds.indexOf(opt.value) !== -1) opt.selected = true;
            }
        }

        initChoices();
        syncMed();
    });

    medChoice.addEventListener('change', syncMed);
})();
</script>

<?php include __DIR__ . '/../../includes/footer.php';
