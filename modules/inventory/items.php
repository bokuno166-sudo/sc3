<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'inventory']);

$pageTitle = 'Inventory Items';
$currentPage = 'inventory';

$conn = getDBConnection();

// Get inventory items with stock
$items = $conn->query("
    SELECT i.*, c.category_name, s.quantity_in_stock, s.quantity_reserved
    FROM inventory_items i
    LEFT JOIN inventory_categories c ON i.category_id = c.id
    LEFT JOIN inventory_stock s ON i.id = s.item_id
    WHERE i.status = 'active'
    ORDER BY i.item_name ASC
");

// Get categories for filter
$categories = $conn->query("SELECT * FROM inventory_categories ORDER BY category_name");

// Aggregate totals by item_type
$medRes = $conn->query("SELECT COALESCE(SUM(s.quantity_in_stock - s.quantity_reserved),0) AS total FROM inventory_stock s JOIN inventory_items i ON s.item_id = i.id WHERE i.status='active' AND (i.item_type='Medicine' OR i.item_type='medicine')");
$medTotal = $medRes && $medRes->num_rows ? intval($medRes->fetch_assoc()['total']) : 0;
$supRes = $conn->query("SELECT COALESCE(SUM(s.quantity_in_stock - s.quantity_reserved),0) AS total FROM inventory_stock s JOIN inventory_items i ON s.item_id = i.id WHERE i.status='active' AND (i.item_type='Supply' OR i.item_type='supply')");
$supTotal = $supRes && $supRes->num_rows ? intval($supRes->fetch_assoc()['total']) : 0;

$conn->close();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Inventory Items</h1>
        <p class="page-subtitle">Manage medical supplies and medications</p>
        <div style="margin-top:8px;">
            <span class="badge badge-primary">Medicine Stock: <?php echo $medTotal; ?></span>
            <span class="badge badge-info" style="margin-left:8px;">Supply Stock: <?php echo $supTotal; ?></span>
        </div>
    </div>
    <a href="item-add.php" class="btn btn-primary">
        <i class="fas fa-plus"></i> Add Item
    </a>
</div>

<!-- Inventory Table -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">All Items</h3>
        <span class="badge badge-secondary"><?php echo $items->num_rows; ?> Items</span>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Item Code</th>
                        <th>Item Name</th>
                        <th>Type</th>
                        <th>Category</th>
                        <th>Stock</th>
                        <th>Reorder Level</th>
                        <th>Unit Cost</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($item = $items->fetch_assoc()): 
                        $stock = $item['quantity_in_stock'] ?: 0;
                        $reorderLevel = $item['reorder_level'];
                        $isLowStock = $stock <= $reorderLevel;
                    ?>
                    <tr>
                        <td><strong><?php echo $item['item_code']; ?></strong></td>
                        <td><?php echo $item['item_name']; ?></td>
                        <td><?php echo htmlspecialchars($item['item_type'] ?? 'Medicine'); ?></td>
                        <td><?php echo $item['category_name'] ?: 'Uncategorized'; ?></td>
                        <td>
                            <?php if ($isLowStock): ?>
                            <span class="badge badge-danger"><?php echo $stock; ?> (Low)</span>
                            <?php else: ?>
                            <span class="badge badge-success"><?php echo $stock; ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $reorderLevel; ?></td>
                        <td><?php echo formatCurrency($item['unit_cost']); ?></td>
                        <td><?php echo getStatusBadge($item['status']); ?></td>
                        <td class="table-actions">
                            <a href="item-view.php?id=<?php echo $item['id']; ?>" class="btn btn-sm btn-info" title="View">
                                <i class="fas fa-eye"></i>
                            </a>
                            <a href="stock-add.php?item_id=<?php echo $item['id']; ?>" class="btn btn-sm btn-success" title="Add Stock">
                                <i class="fas fa-plus"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
