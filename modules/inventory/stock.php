<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'inventory']);

$pageTitle = 'Stock';
$currentPage = 'stock';

$conn = getDBConnection();

// Ensure inventory_items has item_type column to avoid query errors
$col = $conn->query("SHOW COLUMNS FROM inventory_items LIKE 'item_type'");
if (!$col || $col->num_rows === 0) {
    $conn->query("ALTER TABLE inventory_items ADD COLUMN item_type VARCHAR(32) DEFAULT 'Medicine'");
}

// Filters
$q = isset($_GET['q']) ? sanitize($_GET['q']) : '';
$lowOnly = isset($_GET['low']) ? true : false;
$expiryFilter = isset($_GET['expiry']) ? sanitize($_GET['expiry']) : ''; // 'near' or 'expired'
$typeFilter = isset($_GET['type']) ? sanitize($_GET['type']) : 'All'; // All, Medicine, Supply

// Summary per item (total in stock)
$summarySql = "
    SELECT i.id, i.item_code, i.item_name, i.reorder_level, i.item_type, COALESCE(SUM(s.quantity_in_stock - s.quantity_reserved),0) as total_stock
    FROM inventory_items i
    LEFT JOIN inventory_stock s ON i.id = s.item_id
    WHERE i.status = 'active'
";
if ($q) {
    $qEsc = $conn->real_escape_string('%' . $q . '%');
    $summarySql .= " AND (i.item_name LIKE '$qEsc' OR i.item_code LIKE '$qEsc')";
}
$expiryCond = '';
if ($expiryFilter === 'near') {
    $expiryCond = " AND s.expiry_date IS NOT NULL AND s.expiry_date <> '0000-00-00' AND s.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
} elseif ($expiryFilter === 'expired') {
    $expiryCond = " AND s.expiry_date IS NOT NULL AND s.expiry_date <> '0000-00-00' AND s.expiry_date < CURDATE()";
}
$summarySql .= $expiryCond . " GROUP BY i.id ORDER BY i.item_name ASC";

$summaryRes = $conn->query($summarySql);

// Detailed stock batches
$batchSql = "
    SELECT s.*, i.item_code, i.item_name, i.item_type
    FROM inventory_stock s
    JOIN inventory_items i ON s.item_id = i.id
    WHERE i.status = 'active'
";
if ($q) {
    $qEsc = $conn->real_escape_string('%' . $q . '%');
    $batchSql .= " AND (i.item_name LIKE '$qEsc' OR i.item_code LIKE '$qEsc')";
}
$batchSql .= $expiryCond . " ORDER BY i.item_name ASC, s.expiry_date ASC";

// apply type filter if requested
if ($typeFilter !== 'All') {
    $t = $conn->real_escape_string($typeFilter);
    $summarySql = str_replace('WHERE i.status = \'active\'', "WHERE i.status = 'active' AND (i.item_type = '$t' OR i.item_type = LOWER('$t'))", $summarySql);
    $batchSql = str_replace('WHERE i.status = \'active\'', "WHERE i.status = 'active' AND (i.item_type = '$t' OR i.item_type = LOWER('$t'))", $batchSql);
}

$batchRes = $conn->query($batchSql);

$conn->close();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Stock</h1>
        <p class="page-subtitle">Current stock levels and batches</p>
    </div>
    <div>
        <a href="items.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Items</a>
        <a href="stock-add.php" class="btn btn-primary"><i class="fas fa-plus"></i> Add Stock</a>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <form method="GET" style="display:flex; gap:8px; align-items:center; width:100%;">
            <input type="text" name="q" class="form-control" placeholder="Search item code or name" value="<?php echo htmlspecialchars($q); ?>">
            <label style="display:flex; align-items:center; gap:6px;"><input type="checkbox" name="low" <?php echo $lowOnly ? 'checked' : ''; ?>> Low stock only</label>
            <label style="display:flex; align-items:center; gap:6px;">
                Type:
                <select name="type" class="form-control" style="width:150px; margin-left:6px;">
                    <option value="All" <?php echo $typeFilter=='All' ? 'selected' : ''; ?>>All</option>
                    <option value="Medicine" <?php echo $typeFilter=='Medicine' ? 'selected' : ''; ?>>Medicine</option>
                    <option value="Supply" <?php echo $typeFilter=='Supply' ? 'selected' : ''; ?>>Supply</option>
                </select>
            </label>
            <button class="btn btn-primary">Apply</button>
        </form>
    </div>
    <div class="card-body">
        <h4>Summary</h4>
        <?php if ($summaryRes && $summaryRes->num_rows > 0): ?>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Type</th>
                        <th>In Stock</th>
                        <th>Reorder Level</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $summaryRes->fetch_assoc()):
                        $isLow = $row['total_stock'] <= $row['reorder_level'];
                        if ($lowOnly && !$isLow) continue;
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($row['item_code']); ?></strong> <?php echo htmlspecialchars($row['item_name']); ?></td>
                        <td><?php echo htmlspecialchars($row['item_type'] ?? 'Medicine'); ?></td>
                        <td><?php echo $isLow ? '<span class="badge badge-danger">'.$row['total_stock'].' (Low)</span>' : '<span class="badge badge-success">'.$row['total_stock'].'</span>'; ?></td>
                        <td><?php echo (int)$row['reorder_level']; ?></td>
                        <td><?php echo $isLow ? '<span class="badge badge-warning">Reorder</span>' : '<span class="badge badge-success">OK</span>'; ?></td>
                        <td class="table-actions">
                            <a href="item-view.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-info"><i class="fas fa-eye"></i></a>
                            <a href="stock-add.php?item_id=<?php echo $row['id']; ?>" class="btn btn-sm btn-success"><i class="fas fa-plus"></i></a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <div style="padding:20px; text-align:center; color:#999;">No items found.</div>
        <?php endif; ?>

        <hr>
        <h4>Batches</h4>
        <?php if ($batchRes && $batchRes->num_rows > 0): ?>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Batch</th>
                        <th>Expiry</th>
                        <th>Quantity</th>
                        <th>Location</th>
                        <th>Last Movement</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($b = $batchRes->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($b['item_code'].' — '.$b['item_name']); ?></td>
                        <td><?php echo htmlspecialchars($b['batch_number'] ?: '—'); ?></td>
                        <td><?php echo $b['expiry_date'] ? date('M d, Y', strtotime($b['expiry_date'])) : '—'; ?></td>
                        <td><?php echo (int)$b['quantity_in_stock']; ?></td>
                        <td><?php echo htmlspecialchars($b['location'] ?: '—'); ?></td>
                        <td><?php echo $b['last_movement_date'] ? date('M d, Y', strtotime($b['last_movement_date'])) : '—'; ?></td>
                        <td class="table-actions">
                            <a href="stock-add.php?item_id=<?php echo $b['item_id']; ?>&batch=<?php echo urlencode($b['batch_number']); ?>" class="btn btn-sm btn-success" title="Adjust"><i class="fas fa-edit"></i></a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <div style="padding:20px; text-align:center; color:#999;">No stock batches found.</div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
