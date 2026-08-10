<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'laboratory', 'doctor']);

$pageTitle = 'Laboratory Results';
$currentPage = 'lab-results';

$conn = getDBConnection();

// Filters
$status = isset($_GET['status']) ? sanitize($_GET['status']) : '';
$from = isset($_GET['from']) ? sanitize($_GET['from']) : '';
$to = isset($_GET['to']) ? sanitize($_GET['to']) : '';

// Ensure results table exists
$hasResults = false;
$tbl = $conn->query("SHOW TABLES LIKE 'laboratory_results'");
if ($tbl && $tbl->num_rows > 0) $hasResults = true;

if ($hasResults) {
    $where = [];
    if ($status) $where[] = "lr.status = '" . $conn->real_escape_string($status) . "'";
    if ($from) $where[] = "lr.created_at >= '" . $conn->real_escape_string($from) . " 00:00:00'";
    if ($to) $where[] = "lr.created_at <= '" . $conn->real_escape_string($to) . " 23:59:59'";

    $sql = "
        SELECT lr.*, r.request_code, p.first_name, p.last_name, p.patient_code, lt.test_name, u.full_name as technician_name
        FROM laboratory_results lr
        JOIN laboratory_requests r ON lr.request_id = r.id
        JOIN patients p ON lr.patient_id = p.id
        LEFT JOIN laboratory_tests lt ON r.test_id = lt.id
        LEFT JOIN users u ON lr.technician_id = u.id
    ";

    if (!empty($where)) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY lr.created_at DESC';

    $results = $conn->query($sql);
}

$conn->close();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Laboratory Results</h1>
        <p class="page-subtitle">View completed and pending lab results</p>
    </div>
    <div>
        <a href="requests.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Requests</a>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Filter</h3>
        <div style="margin-left:auto;">
            <form method="GET" style="display:flex; gap:8px; align-items:center;">
                <select name="status" class="form-control">
                    <option value="">All statuses</option>
                    <option value="pending-review" <?php echo $status==='pending-review' ? 'selected' : ''; ?>>Pending Review</option>
                    <option value="reviewed" <?php echo $status==='reviewed' ? 'selected' : ''; ?>>Reviewed</option>
                    <option value="finalized" <?php echo $status==='finalized' ? 'selected' : ''; ?>>Finalized</option>
                </select>
                <input type="date" name="from" class="form-control" value="<?php echo htmlspecialchars($from); ?>">
                <input type="date" name="to" class="form-control" value="<?php echo htmlspecialchars($to); ?>">
                <button class="btn btn-primary">Apply</button>
            </form>
        </div>
    </div>
    <div class="card-body" style="padding:0;">
        <?php if (!$hasResults): ?>
            <div style="padding:30px; text-align:center; color:#999;">Laboratory results table not found. Create the table or run DB migrations.</div>
        <?php elseif ($results && $results->num_rows > 0): ?>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Request</th>
                        <th>Patient</th>
                        <th>Test</th>
                        <th>Technician</th>
                        <th>Interpretation</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($r = $results->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo formatDateTime($r['created_at']); ?></td>
                        <td><?php echo htmlspecialchars($r['request_code']); ?></td>
                        <td><?php echo htmlspecialchars($r['first_name'] . ' ' . $r['last_name']); ?> <br><small class="text-muted"><?php echo htmlspecialchars($r['patient_code']); ?></small></td>
                        <td><?php echo htmlspecialchars($r['test_name']); ?></td>
                        <td><?php echo htmlspecialchars($r['technician_name']); ?></td>
                        <td><?php echo htmlspecialchars($r['interpretation']); ?></td>
                        <td><?php echo '<span class="badge badge-'.($r['status']==='finalized'?'success':($r['status']==='reviewed'?'info':'warning')).'">'.ucfirst($r['status']).'</span>'; ?></td>
                        <td class="table-actions">
                            <?php if (hasRole(['doctor'])): ?>
                                <a href="result-view.php?request_id=<?php echo $r['request_id']; ?>" class="btn btn-sm btn-info"><i class="fas fa-eye"></i></a>
                            <?php else: ?>
                                <a href="result-enter.php?request_id=<?php echo $r['request_id']; ?>" class="btn btn-sm btn-info"><i class="fas fa-eye"></i></a>
                            <?php endif; ?>
                            <?php if (!empty($r['attachment_path'])): ?>
                                <a href="<?php echo BASE_URL . ltrim($r['attachment_path'], '/'); ?>" class="btn btn-sm btn-secondary" target="_blank"><i class="fas fa-download"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <div style="padding:30px; text-align:center; color:#999;">No lab results found.</div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
