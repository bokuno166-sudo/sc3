<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'laboratory', 'doctor']);

$pageTitle = 'Laboratory Requests';
$currentPage = 'lab-requests';

$conn = getDBConnection();

// Get pending lab requests (include requests assigned to laboratory workflow)
$pendingRequests = $conn->query("
    SELECT lr.*, p.first_name, p.last_name, p.patient_code, p.gender, p.date_of_birth,
           lt.test_name, lt.category, u.full_name as doctor_name
    FROM laboratory_requests lr
    JOIN patients p ON lr.patient_id = p.id
    JOIN laboratory_tests lt ON lr.test_id = lt.id
    JOIN users u ON lr.doctor_id = u.id
    WHERE lr.status IN ('pending','in-laboratory')
    ORDER BY lr.priority = 'stat' DESC, lr.priority = 'urgent' DESC, lr.requested_at ASC
");

// Get in-progress tests
$inProgress = $conn->query("
    SELECT lr.*, p.first_name, p.last_name, p.patient_code, lt.test_name, u.full_name as doctor_name
    FROM laboratory_requests lr
    JOIN patients p ON lr.patient_id = p.id
    JOIN laboratory_tests lt ON lr.test_id = lt.id
    JOIN users u ON lr.doctor_id = u.id
    WHERE lr.status = 'in-progress'
    ORDER BY lr.requested_at ASC
");

$conn->close();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Laboratory Requests</h1>
        <p class="page-subtitle">Manage laboratory test requests</p>
    </div>
</div>

<!-- Pending Requests -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-clock"></i> Pending Requests</h3>
        <span class="badge badge-warning"><?php echo $pendingRequests->num_rows; ?> Pending</span>
    </div>
    <div class="card-body" style="padding: 0;">
        <?php if ($pendingRequests && $pendingRequests->num_rows > 0): ?>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Request Code</th>
                        <th>Patient</th>
                        <th>Test</th>
                        <th>Category</th>
                        <th>Priority</th>
                        <th>Requested By</th>
                        <th>Time</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($request = $pendingRequests->fetch_assoc()): ?>
                    <tr>
                        <td><strong><?php echo $request['request_code']; ?></strong></td>
                        <td>
                            <?php echo $request['first_name'] . ' ' . $request['last_name']; ?><br>
                            <small class="text-muted"><?php echo $request['patient_code']; ?></small>
                        </td>
                        <td><?php echo $request['test_name']; ?></td>
                        <td><?php echo ucfirst($request['category']); ?></td>
                        <td><?php echo getStatusBadge($request['priority']); ?></td>
                        <td><?php echo $request['doctor_name']; ?></td>
                        <td><?php echo formatDateTime($request['requested_at'], 'M d, h:i A'); ?></td>
                        <td class="table-actions">
                            <a href="result-enter.php?request_id=<?php echo $request['id']; ?>" class="btn btn-sm btn-primary">
                                <i class="fas fa-vial"></i> Process
                            </a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div style="padding: 40px; text-align: center; color: #999;">
            <i class="fas fa-check-circle" style="font-size: 48px; margin-bottom: 15px;"></i>
            <p>No pending laboratory requests</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- In Progress -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-spinner"></i> In Progress</h3>
        <span class="badge badge-info"><?php echo $inProgress->num_rows; ?> In Progress</span>
    </div>
    <div class="card-body" style="padding: 0;">
        <?php if ($inProgress && $inProgress->num_rows > 0): ?>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Request Code</th>
                        <th>Patient</th>
                        <th>Test</th>
                        <th>Requested By</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($request = $inProgress->fetch_assoc()): ?>
                    <tr>
                        <td><strong><?php echo $request['request_code']; ?></strong></td>
                        <td>
                            <?php echo $request['first_name'] . ' ' . $request['last_name']; ?><br>
                            <small class="text-muted"><?php echo $request['patient_code']; ?></small>
                        </td>
                        <td><?php echo $request['test_name']; ?></td>
                        <td><?php echo $request['doctor_name']; ?></td>
                        <td class="table-actions">
                            <a href="result-enter.php?request_id=<?php echo $request['id']; ?>" class="btn btn-sm btn-success">
                                <i class="fas fa-check"></i> Complete
                            </a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div style="padding: 40px; text-align: center; color: #999;">
            <i class="fas fa-info-circle" style="font-size: 48px; margin-bottom: 15px;"></i>
            <p>No tests in progress</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
