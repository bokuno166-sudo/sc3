<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin']);

$pageTitle = 'Add Test';
$currentPage = 'lab-tests-add';

$conn = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $test_code = sanitize($_POST['test_code'] ?? '');
    $test_name = sanitize($_POST['test_name'] ?? '');
    $category = sanitize($_POST['category'] ?? 'other');
    $description = sanitize($_POST['description'] ?? '');
    $price = isset($_POST['price']) ? (float)$_POST['price'] : 0.0;
    $status = isset($_POST['status']) && $_POST['status'] === 'active' ? 'active' : 'inactive';

    if (empty($test_code) || empty($test_name)) {
        setFlashMessage('error', 'Code and name are required.');
        redirect('modules/laboratory/tests-add.php');
    }

    $stmt = $conn->prepare('INSERT INTO laboratory_tests (test_code, test_name, category, description, price, status) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('sssdds', $test_code, $test_name, $category, $description, $price, $status);
    if ($stmt->execute()) {
        $newId = $stmt->insert_id;
        logActivity('create', 'laboratory_tests', $newId);
        setFlashMessage('success', 'Test added successfully.');
        $stmt->close();
        $conn->close();
        redirect('modules/laboratory/tests.php');
    } else {
        setFlashMessage('error', 'Failed to add test: ' . $stmt->error);
        $stmt->close();
        $conn->close();
        redirect('modules/laboratory/tests-add.php');
    }
}

$conn->close();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Add Test</h1>
        <p class="page-subtitle">Create a new laboratory test</p>
    </div>
    <div>
        <a href="tests.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <form method="post" action="tests-add.php">
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label>Test Code</label>
                    <input type="text" name="test_code" class="form-control" required value="<?php echo htmlspecialchars($_POST['test_code'] ?? ''); ?>">
                </div>
                <div class="form-group col-md-8">
                    <label>Test Name</label>
                    <input type="text" name="test_name" class="form-control" required value="<?php echo htmlspecialchars($_POST['test_name'] ?? ''); ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group col-md-4">
                    <label>Category</label>
                    <input type="text" name="category" class="form-control" value="<?php echo htmlspecialchars($_POST['category'] ?? 'other'); ?>">
                </div>
                <div class="form-group col-md-4">
                    <label>Price</label>
                    <input type="number" step="0.01" name="price" class="form-control" value="<?php echo htmlspecialchars($_POST['price'] ?? '0.00'); ?>">
                </div>
                <div class="form-group col-md-4">
                    <label>Status</label>
                    <select name="status" class="form-control">
                        <option value="active" <?php echo (($_POST['status'] ?? '') === 'active') ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo (($_POST['status'] ?? '') === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Description</label>
                <textarea name="description" class="form-control" rows="5"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
            </div>

            <div class="form-group text-right">
                <button type="submit" class="btn btn-primary">Create Test</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php';
