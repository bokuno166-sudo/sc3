<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'inventory']);

$pageTitle = 'Add Stock';
$currentPage = 'stock';

$conn = getDBConnection();
$itemId = isset($_GET['item_id']) ? (int)$_GET['item_id'] : 0;
$batchParam = isset($_GET['batch']) ? $_GET['batch'] : null;

// Load item if provided
$item = null;
if ($itemId > 0) {
    $r = $conn->query("SELECT * FROM inventory_items WHERE id = $itemId LIMIT 1");
    if ($r && $r->num_rows > 0) $item = $r->fetch_assoc();
}

// Handle POST - add or adjust stock batch
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $item_id = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
    $batch_number = sanitize($_POST['batch_number'] ?? '');
    $expiry_date = sanitize($_POST['expiry_date'] ?? '');
    $quantity = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 0;
    $location = sanitize($_POST['location'] ?? '');
    $notes = sanitize($_POST['notes'] ?? '');
        $stock_type = sanitize($_POST['stock_type'] ?? 'Medicine');

    if ($item_id <= 0 || $quantity <= 0) {
        setFlashMessage('error', 'Invalid item or quantity.');
        redirect('modules/inventory/stock-add.php' . ($item_id ? '?item_id='.$item_id : ''));
    }

    // Check item type matches stock_type to avoid mixing Supplies and Medicines
    $itRes = $conn->query("SELECT item_type FROM inventory_items WHERE id = " . intval($item_id) . " LIMIT 1");
    if (!$itRes || $itRes->num_rows === 0) {
        setFlashMessage('error', 'Selected item not found.');
        $conn->close();
        redirect('modules/inventory/stock.php');
    }
    $itRow = $itRes->fetch_assoc();
    $item_type = $itRow['item_type'] ?? 'Medicine';
    if (strtolower($item_type) !== strtolower($stock_type)) {
        setFlashMessage('error', 'Stock type mismatch: selected item is "' . htmlspecialchars($item_type) . '" but you chose "' . htmlspecialchars($stock_type) . '".');
        $conn->close();
        redirect('modules/inventory/stock-add.php' . ($item_id ? '?item_id='.$item_id : ''));
    }

    // Ensure inventory_stock table exists
    $tbl = $conn->query("SHOW TABLES LIKE 'inventory_stock'");
    if (!$tbl || $tbl->num_rows === 0) {
        setFlashMessage('error', 'Stock table not found.');
        $conn->close();
        redirect('modules/inventory/stock.php');
    }

    // If batch_number provided, try update existing batch
    if ($batch_number !== '') {
        $stmt = $conn->prepare('SELECT id, quantity_in_stock FROM inventory_stock WHERE item_id = ? AND batch_number = ? LIMIT 1');
        $stmt->bind_param('is', $item_id, $batch_number);
        $stmt->execute();
        $res = $stmt->get_result();
        $existing = $res->fetch_assoc();
        $stmt->close();

        if ($existing) {
            // update quantity
            $newQty = (int)$existing['quantity_in_stock'] + $quantity;
            $stmt = $conn->prepare('UPDATE inventory_stock SET quantity_in_stock = ?, expiry_date = ?, location = ?, last_movement_date = NOW() WHERE id = ?');
            $stmt->bind_param('issi', $newQty, $expiry_date, $location, $existing['id']);
            $ok = $stmt->execute();
            $stmt->close();
        } else {
            // insert new batch (inventory_stock does not include a 'notes' column)
            $stmt = $conn->prepare('INSERT INTO inventory_stock (item_id, batch_number, expiry_date, quantity_in_stock, location, last_movement_date) VALUES (?, ?, ?, ?, ?, NOW())');
            $stmt->bind_param('issis', $item_id, $batch_number, $expiry_date, $quantity, $location);
            $ok = $stmt->execute();
            $stmt->close();
        }
    } else {
        // no batch provided -> create unnamed batch record (no 'notes' column)
        $stmt = $conn->prepare('INSERT INTO inventory_stock (item_id, batch_number, expiry_date, quantity_in_stock, location, last_movement_date) VALUES (?, ?, ?, ?, ?, NOW())');
        $empty = '';
        $stmt->bind_param('issis', $item_id, $empty, $expiry_date, $quantity, $location);
        $ok = $stmt->execute();
        $stmt->close();
    }

    if (isset($ok) && $ok) {
        logActivity('add_stock', 'inventory_stock', null, null, json_encode(['item_id'=>$item_id,'batch'=>$batch_number,'quantity'=>$quantity]));
        setFlashMessage('success', 'Stock updated successfully.');
        $conn->close();
        redirect('modules/inventory/stock.php');
    } else {
        setFlashMessage('error', 'Failed to update stock.');
    }
}

