<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'nurse']);

$pageTitle = 'Visits';
$currentPage = 'reception';

$conn = getDBConnection();

// Filters
$q = isset($_GET['q']) ? sanitize($_GET['q']) : '';
$from = isset($_GET['from']) ? sanitize($_GET['from']) : '';
$to = isset($_GET['to']) ? sanitize($_GET['to']) : '';
$status = isset($_GET['status']) ? sanitize($_GET['status']) : '';

$where = [];
if ($q) {
    $qEsc = $conn->real_escape_string('%' . $q . '%');
    $where[] = "(p.first_name LIKE '$qEsc' OR p.last_name LIKE '$qEsc' OR p.patient_code LIKE '$qEsc')";
}
if ($from) $where[] = "v.visit_date >= '" . $conn->real_escape_string($from) . " 00:00:00'";
if ($to) $where[] = "v.visit_date <= '" . $conn->real_escape_string($to) . " 23:59:59'";
if ($status) $where[] = "v.status = '" . $conn->real_escape_string($status) . "'";

$sql = "
    SELECT v.*, p.first_name, p.last_name, p.patient_code
    FROM patient_visits v
    JOIN patients p ON v.patient_id = p.id
";
if (!empty($where)) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY v.visit_date DESC';

$visitsResult = $conn->query($sql);

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Visits</h1>
        <p class="page-subtitle">Patient visits and queue</p>
    </div>
    <div>
        <a href="patient-add.php" class="btn btn-primary"><i class="fas fa-user-plus"></i> New Visit</a>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <form method="GET" style="display:flex; gap:8px; align-items:center; width:100%;">
            <input type="text" name="q" class="form-control" placeholder="Search patient name or code" value="<?php echo htmlspecialchars($q); ?>">
            <input type="date" name="from" class="form-control" value="<?php echo htmlspecialchars($from); ?>">
            <input type="date" name="to" class="form-control" value="<?php echo htmlspecialchars($to); ?>">
            <select name="status" class="form-control">
                <option value="">All statuses</option>
                <option value="waiting" <?php echo $status==='waiting' ? 'selected' : ''; ?>>Waiting</option>
                <option value="in_consultation" <?php echo $status==='in_consultation' ? 'selected' : ''; ?>>In Consultation</option>
                <option value="completed" <?php echo $status==='completed' ? 'selected' : ''; ?>>Completed</option>
                <option value="cancelled" <?php echo $status==='cancelled' ? 'selected' : ''; ?>>Cancelled</option>
            </select>
            <button class="btn btn-primary">Filter</button>
        </form>
    </div>
    <div class="card-body" style="padding:0;">
        <?php if ($visitsResult && $visitsResult->num_rows > 0): ?>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Visit ID</th>
                        <th>Patient</th>
                        <th>Visit Date</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($v = $visitsResult->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo $v['id']; ?></td>
                        <td>
                            <?php echo htmlspecialchars($v['first_name'] . ' ' . $v['last_name']); ?>
                            <br><small class="text-muted"><?php echo htmlspecialchars($v['patient_code']); ?></small>
                        </td>
                        <td><?php echo formatDateTime($v['visit_date']); ?></td>
                        <td><?php echo htmlspecialchars($v['visit_type']); ?></td>
                        <td><?php echo ucwords(str_replace('_',' ',$v['status'])); ?></td>
                        <td class="table-actions">
                            <a href="patient-view.php?id=<?php echo $v['patient_id']; ?>" class="btn btn-sm btn-info"><i class="fas fa-user"></i></a>
                            <a href="visit-view.php?id=<?php echo $v['id']; ?>" class="btn btn-sm btn-primary"><i class="fas fa-eye"></i></a>
                            <a href="visit-edit.php?id=<?php echo $v['id']; ?>" class="btn btn-sm btn-secondary"><i class="fas fa-edit"></i></a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div style="padding:30px; text-align:center; color:#999;">No visits found.</div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
