<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['doctor']);

$visitId = isset($_GET['visit_id']) ? (int)$_GET['visit_id'] : 0;
$conn = getDBConnection();
$patient = null;
if ($visitId) {
    $res = $conn->query("SELECT v.*, p.* FROM patient_visits v JOIN patients p ON v.patient_id = p.id WHERE v.id = $visitId");
    if ($res && $res->num_rows) $patient = $res->fetch_assoc();
}

$pageTitle = 'Create Prescription';
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Prescription</h1>
        <p class="page-subtitle"><?php echo $patient ? 'For: ' . $patient['first_name'] . ' ' . $patient['last_name'] : 'New Prescription'; ?></p>
    </div>
    <a href="../consultation/consultation-start.php?visit_id=<?php echo $visitId; ?>" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to Consultation
    </a>
</div>

<div class="card">
    <div class="card-header" style="display:flex; align-items:center;">
        <h3 class="card-title"><i class="fas fa-prescription"></i> Prescription Form</h3>
        <div style="margin-left:auto; display:flex; gap:8px;">
            <button type="button" class="btn btn-sm btn-success" onclick="addMedication()"><i class="fas fa-plus"></i> Add</button>
        </div>
    </div>
    <div class="card-body">
        <form id="prescriptionForm">
            <div id="medicationsContainer"></div>
            <div style="margin-top:16px; text-align:right;">
                <button type="button" class="btn btn-success" onclick="applyAndReturn()">Apply & Return</button>
            </div>
        </form>
    </div>
</div>

<script>
let medicationCount = 0;

function addMedication(prefill = {}) {
    medicationCount++;
    const container = document.getElementById('medicationsContainer');
    const div = document.createElement('div');
    div.className = 'form-row medication-row';
    div.style.marginBottom = '12px';
    div.style.padding = '10px';
    div.style.border = '1px solid #eee';
    div.innerHTML = `
        <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:end;">
            <div style="flex:2; min-width:220px;">
                <label class="form-label">Medication Name</label>
                <input type="text" name="medications[]" class="form-control" placeholder="e.g., Paracetamol" required value="${prefill.medication || ''}">
            </div>
            <div style="flex:1; min-width:120px;">
                <label class="form-label">Strength / Form</label>
                <input type="text" name="strength_forms[]" class="form-control" placeholder="500 mg / tablet" value="${prefill.strength || ''}">
            </div>
            <div style="flex:1; min-width:120px;">
                <label class="form-label">Dose</label>
                <input type="text" name="dosage[]" class="form-control" placeholder="e.g., 1 tablet" value="${prefill.dose || ''}">
            </div>
            <div style="flex:1; min-width:140px;">
                <label class="form-label">Frequency</label>
                <input type="text" name="frequency[]" class="form-control" placeholder="e.g., 3x daily" value="${prefill.frequency || ''}">
            </div>
            <div style="flex:1; min-width:120px;">
                <label class="form-label">Duration</label>
                <input type="text" name="duration[]" class="form-control" placeholder="e.g., 5 days" value="${prefill.duration || ''}">
            </div>
            <div style="flex:1; min-width:100px;">
                <label class="form-label">Qty</label>
                <input type="number" name="quantity[]" class="form-control" placeholder="Qty" value="${prefill.quantity || ''}">
            </div>
            <div style="flex:1; min-width:100px;">
                <label class="form-label">Refills</label>
                <input type="number" name="refills[]" class="form-control" value="${prefill.refills || 0}" min="0">
            </div>
            <div style="flex:1 1 100%;">
                <label class="form-label">Instructions</label>
                <input type="text" name="med_instructions[]" class="form-control" placeholder="Patient directions" value="${prefill.instructions || ''}">
            </div>
            <div style="display:flex; align-items:flex-end;">
                <button type="button" class="btn btn-danger" onclick="this.closest('.medication-row').remove()"><i class="fas fa-trash"></i></button>
            </div>
        </div>
    `;
    container.appendChild(div);
}

function printPreview() {
    // Gather current medications from the form
    const rows = document.querySelectorAll('.medication-row');
    const meds = [];
    rows.forEach(row => {
        const get = (name) => { const el = row.querySelector('[name="'+name+'"]'); return el ? el.value : ''; };
        if (get('medications[]')) {
            meds.push({
                medication: get('medications[]'),
                dose: get('dosage[]'),
                frequency: get('frequency[]'),
                duration: get('duration[]'),
                instructions: get('med_instructions[]')
            });
        }
    });
    let html = '<h2>Prescription</h2>';
    if (<?php echo $patient ? 'true' : 'false'; ?>) {
        html += '<p><?php echo addslashes($patient ? $patient['first_name'].' '.$patient['last_name'] : ''); ?></p>';
    }
    meds.forEach(m => {
        html += `<div style="margin-bottom:12px;"><strong>${m.medication}</strong><br>${m.dose || ''} ${m.frequency || ''} ${m.duration ? '('+m.duration+')' : ''}<br><small>${m.instructions || ''}</small></div>`;
    });
    const win = window.open('', '_blank');
    win.document.write('<html><head><title>Prescription</title></head><body>'+html+'</body></html>');
    win.document.close();
    win.print();
}

function applyAndReturn() {
    // Gather medications from the form
    const rows = document.querySelectorAll('.medication-row');
    const meds = [];
    rows.forEach(row => {
        const get = (name) => { const el = row.querySelector('[name="'+name+'"]'); return el ? el.value : ''; };
        if (get('medications[]')) {
            meds.push({
                medication: get('medications[]'),
                dose: get('dosage[]'),
                frequency: get('frequency[]'),
                duration: get('duration[]'),
                instructions: get('med_instructions[]'),
                quantity: get('quantity[]')
            });
        }
    });

    if (!meds.length) {
        alert('Please add at least one medication before saving the prescription.');
        // ensure user can add a medication easily
        addMedication();
        return;
    }

    // POST to server to persist prescriptions
    fetch('../prescription/prescription-apply.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ visit_id: <?php echo (int)$visitId; ?>, medications: meds })
    }).then(r => r.json()).then(function(resp) {
        if (resp && resp.success) {
            // redirect back to consultation
            window.location.href = '../consultation/consultation-start.php?visit_id=' + <?php echo (int)$visitId; ?>;
        } else {
            alert('Failed to save prescriptions: ' + (resp && resp.message ? resp.message : 'Unknown error'));
        }
    }).catch(function(err){
        alert('Error saving prescriptions: ' + err);
    });
}

// init
if (!document.querySelector('.medication-row')) addMedication();
</script>

<?php include __DIR__ . '/../../includes/footer.php';