// Render form
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Add / Adjust Stock</h1>
        <p class="page-subtitle"><?php echo $item ? htmlspecialchars($item['item_code'].' — '.$item['item_name'].' ['.($item['item_type'] ?? 'Medicine').']') : 'Select item to add stock'; ?></p>
    </div>
    <div>
        <a href="stock.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
</div>

<div class="card">
    <div class="card-body">
            <form method="post" action="stock-add.php">
                <input type="hidden" name="item_id" id="item_id_field" value="<?php echo $itemId; ?>">

                <?php if ($itemId <= 0): ?>
                <div class="form-row">
                    <div class="form-group col-md-12">
                        <label>Select Item</label>
                        <select id="item_select" name="item_id" class="form-control" required>
                            <option value="">-- Select item --</option>
                            <?php
                            $itRes = $conn->query("SELECT id, item_code, item_name, item_type FROM inventory_items WHERE status = 'active' ORDER BY item_type, item_name");
                            if ($itRes) {
                                while ($it = $itRes->fetch_assoc()) {
                                    echo '<option value="' . intval($it['id']) . '" data-type="' . htmlspecialchars($it['item_type'] ?? 'Medicine') . '">' . htmlspecialchars($it['item_code'] . ' — ' . $it['item_name'] . ' [' . ($it['item_type'] ?? 'Medicine') . ']') . '</option>';
                                }
                            }
                            ?>
                        </select>
                    </div>
                </div>
                <?php endif; ?>

            <div class="form-row">
                <div class="form-group col-md-6">
                    <label>Batch Number (optional)</label>
                    <input type="text" name="batch_number" class="form-control" value="<?php echo htmlspecialchars($batchParam ?? ''); ?>">
                </div>
                <div class="form-group col-md-6">
                    <label>Expiry Date (optional)</label>
                    <input type="date" name="expiry_date" class="form-control" value="">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group col-md-4">
                    <label>Quantity</label>
                    <input type="number" name="quantity" class="form-control" required>
                </div>
                <div class="form-group col-md-4">
                    <label>Location</label>
                    <input type="text" name="location" class="form-control">
                </div>
                <div class="form-group col-md-4">
                    <label>Notes</label>
                    <input type="text" name="notes" class="form-control">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group col-md-4">
                    <label>Stock Type</label>
                    <select id="stock_type" name="stock_type" class="form-control">
                        <option value="Medicine">Medicine</option>
                        <option value="Supply">Supply</option>
                    </select>
                </div>
            </div>

            <div class="form-group text-right">
                <button type="submit" class="btn btn-primary">Apply</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
<script>
// Show selected item's type in subtitle and sync hidden field
document.addEventListener('DOMContentLoaded', function(){
    var select = document.getElementById('item_select');
    var itemField = document.getElementById('item_id_field');
    var subtitle = document.querySelector('.page-subtitle');
    var typeSelect = document.getElementById('stock_type');
    function upd(){
        if (!select) return;
        var opt = select.options[select.selectedIndex];
        if (!opt || !opt.value) return;
        var t = opt.getAttribute('data-type') || 'Medicine';
        if (subtitle) subtitle.textContent = opt.text;
        if (itemField) itemField.value = opt.value;
        if (typeSelect) typeSelect.value = t;
    }
    if (select) select.addEventListener('change', upd);
    upd();
});
</script>
