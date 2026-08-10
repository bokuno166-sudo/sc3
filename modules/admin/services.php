<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin']);

$pageTitle = 'Services';
$currentPage = 'services';

$conn = getDBConnection();

// Handle delete action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['id'])) {
    $action = $_POST['action'];
    $id = (int)$_POST['id'];
    if ($action === 'delete') {
        $stmt = $conn->prepare('DELETE FROM services WHERE id = ?');
        $stmt->bind_param('i', $id);
        if ($stmt->execute()) {
            logActivity('delete', 'services', $id);
            setFlashMessage('success', 'Service removed.');
        } else {
            setFlashMessage('error', 'Failed to remove service: ' . $stmt->error);
        }
        $stmt->close();
    }
    redirect('modules/admin/services.php');
}

// Check table exists
$hasTable = false;
$tbl = $conn->query("SHOW TABLES LIKE 'services'");
if ($tbl && $tbl->num_rows > 0) $hasTable = true;

if ($hasTable) {
    $services = $conn->query("SELECT * FROM services ORDER BY service_name ASC");
}

$conn->close();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Services</h1>
        <p class="page-subtitle">Manage clinical and billing services</p>
    </div>
    <div>
        <a href="services-add.php" class="btn btn-primary"><i class="fas fa-plus"></i> New Service</a>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Service Catalog</h3>
        <span class="badge badge-secondary"><?php echo $hasTable && $services ? $services->num_rows : 0; ?> Services</span>
    </div>
    <div class="card-body" style="padding:0;">
        <?php if (!$hasTable): ?>
            <div style="padding:30px; text-align:center; color:#999;">Services table not found. Run DB migrations.</div>
        <?php elseif ($services && $services->num_rows > 0): ?>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Service Name</th>
                        <th>Category</th>
                        <th>Price</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($s = $services->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($s['service_code']); ?></td>
                        <td><?php echo htmlspecialchars($s['service_name']); ?></td>
                        <td><?php echo htmlspecialchars(ucfirst($s['category'] ?? '')); ?></td>
                        <td><?php echo formatCurrency($s['price']); ?></td>
                        <td><?php echo getStatusBadge($s['status'] ?? 'active'); ?></td>
                        <td class="table-actions">
                            <a href="services-edit.php?id=<?php echo $s['id']; ?>" class="btn btn-sm btn-secondary"><i class="fas fa-edit"></i></a>
                            <form method="POST" style="display:inline; margin-left:6px;" onsubmit="return confirm('Delete this service?');">
                                <input type="hidden" name="id" value="<?php echo $s['id']; ?>">
                                <input type="hidden" name="action" value="delete">
                                <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <div style="padding:30px; text-align:center; color:#999;">No services found.</div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
