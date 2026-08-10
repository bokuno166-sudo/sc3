<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

$pageTitle = 'Daily Note Preview';
$currentPage = 'nursing';
include __DIR__ . '/../../includes/header.php';

$admission_id = isset($_GET['admission_id']) ? (int)$_GET['admission_id'] : 0;
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

?>

<div class="page-header">
    <div>
        <h1 class="page-title">Daily Note Preview</h1>
        <p class="page-subtitle">Admission ID: <?php echo htmlspecialchars($admission_id); ?> — Date: <?php echo htmlspecialchars($date); ?></p>
    </div>
    <div>
        <button class="btn btn-primary" id="printBtn">Print</button>
    </div>
</div>

<div class="card">
    <div class="card-body" id="previewArea">
        <p class="text-muted">Loading saved checks...</p>
    </div>
</div>

<script>
(function(){
    const admissionId = <?php echo (int)$admission_id; ?>;
    const dateKey = <?php echo json_encode($date); ?>;
    const key = `daily_checks_${admissionId}_${dateKey}`;
    let arr = [];
    try { arr = JSON.parse(localStorage.getItem(key) || '[]'); } catch(e){ arr = []; }

    const area = document.getElementById('previewArea');
    if (!arr.length) {
        area.innerHTML = '<div class="text-muted">No local entries for this admission and date.</div>';
        return;
    }

    // Combine into a single daily note
    const nurseList = {};
    let vitalsLines = [];
    let narrative = [];
    let intakeOutputLines = [];

    arr.forEach(entry => {
        nurseList[entry.nurse || 'Unknown'] = true;
        // vitals summary line
        const vit = [];
        if (entry.blood_pressure) vit.push('BP: ' + entry.blood_pressure);
        if (entry.heart_rate) vit.push('HR: ' + entry.heart_rate);
        if (entry.temperature) vit.push('Temp: ' + entry.temperature + '°C');
        if (entry.respiratory_rate) vit.push('RR: ' + entry.respiratory_rate);
        if (entry.oxygen_saturation) vit.push('SpO2: ' + entry.oxygen_saturation + '%');
        if (vit.length) vitalsLines.push(`<div>${entry.recorded_at}: ${vit.join('; ')}</div>`);

        // narrative sections
        if (entry.general_condition) narrative.push('<div><strong>Assessment:</strong> ' + escapeHtml(entry.general_condition) + '</div>');
        if (entry.observation) narrative.push('<div><strong>Observation:</strong> ' + escapeHtml(entry.observation) + '</div>');
        if (entry.intervention) narrative.push('<div><strong>Intervention:</strong> ' + escapeHtml(entry.intervention) + '</div>');
        if (entry.patient_response) narrative.push('<div><strong>Patient Response:</strong> ' + escapeHtml(entry.patient_response) + '</div>');
        if (entry.notes) narrative.push('<div><strong>Notes:</strong> ' + escapeHtml(entry.notes) + '</div>');
        if (entry.intake_output) intakeOutputLines.push('<div>' + escapeHtml(entry.recorded_at + ': ' + entry.intake_output) + '</div>');
    });

    const nurses = Object.keys(nurseList).join(', ');

    const html = `
        <h3>Daily Nursing Note — ${escapeHtml(dateKey)}</h3>
        <div><strong>Admission:</strong> ${escapeHtml(String(admissionId))}</div>
        <div><strong>Recorded by (nurses):</strong> ${escapeHtml(nurses)}</div>
        <hr>
        <h4>Vitals Timeline</h4>
        <div>${vitalsLines.join('')}</div>
        <hr>
        <h4>Assessment / Observations / Interventions</h4>
        <div>${narrative.join('')}</div>
        <hr>
        <h4>Intake / Output</h4>
        <div>${intakeOutputLines.join('') || '<div class="text-muted">None recorded</div>'}</div>
        <hr>
        <div style="margin-top:18px">Signature: _______________________</div>
        <div>Printed: ${new Date().toLocaleString()}</div>
    `;

    area.innerHTML = html;

    document.getElementById('printBtn').addEventListener('click', function(){ window.print(); });

    function escapeHtml(s){ return String(s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":"&#39;"}[c]; }); }
})();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
