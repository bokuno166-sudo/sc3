<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'inventory']);

$pageTitle = 'Edit Item';
$currentPage = 'inventory';

$conn = getDBConnection();
$itemId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($itemId <= 0) {
    setFlashMessage('error', 'Invalid item selected.');
    redirect('modules/inventory/items.php');
}

// handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $item_code = sanitize($_POST['item_code'] ?? '');
    $item_name = sanitize($_POST['item_name'] ?? '');
    $category_id = isset($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $reorder_level = isset($_POST['reorder_level']) ? (int)$_POST['reorder_level'] : 0;
    $unit_cost = isset($_POST['unit_cost']) ? floatval($_POST['unit_cost']) : 0.0;
    $status = sanitize($_POST['status'] ?? 'active');
    $item_type = sanitize($_POST['item_type'] ?? 'Medicine');
    $description = sanitize($_POST['description'] ?? '');

    // ensure item_type column exists
    $col = $conn->query("SHOW COLUMNS FROM inventory_items LIKE 'item_type'");
    if (!$col || $col->num_rows === 0) {
        $conn->query("ALTER TABLE inventory_items ADD COLUMN item_type VARCHAR(32) DEFAULT 'Medicine'");
    }

    $stmt = $conn->prepare('UPDATE inventory_items SET item_code = ?, item_name = ?, category_id = ?, reorder_level = ?, unit_cost = ?, status = ?, description = ?, item_type = ? WHERE id = ?');
    $stmt->bind_param('ssiidsssi', $item_code, $item_name, $category_id, $reorder_level, $unit_cost, $status, $description, $item_type, $itemId);
    $executed = $stmt->execute();
    $stmt->close();

    if ($executed) {
        logActivity('update', 'inventory_items', $itemId, null, json_encode($_POST));
        setFlashMessage('success', 'Item updated successfully.');
        $conn->close();
        redirect('modules/inventory/items.php');
    } else {
        setFlashMessage('error', 'Failed to update item.');
    }
}

// load item
$res = $conn->query("SELECT * FROM inventory_items WHERE id = $itemId");
if (!$res || $res->num_rows === 0) {
    setFlashMessage('error', 'Item not found.');
    $conn->close();
    redirect('modules/inventory/items.php');
}
$item = $res->fetch_assoc();

// categories for dropdown
$categories = [];
$catRes = $conn->query("SELECT * FROM inventory_categories ORDER BY category_name");
if ($catRes) {
    while ($c = $catRes->fetch_assoc()) $categories[] = $c;
}

$conn->close();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Edit Item</h1>
        <p class="page-subtitle">Update inventory item details</p>
    </div>
    <div>
        <a href="items.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <form method="post" action="item-edit.php?id=<?php echo $itemId; ?>">
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label>Item Code</label>
                    <input type="text" name="item_code" class="form-control" value="<?php echo htmlspecialchars($item['item_code'] ?? ''); ?>" required>
                </div>
                <div class="form-group col-md-8">
                    <label>Item Name</label>
                    <input type="text" name="item_name" class="form-control" value="<?php echo htmlspecialchars($item['item_name'] ?? ''); ?>" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group col-md-4">
                    <label>Category</label>
                    <select name="category_id" class="form-control">
                        <option value="">Uncategorized</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo ($item['category_id'] ?? '') == $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['category_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group col-md-4">
                    <label>Type</label>
                    <select name="item_type" class="form-control">
                        <option value="Medicine" <?php echo ($item['item_type'] ?? 'Medicine')=='Medicine' ? 'selected' : ''; ?>>Medicine</option>
                        <option value="Supply" <?php echo ($item['item_type'] ?? '')=='Supply' ? 'selected' : ''; ?>>Supply</option>
                    </select>
                </div>
                <div class="form-group col-md-4">
                    <label>Reorder Level</label>
                    <input type="number" name="reorder_level" class="form-control" value="<?php echo htmlspecialchars($item['reorder_level'] ?? '0'); ?>">
                </div>
                <div class="form-group col-md-4">
                    <label>Unit Cost</label>
                    <input type="number" step="0.01" name="unit_cost" class="form-control" value="<?php echo htmlspecialchars($item['unit_cost'] ?? '0.00'); ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group col-md-4">
                    <label>Status</label>
                    <select name="status" class="form-control">
                        <option value="active" <?php echo ($item['status'] ?? '')=='active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo ($item['status'] ?? '')=='inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Description</label>
                <textarea name="description" class="form-control"><?php echo htmlspecialchars($item['description'] ?? ''); ?></textarea>
            </div>

            <div class="form-group text-right">
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
