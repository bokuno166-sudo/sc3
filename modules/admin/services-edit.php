<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin']);

$pageTitle = 'Edit Service';
$currentPage = 'services';

$conn = getDBConnection();
$serviceId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($serviceId <= 0) {
    setFlashMessage('error', 'Invalid service selected.');
    redirect('modules/admin/services.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $service_code = sanitize($_POST['service_code'] ?? '');
    $service_name = sanitize($_POST['service_name'] ?? '');
    $category = sanitize($_POST['category'] ?? '');
    $price = floatval($_POST['price'] ?? 0);
    $status = sanitize($_POST['status'] ?? 'active');
    $description = sanitize($_POST['description'] ?? '');

    $stmt = $conn->prepare('UPDATE services SET service_code = ?, service_name = ?, category = ?, price = ?, status = ?, description = ? WHERE id = ?');
    $stmt->bind_param('sssdssi', $service_code, $service_name, $category, $price, $status, $description, $serviceId);
    $executed = $stmt->execute();
    $stmt->close();

    if ($executed) {
        logActivity('update', 'services', $serviceId, null, json_encode($_POST));
        setFlashMessage('success', 'Service updated successfully.');
        $conn->close();
        redirect('modules/admin/services.php');
    } else {
        setFlashMessage('error', 'Failed to update service.');
    }
}

$res = $conn->query("SELECT * FROM services WHERE id = $serviceId");
if (!$res || $res->num_rows === 0) {
    setFlashMessage('error', 'Service not found.');
    $conn->close();
    redirect('modules/admin/services.php');
}
$service = $res->fetch_assoc();
$conn->close();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Edit Service</h1>
        <p class="page-subtitle">Update service details</p>
    </div>
    <div>
        <a href="services.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <form method="post" action="services-edit.php?id=<?php echo $serviceId; ?>">
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label>Service Code</label>
                    <input type="text" name="service_code" class="form-control" value="<?php echo htmlspecialchars($service['service_code'] ?? ''); ?>" required>
                </div>
                <div class="form-group col-md-8">
                    <label>Service Name</label>
                    <input type="text" name="service_name" class="form-control" value="<?php echo htmlspecialchars($service['service_name'] ?? ''); ?>" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group col-md-4">
                    <label>Category</label>
                    <input type="text" name="category" class="form-control" value="<?php echo htmlspecialchars($service['category'] ?? ''); ?>">
                </div>
                <div class="form-group col-md-4">
                    <label>Price</label>
                    <input type="number" step="0.01" name="price" class="form-control" value="<?php echo htmlspecialchars($service['price'] ?? '0.00'); ?>">
                </div>
                <div class="form-group col-md-4">
                    <label>Status</label>
                    <select name="status" class="form-control">
                        <option value="active" <?php echo ($service['status'] ?? '')=='active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo ($service['status'] ?? '')=='inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Description</label>
                <textarea name="description" class="form-control"><?php echo htmlspecialchars($service['description'] ?? ''); ?></textarea>
            </div>

            <div class="form-group text-right">
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
