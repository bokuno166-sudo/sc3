<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin']);

$pageTitle = 'Add Service';
$currentPage = 'services';

$conn = getDBConnection();

// load categories for select
$catsRes = $conn->query('SELECT id, category_name FROM service_categories ORDER BY category_name ASC');
$categories = [];
if ($catsRes) {
    while ($r = $catsRes->fetch_assoc()) $categories[] = $r;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $service_code = sanitize($_POST['service_code'] ?? '');
    $service_name = sanitize($_POST['service_name'] ?? '');
    $category_id = isset($_POST['category_id']) ? intval($_POST['category_id']) : 0;
    if ($category_id <= 0) $category_id = null;
    $price = floatval($_POST['price'] ?? 0);
    $status = sanitize($_POST['status'] ?? 'active');
    $description = sanitize($_POST['description'] ?? '');

    $stmt = $conn->prepare('INSERT INTO services (service_code, service_name, category_id, description, price, status) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('ssisds', $service_code, $service_name, $category_id, $description, $price, $status);
    $executed = $stmt->execute();
    $newId = $stmt->insert_id;
    $stmt->close();

    if ($executed) {
        logActivity('create', 'services', $newId, null, json_encode($_POST));
        setFlashMessage('success', 'Service created successfully.');
        $conn->close();
        redirect('modules/admin/services.php');
    } else {
        setFlashMessage('error', 'Failed to create service: ' . $conn->error);
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Add Service</h1>
        <p class="page-subtitle">Create a new clinical or billing service</p>
    </div>
    <div>
        <a href="services.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <form method="post" action="services-add.php">
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label>Service Code</label>
                    <input type="text" name="service_code" class="form-control" value="" required>
                </div>
                <div class="form-group col-md-8">
                    <label>Service Name</label>
                    <input type="text" name="service_name" class="form-control" value="" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group col-md-4">
                    <label>Category</label>
                    <select name="category_id" class="form-control">
                        <option value="0">-- None --</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['category_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group col-md-4">
                    <label>Price</label>
                    <input type="number" step="0.01" name="price" class="form-control" value="0.00">
                </div>
                <div class="form-group col-md-4">
                    <label>Status</label>
                    <select name="status" class="form-control">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Description</label>
                <textarea name="description" class="form-control"></textarea>
            </div>

            <div class="form-group text-right">
                <button type="submit" class="btn btn-primary">Create Service</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
