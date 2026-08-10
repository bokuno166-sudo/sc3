<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'nurse', 'doctor']);

$pageTitle = 'Pregnancy Timeline';
$currentPage = 'maternity';

$conn = getDBConnection();

$patientId = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;

// Fetch patient details
$patientRes = $conn->query("SELECT * FROM patients WHERE id = $patientId");
if (!$patientRes || $patientRes->num_rows === 0) {
    setFlashMessage('error', 'Patient not found.');
    redirect('modules/maternity/index.php');
}
$patient = $patientRes->fetch_assoc();

// Gather all timeline events
$events = [];

// 1. Fetch Maternity Check-ups
$checkupsQuery = $conn->query("
    SELECT mc.*, u.full_name as user_name, 'checkup' as event_type, mc.checkup_date as event_date
    FROM maternity_checkups mc
    LEFT JOIN users u ON mc.created_by = u.id
    WHERE mc.patient_id = $patientId
");
if ($checkupsQuery) {
    while ($row = $checkupsQuery->fetch_assoc()) {
        $events[] = $row;
    }
}

// 2. Fetch Triage Records
$triageQuery = $conn->query("
    SELECT tr.*, u.full_name as user_name, 'triage' as event_type, tr.created_at as event_date
    FROM triage_records tr
    LEFT JOIN users u ON tr.nurse_id = u.id
    WHERE tr.patient_id = $patientId
");
if ($triageQuery) {
    while ($row = $triageQuery->fetch_assoc()) {
        $events[] = $row;
    }
}

// 3. Fetch Admissions
$admissionsQuery = $conn->query("
    SELECT a.*, r.room_number, b.bed_number, u.full_name as doctor_name, 'admission' as event_type, a.admission_date as event_date
    FROM admissions a
    LEFT JOIN rooms r ON a.room_id = r.id
    LEFT JOIN beds b ON a.bed_id = b.id
    LEFT JOIN users u ON a.doctor_id = u.id
    WHERE a.patient_id = $patientId
");
if ($admissionsQuery) {
    while ($row = $admissionsQuery->fetch_assoc()) {
        $events[] = $row;
    }
}

// 4. Fetch Deliveries
$deliveriesQuery = $conn->query("
    SELECT dr.*, u1.full_name as doctor_name, u2.full_name as midwife_name, 'delivery' as event_type, dr.delivery_date as event_date
    FROM delivery_records dr
    LEFT JOIN users u1 ON dr.attended_by = u1.id
    LEFT JOIN users u2 ON dr.assistant_midwife = u2.id
    WHERE dr.patient_id = $patientId
");
if ($deliveriesQuery) {
    while ($row = $deliveriesQuery->fetch_assoc()) {
        $events[] = $row;
    }
}

// 5. Fetch Discharges
$dischargesQuery = $conn->query("
    SELECT dc.*, u1.full_name as checked_by_name, u2.full_name as approved_by_name, 'discharge' as event_type, dc.discharge_date as event_date
    FROM discharge_records dc
    LEFT JOIN users u1 ON dc.discharge_checked_by = u1.id
    LEFT JOIN users u2 ON dc.discharge_approved_by = u2.id
    WHERE dc.patient_id = $patientId
");
if ($dischargesQuery) {
    while ($row = $dischargesQuery->fetch_assoc()) {
        $events[] = $row;
    }
}

// Sort all events by date ascending
usort($events, function($a, $b) {
    $timeA = strtotime($a['event_date']);
    $timeB = strtotime($b['event_date']);
    return $timeA <=> $timeB;
});

include __DIR__ . '/../../includes/header.php';
?>

<style>
/* Modern Interactive Pregnancy Timeline styling */
.maternity-timeline {
    position: relative;
    padding: 20px 0;
    margin: 20px 0;
    list-style: none;
}
.maternity-timeline::before {
    content: '';
    position: absolute;
    top: 0;
    bottom: 0;
    left: 40px;
    width: 4px;
    background: #e9ecef;
    border-radius: 2px;
}
.timeline-item {
    position: relative;
    margin-bottom: 30px;
}
.timeline-item::after {
    content: '';
    display: table;
    clear: both;
}
.timeline-badge {
    position: absolute;
    top: 15px;
    left: 20px;
    width: 44px;
    height: 44px;
    border-radius: 50%;
    text-align: center;
    line-height: 44px;
    font-size: 18px;
    color: #fff;
    z-index: 100;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}
.timeline-panel {
    margin-left: 80px;
    background: #fff;
    border: 1px solid #e3e6f0;
    border-radius: 8px;
    padding: 20px;
    position: relative;
    box-shadow: 0 2px 4px rgba(0,0,0,0.02);
    transition: transform 0.2s, box-shadow 0.2s;
}
.timeline-panel:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.05);
}
.timeline-date {
    font-size: 13px;
    color: #858796;
    margin-bottom: 8px;
}
.timeline-title {
    margin-top: 0;
    margin-bottom: 12px;
    color: #4e73df;
    font-size: 18px;
    font-weight: 700;
}
.timeline-body {
    font-size: 14px;
    line-height: 1.6;
    color: #5a5c69;
}
.badge-checkup { background: #4e73df; }
.badge-triage { background: #36b9cc; }
.badge-admission { background: #f6c23e; }
.badge-delivery { background: #1cc88a; }
.badge-discharge { background: #e74a3b; }

.vitals-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 10px;
    background: #f8f9fc;
    padding: 12px;
    border-radius: 6px;
    margin-bottom: 10px;
    border-left: 3px solid #dddfeb;
}
.vitals-item {
    font-size: 13px;
}
.vitals-item label {
    display: block;
    font-weight: bold;
    color: #4e73df;
    margin-bottom: 2px;
    font-size: 11px;
    text-transform: uppercase;
}
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">Pregnancy Timeline History</h1>
        <p class="page-subtitle">Detailed progression of patient from initial triage check-up till birthing</p>
    </div>
    <div>
        <a href="index.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
    </div>
</div>

<div style="display: grid; grid-template-columns: 1fr 3fr; gap: 25px; align-items: start;">
    
    <!-- Patient Profile summary card -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-user-circle"></i> Patient Details</h3>
        </div>
        <div class="card-body">
            <h3 style="margin: 0 0 5px;"><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></h3>
            <p class="text-muted" style="margin-bottom: 15px;"><?php echo htmlspecialchars($patient['patient_code']); ?></p>
            <hr>
            <div style="font-size:14px; line-height: 1.8;">
                <div><strong>Age / Gender:</strong> <?php echo calculateAge($patient['date_of_birth']); ?> yrs / <?php echo htmlspecialchars($patient['gender']); ?></div>
                <div><strong>Status:</strong> <?php echo $patient['is_pregnant'] ? '<span class="badge badge-warning">Active Pregnancy</span>' : '<span class="badge badge-success">Delivered/Not Pregnant</span>'; ?></div>
                <?php if ($patient['is_pregnant']): ?>
                    <div><strong>Gestation Age:</strong> <?php echo intval($patient['weeks_of_pregnancy']); ?> weeks</div>
                    <div><strong>Expected Due Date:</strong> <?php echo formatDate($patient['expected_due_date']); ?></div>
                <?php endif; ?>
                <div><strong>Blood Type:</strong> <?php echo htmlspecialchars($patient['blood_type']); ?></div>
                <div><strong>Allergies:</strong> <br><span class="text-danger"><?php echo htmlspecialchars($patient['allergies'] ?: 'None'); ?></span></div>
                <div><strong>Contact:</strong> <?php echo htmlspecialchars($patient['contact_number']); ?></div>
                <div><strong>Address:</strong> <br><span style="font-size:13px; color:#555;"><?php echo htmlspecialchars($patient['address']); ?></span></div>
            </div>
            
            <div style="margin-top: 20px;">
                <a href="../reception/patient-view.php?id=<?php echo $patientId; ?>" class="btn btn-sm btn-info btn-block">
                    <i class="fas fa-id-card"></i> View Full Chart
                </a>
            </div>
        </div>
    </div>
    
    <!-- Chronological Pregnancy Timeline -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-history"></i> Chronological Care Journey</h3>
        </div>
        <div class="card-body">
            <?php if (count($events) > 0): ?>
            <ul class="maternity-timeline">
                <?php foreach ($events as $event): ?>
                    <li class="timeline-item">
                        
                        <!-- Badges with icons mapping the event type -->
                        <?php if ($event['event_type'] === 'checkup'): ?>
                            <div class="timeline-badge badge-checkup" title="Prenatal Check-up"><i class="fas fa-stethoscope"></i></div>
                        <?php elseif ($event['event_type'] === 'triage'): ?>
                            <div class="timeline-badge badge-triage" title="Triage Check-up"><i class="fas fa-heartbeat"></i></div>
                        <?php elseif ($event['event_type'] === 'admission'): ?>
                            <div class="timeline-badge badge-admission" title="Inpatient Admission"><i class="fas fa-door-open"></i></div>
                        <?php elseif ($event['event_type'] === 'delivery'): ?>
                            <div class="timeline-badge badge-delivery" title="Birthing / Child Delivery"><i class="fas fa-baby-carriage"></i></div>
                        <?php elseif ($event['event_type'] === 'discharge'): ?>
                            <div class="timeline-badge badge-discharge" title="Discharge Record"><i class="fas fa-hospital-user"></i></div>
                        <?php endif; ?>
                        
                        <!-- Timeline Panels -->
                        <div class="timeline-panel">
                            <div class="timeline-date">
                                <i class="far fa-calendar-alt"></i> <?php echo formatDateTime($event['event_date']); ?>
                            </div>
                            
                            <!-- Checkup event layout -->
                            <?php if ($event['event_type'] === 'checkup'): ?>
                                <h4 class="timeline-title" style="color: #4e73df;">Prenatal Checkup — <?php echo intval($event['weeks_of_pregnancy']); ?> Weeks Gestation</h4>
                                <div class="timeline-body">
                                    <div class="vitals-grid">
                                        <div class="vitals-item"><label>Weight</label><?php echo $event['weight'] ? $event['weight'] . ' kg' : 'N/A'; ?></div>
                                        <div class="vitals-item"><label>BP</label><?php echo htmlspecialchars($event['blood_pressure'] ?: 'N/A'); ?></div>
                                        <div class="vitals-item"><label>Fetal HR</label><?php echo $event['fetal_heartbeat'] ? $event['fetal_heartbeat'] . ' bpm' : 'N/A'; ?></div>
                                        <div class="vitals-item"><label>Fundal Ht</label><?php echo $event['fundal_height'] ? $event['fundal_height'] . ' cm' : 'N/A'; ?></div>
                                        <div class="vitals-item"><label>Presentation</label><?php echo htmlspecialchars($event['presentation'] ?: 'N/A'); ?></div>
                                    </div>
                                    <?php if (!empty($event['prescribed_vitamins'])): ?>
                                        <p><strong>Vitamins / Rx:</strong><br><span style="background: #eef2f3; padding: 2px 6px; border-radius: 4px; display:inline-block; font-size:13px;"><?php echo nl2br(htmlspecialchars($event['prescribed_vitamins'])); ?></span></p>
                                    <?php endif; ?>
                                    <p><strong>Clinical Notes:</strong><br><?php echo nl2br(htmlspecialchars($event['notes'] ?: 'No clinical notes provided.')); ?></p>
                                    <?php if ($event['next_appointment_date']): ?>
                                        <div style="font-size:12px; margin-top:10px; color:#1cc88a;">
                                            <strong>Next Appt:</strong> <?php echo formatDate($event['next_appointment_date']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <div style="text-align: right; font-size: 11px; color:#999; margin-top:5px;">
                                        Recorded by: <?php echo htmlspecialchars($event['user_name'] ?? 'System'); ?>
                                    </div>
                                </div>
                                
                            <!-- Triage event layout -->
                            <?php elseif ($event['event_type'] === 'triage'): ?>
                                <h4 class="timeline-title" style="color: #36b9cc;">Emergency / Pre-admission Triage</h4>
                                <div class="timeline-body">
                                    <div class="vitals-grid">
                                        <div class="vitals-item"><label>Weight / Ht</label><?php echo $event['weight'] ? $event['weight'] . ' kg' : 'N/A'; ?> / <?php echo $event['height'] ? $event['height'] . ' cm' : 'N/A'; ?></div>
                                        <div class="vitals-item"><label>BP</label><?php echo htmlspecialchars($event['blood_pressure'] ?: 'N/A'); ?></div>
                                        <div class="vitals-item"><label>Temp</label><?php echo $event['temperature'] ? $event['temperature'] . ' °C' : 'N/A'; ?></div>
                                        <div class="vitals-item"><label>Fetal HR</label><?php echo $event['fetal_heartbeat'] ? $event['fetal_heartbeat'] . ' bpm' : 'N/A'; ?></div>
                                        <div class="vitals-item"><label>Dilation</label><?php echo $event['cervix_dilation'] !== null ? $event['cervix_dilation'] . ' cm' : 'N/A'; ?></div>
                                        <div class="vitals-item"><label>Contractions</label><?php echo htmlspecialchars($event['contractions'] ?: 'N/A'); ?></div>
                                    </div>
                                    <p><strong>Chief Complaint / Symptoms:</strong><br><?php echo nl2br(htmlspecialchars($event['symptoms'] ?: 'N/A')); ?></p>
                                    <p><strong>Care Notes:</strong><br><?php echo nl2br(htmlspecialchars($event['notes'] ?: 'No notes.')); ?></p>
                                    <div style="text-align: right; font-size: 11px; color:#999; margin-top:5px;">
                                        Logged by Nurse: <?php echo htmlspecialchars($event['user_name'] ?? 'System'); ?>
                                    </div>
                                </div>
                                
                            <!-- Admission event layout -->
                            <?php elseif ($event['event_type'] === 'admission'): ?>
                                <h4 class="timeline-title" style="color: #f6c23e;">Inpatient Admission to Hospital</h4>
                                <div class="timeline-body">
                                    <div style="background: #fff8e1; border-left: 3px solid #ffb300; padding:12px; border-radius:4px; margin-bottom:10px;">
                                        <div><strong>Admission Code:</strong> <?php echo htmlspecialchars($event['admission_code'] ?? 'N/A'); ?></div>
                                        <div><strong>Room assignment:</strong> Room <?php echo htmlspecialchars($event['room_number']); ?> (Bed: <?php echo htmlspecialchars($event['bed_number'] ?: 'Standard'); ?>)</div>
                                        <div><strong>Physician in Charge:</strong> Dr. <?php echo htmlspecialchars($event['doctor_name']); ?></div>
                                        <div><strong>Reason:</strong> <?php echo nl2br(htmlspecialchars($event['admission_reason'])); ?></div>
                                    </div>
                                    <?php if (!empty($event['notes'])): ?>
                                        <p><strong>Admission Notes:</strong><br><?php echo nl2br(htmlspecialchars($event['notes'])); ?></p>
                                    <?php endif; ?>
                                </div>
                                
                            <!-- Delivery/Birthing event layout -->
                            <?php elseif ($event['event_type'] === 'delivery'): ?>
                                <h4 class="timeline-title" style="color: #1cc88a; font-size: 20px;"><i class="fas fa-baby"></i> Birthing / Delivery Event Log</h4>
                                <div class="timeline-body">
                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom:15px;">
                                        <!-- Baby details card -->
                                        <div style="background: #e8f5e9; border: 1px solid #c8e6c9; border-radius: 6px; padding:12px;">
                                            <h5 style="margin:0 0 10px; color:#2e7d32;"><i class="fas fa-child"></i> Newborn Infant Details</h5>
                                            <table style="width:100%; font-size: 13px; border-spacing: 5px;">
                                                <tr><td><strong>Gender:</strong></td><td><?php echo htmlspecialchars($event['baby_gender']); ?></td></tr>
                                                <tr><td><strong>Birth Weight:</strong></td><td><?php echo $event['baby_weight'] ? $event['baby_weight'] . ' kg' : 'N/A'; ?></td></tr>
                                                <tr><td><strong>Birth Length:</strong></td><td><?php echo $event['baby_length'] ? $event['baby_length'] . ' cm' : 'N/A'; ?></td></tr>
                                                <tr><td><strong>APGAR Score:</strong></td><td><?php echo $event['apgar_score_1min'] . ' (1m) / ' . $event['apgar_score_5min'] . ' (5m) / ' . $event['apgar_score_10min'] . ' (10m)'; ?></td></tr>
                                                <tr><td><strong>Condition:</strong></td><td><span class="badge badge-success"><?php echo htmlspecialchars(ucfirst($event['baby_condition'])); ?></span></td></tr>
                                            </table>
                                        </div>
                                        
                                        <!-- Delivery specs card -->
                                        <div style="background: #f1f8e9; border: 1px solid #dcedc8; border-radius: 6px; padding:12px;">
                                            <h5 style="margin:0 0 10px; color:#558b2f;"><i class="fas fa-female"></i> Mother & Delivery Specs</h5>
                                            <table style="width:100%; font-size: 13px; border-spacing: 5px;">
                                                <tr><td><strong>Method:</strong></td><td><?php echo htmlspecialchars(ucfirst($event['delivery_type'])); ?> Delivery</td></tr>
                                                <tr><td><strong>Mother State:</strong></td><td><span class="badge badge-success"><?php echo htmlspecialchars(ucfirst($event['mother_condition'])); ?></span></td></tr>
                                                <tr><td><strong>Blood Loss:</strong></td><td><?php echo $event['blood_loss_ml'] ? $event['blood_loss_ml'] . ' ml' : 'N/A'; ?></td></tr>
                                                <tr><td><strong>Admission Dilation:</strong></td><td><?php echo $event['cervix_dilation_at_admission'] ? $event['cervix_dilation_at_admission'] . ' cm' : 'N/A'; ?></td></tr>
                                                <tr><td><strong>Attended By:</strong></td><td><?php echo htmlspecialchars($event['doctor_name']); ?></td></tr>
                                            </table>
                                        </div>
                                    </div>
                                    
                                    <?php if (!empty($event['complications'])): ?>
                                        <p style="background: #ffebee; border-left: 3px solid #ef5350; padding:8px; border-radius:4px; font-size:13px; color:#c62828;">
                                            <strong>Delivery Complications:</strong><br><?php echo nl2br(htmlspecialchars($event['complications'])); ?>
                                        </p>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($event['notes'])): ?>
                                        <p><strong>Clinical Birthing Notes:</strong><br><?php echo nl2br(htmlspecialchars($event['notes'])); ?></p>
                                    <?php endif; ?>
                                </div>
                                
                            <!-- Discharge event layout -->
                            <?php elseif ($event['event_type'] === 'discharge'): ?>
                                <h4 class="timeline-title" style="color: #e74a3b;">Discharged from Hospital</h4>
                                <div class="timeline-body">
                                    <div style="background: #fbe9e7; border-left: 3px solid #ff5722; padding: 12px; border-radius: 4px;">
                                        <div><strong>Discharge Type:</strong> <?php echo htmlspecialchars(ucfirst($event['discharge_type'])); ?></div>
                                        <div><strong>Final Diagnosis:</strong><br><span style="font-weight:600;"><?php echo nl2br(htmlspecialchars($event['final_diagnosis'])); ?></span></div>
                                        <div><strong>Discharge Summary:</strong><br><?php echo nl2br(htmlspecialchars($event['discharge_summary'])); ?></div>
                                    </div>
                                    <?php if (!empty($event['medications_on_discharge'])): ?>
                                        <p style="margin-top:10px;"><strong>Take-Home Medications:</strong><br><span style="font-size:13px; font-family:monospace; background:#fafafa; display:block; padding:8px; border:1px dashed #ccc; border-radius:4px;"><?php echo nl2br(htmlspecialchars($event['medications_on_discharge'])); ?></span></p>
                                    <?php endif; ?>
                                    <?php if (!empty($event['follow_up_instructions'])): ?>
                                        <p><strong>Follow-Up Instructions:</strong><br><?php echo nl2br(htmlspecialchars($event['follow_up_instructions'])); ?></p>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php else: ?>
            <div style="padding: 40px; text-align: center; color: #999;">
                <i class="fas fa-road" style="font-size: 48px; margin-bottom: 15px; color: #ccc;"></i>
                <p>No timeline records (checkups, triage, admissions, or delivery logs) exist for this pregnancy yet.</p>
                <p style="font-size:13px;">Use the Maternity Dashboard to log their first prenatal check-up.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
</div>

<?php
$conn->close();
include __DIR__ . '/../../includes/footer.php';
?>
