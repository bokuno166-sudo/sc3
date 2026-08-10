<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'laboratory']);

$pageTitle = 'Edit Test';
$currentPage = 'lab-tests';

$conn = getDBConnection();

$id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);
if ($id <= 0) {
    setFlashMessage('error', 'Invalid test id.');
    redirect('modules/laboratory/tests.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $test_code = sanitize($_POST['test_code'] ?? '');
    $test_name = sanitize($_POST['test_name'] ?? '');
    $category = sanitize($_POST['category'] ?? 'other');
    $description = sanitize($_POST['description'] ?? '');
    $price = isset($_POST['price']) ? (float)$_POST['price'] : 0.0;
    $status = isset($_POST['status']) && $_POST['status'] === 'active' ? 'active' : 'inactive';

    if (empty($test_code) || empty($test_name)) {
        setFlashMessage('error', 'Code and name are required.');
        redirect('modules/laboratory/tests-edit.php?id=' . $id);
    }

    $stmt = $conn->prepare('UPDATE laboratory_tests SET test_code = ?, test_name = ?, category = ?, description = ?, price = ?, status = ? WHERE id = ?');
    $stmt->bind_param('sssdssi', $test_code, $test_name, $category, $description, $price, $status, $id);
    if ($stmt->execute()) {
        logActivity('update', 'laboratory_tests', $id);
        setFlashMessage('success', 'Test updated successfully.');
        $stmt->close();
        redirect('modules/laboratory/tests.php');
    } else {
        setFlashMessage('error', 'Failed to update test: ' . $stmt->error);
        $stmt->close();
        redirect('modules/laboratory/tests-edit.php?id=' . $id);
    }
}

// Load test
$stmt = $conn->prepare('SELECT * FROM laboratory_tests WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$res = $stmt->get_result();
if (!$res || $res->num_rows === 0) {
    setFlashMessage('error', 'Test not found.');
    $stmt->close();
    $conn->close();
    redirect('modules/laboratory/tests.php');
}
$test = $res->fetch_assoc();
$stmt->close();
$conn->close();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Edit Test</h1>
        <p class="page-subtitle">Modify laboratory test details</p>
    </div>
    <div>
        <a href="tests.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <form method="post" action="tests-edit.php?id=<?php echo $test['id']; ?>">
            <input type="hidden" name="id" value="<?php echo $test['id']; ?>">

            <div class="form-row">
                <div class="form-group col-md-4">
                    <label>Test Code</label>
                    <input type="text" name="test_code" class="form-control" required value="<?php echo htmlspecialchars($test['test_code'] ?? ''); ?>">
                </div>
                <div class="form-group col-md-8">
                    <label>Test Name</label>
                    <input type="text" name="test_name" class="form-control" required value="<?php echo htmlspecialchars($test['test_name'] ?? ''); ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group col-md-4">
                    <label>Category</label>
                    <input type="text" name="category" class="form-control" value="<?php echo htmlspecialchars($test['category'] ?? 'other'); ?>">
                </div>
                <div class="form-group col-md-4">
                    <label>Price</label>
                    <input type="number" step="0.01" name="price" class="form-control" value="<?php echo htmlspecialchars($test['price'] ?? '0.00'); ?>">
                </div>
                <div class="form-group col-md-4">
                    <label>Status</label>
                    <select name="status" class="form-control">
                        <option value="active" <?php echo ($test['status'] ?? '') === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo ($test['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Description</label>
                <textarea name="description" class="form-control" rows="5"><?php echo htmlspecialchars($test['description'] ?? ''); ?></textarea>
            </div>

            <div class="form-group text-right">
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php';
