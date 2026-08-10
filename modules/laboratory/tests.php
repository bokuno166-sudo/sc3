<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'laboratory']);

$pageTitle = 'Test Catalog';
$currentPage = 'lab-tests';

$conn = getDBConnection();

// Handle delete action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['id'])) {
    $action = $_POST['action'];
    $id = (int)$_POST['id'];
    if ($action === 'delete') {
        // Prevent deleting tests that are referenced by any laboratory_requests (all statuses)
        $chk = $conn->prepare('SELECT COUNT(*) as cnt FROM laboratory_requests WHERE test_id = ?');
        $chk->bind_param('i', $id);
        $chk->execute();
        $cres = $chk->get_result();
        $count = 0;
        if ($cres && $cres->num_rows > 0) {
            $count = (int)$cres->fetch_assoc()['cnt'];
        }
        $chk->close();

        if ($count > 0) {
            setFlashMessage('error', 'Cannot delete test: there are ' . $count . ' laboratory request(s) using this test. Use "Mark Inactive" to disable the test.');
        } else {
            $stmt = $conn->prepare('DELETE FROM laboratory_tests WHERE id = ?');
            $stmt->bind_param('i', $id);
            if ($stmt->execute()) {
                setFlashMessage('success', 'Test removed.');
            } else {
                setFlashMessage('error', 'Failed to remove test: ' . $stmt->error);
            }
            $stmt->close();
        }
    } elseif ($action === 'inactivate') {
        // Mark test as inactive (safe even if there are requests)
        $stmt = $conn->prepare('UPDATE laboratory_tests SET status = ? WHERE id = ?');
        $status = 'inactive';
        $stmt->bind_param('si', $status, $id);
        if ($stmt->execute()) {
            setFlashMessage('success', 'Test marked as inactive.');
        } else {
            setFlashMessage('error', 'Failed to mark test inactive: ' . $stmt->error);
        }
        $stmt->close();
    }
    redirect('modules/laboratory/tests.php');
}

// Filters
$q = isset($_GET['q']) ? sanitize($_GET['q']) : '';
$category = isset($_GET['category']) ? sanitize($_GET['category']) : '';

$where = [];
if ($q) {
    $qEsc = $conn->real_escape_string('%' . $q . '%');
    $where[] = "(test_name LIKE '$qEsc' OR test_code LIKE '$qEsc' OR description LIKE '$qEsc')";
}
if ($category) $where[] = "category = '" . $conn->real_escape_string($category) . "'";

$sql = "SELECT * FROM laboratory_tests";
if (!empty($where)) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY test_name ASC';

$tests = $conn->query($sql);

$categoriesRes = $conn->query("SELECT DISTINCT category FROM laboratory_tests ORDER BY category");
$categories = [];
if ($categoriesRes) {
    while ($c = $categoriesRes->fetch_assoc()) $categories[] = $c['category'];
}

$includeHeader = true;
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Test Catalog</h1>
        <p class="page-subtitle">Laboratory tests and pricing</p>
    </div>
    <?php if (hasRole(['admin'])): ?>
    <div>
        <a href="tests-add.php" class="btn btn-primary"><i class="fas fa-plus"></i> New Test</a>
    </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header">
        <form method="GET" style="display:flex; gap:8px; align-items:center; width:100%;">
            <input type="text" name="q" class="form-control" placeholder="Search code, name or description" value="<?php echo htmlspecialchars($q); ?>">
            <select name="category" class="form-control">
                <option value="">All categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $category===$cat ? 'selected' : ''; ?>><?php echo htmlspecialchars(ucfirst($cat)); ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-primary">Filter</button>
        </form>
    </div>
    <div class="card-body" style="padding:0;">
        <?php if ($tests && $tests->num_rows > 0): ?>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Test Name</th>
                        <th>Category</th>
                        <th>Price</th>
                        <th>Description</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($t = $tests->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($t['test_code']); ?></td>
                        <td><?php echo htmlspecialchars($t['test_name']); ?></td>
                        <td><?php echo htmlspecialchars(ucfirst($t['category'])); ?></td>
                        <td><?php echo formatCurrency($t['price']); ?></td>
                        <td><?php echo htmlspecialchars(strlen($t['description'])>100?substr($t['description'],0,100).'...':$t['description']); ?></td>
                        <td><?php echo $t['status']==='active' ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-secondary">Inactive</span>'; ?></td>
                        <td class="table-actions">
                            <a href="tests-edit.php?id=<?php echo $t['id']; ?>" class="btn btn-sm btn-secondary"><i class="fas fa-edit"></i></a>
                            <?php
                                // Check if this test has linked laboratory requests
                                $reqCount = 0;
                                $rc = $conn->query("SELECT COUNT(*) as cnt FROM laboratory_requests WHERE test_id = " . (int)$t['id']);
                                if ($rc && $rc->num_rows > 0) $reqCount = (int)$rc->fetch_assoc()['cnt'];
                            ?>
                            <?php if ($reqCount > 0): ?>
                                <form method="POST" style="display:inline; margin-left:6px;">
                                    <input type="hidden" name="id" value="<?php echo $t['id']; ?>">
                                    <input type="hidden" name="action" value="inactivate">
                                    <button type="submit" class="btn btn-sm btn-warning" title="Mark inactive (has <?php echo $reqCount; ?> request(s))"><i class="fas fa-ban"></i></button>
                                </form>
                            <?php else: ?>
                                <form method="POST" style="display:inline; margin-left:6px;" onsubmit="return confirm('Delete this test?');">
                                    <input type="hidden" name="id" value="<?php echo $t['id']; ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div style="padding:40px; text-align:center; color:#999;">No tests found.</div>
        <?php endif; ?>
    </div>
</div>

<?php
$conn->close();
include __DIR__ . '/../../includes/footer.php';
?>
