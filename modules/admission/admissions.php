<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'nurse', 'doctor']);

$pageTitle = 'Patient Admissions';
$currentPage = 'admissions';

$conn = getDBConnection();

// Get current admissions
$currentAdmissions = $conn->query("
    SELECT a.*, p.first_name, p.last_name, p.patient_code, p.gender, p.date_of_birth,
           r.room_number, r.room_type, b.bed_number, u.full_name as doctor_name
    FROM admissions a
    JOIN patients p ON a.patient_id = p.id
    LEFT JOIN rooms r ON a.room_id = r.id
    LEFT JOIN beds b ON a.bed_id = b.id
    JOIN users u ON a.doctor_id = u.id
    WHERE a.status = 'admitted'
    ORDER BY a.admission_date DESC
");

// Get rooms (include occupied rooms so availability view shows them too)
$availableRooms = $conn->query("
    SELECT r.*, 
        COALESCE(NULLIF(COUNT(b.id),0), 2) AS total_beds,
        IF(COUNT(b.id) > 0,
            SUM(CASE WHEN b.status = 'available' THEN 1 ELSE 0 END),
            GREATEST(0, 2 - COALESCE((SELECT COUNT(*) FROM admissions a WHERE a.room_id = r.id AND a.status = 'admitted'), 0))
        ) AS available_beds
    FROM rooms r
    LEFT JOIN beds b ON r.id = b.room_id
    GROUP BY r.id
");

$conn->close();

include __DIR__ . '/../../includes/header.php';
?>
<style>
.room-card {
    background: var(--surface-muted);
    padding: 15px;
    border-radius: 10px;
    transition: background 180ms ease, box-shadow 180ms ease, transform 180ms ease;
    text-decoration: none;
    color: inherit;
    display: block;
}
.room-card:hover {
    background: var(--surface-color);
    box-shadow: 0 6px 20px rgba(0,0,0,0.12);
    transform: translateY(-2px);
}
.room-card h4 {
    margin: 0 0 5px;
    font-size: 15px;
    font-weight: 700;
    color: var(--text-color);
}
.room-card .room-type {
    margin: 0;
    color: var(--text-muted);
    font-size: 13px;
}
.room-card .room-beds {
    margin: 10px 0 0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.room-card .room-total {
    font-size: 12px;
    color: var(--text-muted);
}
.room-legend {
    margin-top: 14px;
    display: flex;
    gap: 18px;
    align-items: center;
    flex-wrap: wrap;
    font-size: 13px;
    color: var(--text-muted);
}
.room-legend strong {
    color: var(--text-color);
    margin-right: 4px;
}
.room-legend-item {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    color: var(--text-muted);
}
.room-legend-dot {
    display: inline-block;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    flex-shrink: 0;
}
.empty-state {
    padding: 48px;
    text-align: center;
    color: var(--text-muted);
}
.empty-state i {
    font-size: 48px;
    margin-bottom: 15px;
    display: block;
    opacity: 0.5;
}
</style>
<?php
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Patient Admissions</h1>
        <p class="page-subtitle">Manage inpatient admissions and room assignments</p>
    </div>
    <a href="admission-add.php" class="btn btn-primary">
        <i class="fas fa-plus"></i> New Admission
    </a>
</div>

<!-- Room Status -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-door-open"></i> Room Availability</h3>
    </div>
    <div class="card-body">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
            <?php while ($room = $availableRooms->fetch_assoc()): ?>
            <?php
                // Determine availability based on bed counts
                $totalBeds = intval($room['total_beds']);
                $availableBeds = intval($room['available_beds']);
                if ($totalBeds > 0) {
                    if ($availableBeds === $totalBeds) {
                        // all beds available
                        $border = '#28a745'; // green
                        $badgeClass = 'badge-success';
                        $availabilityText = 'All available';
                    } elseif ($availableBeds === 0) {
                        // all beds occupied
                        $border = '#dc3545'; // red
                        $badgeClass = 'badge-danger';
                        $availabilityText = 'Full';
                    } else {
                        // some beds occupied
                        $border = '#ffc107'; // yellow
                        $badgeClass = 'badge-warning';
                        $availabilityText = 'Partially occupied';
                    }
                } else {
                    // unknown capacity fallback
                    $border = '#6c757d'; // gray
                    $badgeClass = 'badge-secondary';
                    $availabilityText = ucfirst($room['status'] ?? 'unknown');
                }
            ?>
            <a href="room-view.php?room_id=<?php echo intval($room['id']); ?>" class="room-card" style="border-left: 4px solid <?php echo $border; ?>;">
                <h4>Room <?php echo htmlspecialchars($room['room_number']); ?></h4>
                <p class="room-type"><?php echo htmlspecialchars(ucfirst($room['room_type'])); ?> &bull; <?php echo htmlspecialchars($availabilityText); ?></p>
                <div class="room-beds">
                    <span class="badge <?php echo $badgeClass; ?>"><?php echo intval($room['available_beds']); ?> Available</span>
                    <span class="room-total">/ <?php echo intval($room['total_beds']); ?> Total</span>
                </div>
            </a>
            <?php endwhile; ?>
        </div>
        <!-- Legend for availability colors -->
        <div class="room-legend">
            <strong>Legend:</strong>
            <span class="room-legend-item">
                <span class="room-legend-dot" style="background:rgba(40,167,69,0.18); border:2px solid #28a745;"></span>
                Available
            </span>
            <span class="room-legend-item">
                <span class="room-legend-dot" style="background:rgba(220,53,69,0.18); border:2px solid #dc3545;"></span>
                Full
            </span>
            <span class="room-legend-item">
                <span class="room-legend-dot" style="background:rgba(255,193,7,0.18); border:2px solid #ffc107;"></span>
                Occupied (not full)
            </span>
        </div>
    </div>
</div>

<!-- Current Admissions -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-procedures"></i> Current Admissions</h3>
        <span class="badge badge-primary"><?php echo $currentAdmissions->num_rows; ?> Admitted</span>
    </div>
    <div class="card-body" style="padding: 0;">
        <?php if ($currentAdmissions && $currentAdmissions->num_rows > 0): ?>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Admission Code</th>
                        <th>Patient</th>
                        <th>Room/Bed</th>
                        <th>Doctor</th>
                        <th>Admission Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($admission = $currentAdmissions->fetch_assoc()): ?>
                    <tr>
                        <td><strong><?php echo $admission['admission_code']; ?></strong></td>
                        <td>
                            <?php echo $admission['first_name'] . ' ' . $admission['last_name']; ?><br>
                            <small class="text-muted"><?php echo $admission['patient_code']; ?> | 
                            <?php echo calculateAge($admission['date_of_birth']); ?> yrs | <?php echo $admission['gender']; ?></small>
                        </td>
                        <td>
                            <?php if ($admission['room_number']): ?>
                                Room <?php echo $admission['room_number']; ?><br>
                                <small class="text-muted">Bed <?php echo $admission['bed_number']; ?> (<?php echo ucfirst($admission['room_type']); ?>)</small>
                            <?php else: ?>
                                <span class="text-muted">Not assigned</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $admission['doctor_name']; ?></td>
                        <td><?php echo formatDateTime($admission['admission_date']); ?></td>
                        <td class="table-actions">
                            <a href="admission-view.php?id=<?php echo $admission['id']; ?>" class="btn btn-sm btn-info" title="View">
                                <i class="fas fa-eye"></i>
                            </a>
                            <?php if (hasRole(['admin', 'nurse', 'doctor'])): ?>
                            <a href="admission-edit.php?id=<?php echo $admission['id']; ?>" class="btn btn-sm btn-secondary" title="Edit Admission">
                                <i class="fas fa-edit"></i>
                            </a>
                            <?php endif; ?>
                            <?php if (hasRole(['admin', 'nurse'])): ?>
                            <a href="monitoring-add.php?admission_id=<?php echo $admission['id']; ?>" class="btn btn-sm btn-success" title="Add Notes">
                                <i class="fas fa-notes-medical"></i>
                            </a>
                            <?php endif; ?>
                            <?php if (hasRole(['admin', 'doctor'])): ?>
                            <a href="discharge.php?admission_id=<?php echo $admission['id']; ?>" class="btn btn-sm btn-warning" title="Discharge">
                                <i class="fas fa-sign-out-alt"></i>
                            </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-bed"></i>
            <p>No patients currently admitted</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
