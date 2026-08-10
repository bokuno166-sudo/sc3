<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'inventory']);

$pageTitle = 'View Item';
$currentPage = 'inventory';

$conn = getDBConnection();
$itemId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($itemId <= 0) {
    setFlashMessage('error', 'Invalid item selected.');
    redirect('modules/inventory/items.php');
}

$res = $conn->query("SELECT i.*, c.category_name, s.quantity_in_stock, s.quantity_reserved FROM inventory_items i LEFT JOIN inventory_categories c ON i.category_id = c.id LEFT JOIN inventory_stock s ON i.id = s.item_id WHERE i.id = $itemId LIMIT 1");
if (!$res || $res->num_rows === 0) {
    setFlashMessage('error', 'Item not found.');
    $conn->close();
    redirect('modules/inventory/items.php');
}
$item = $res->fetch_assoc();

$conn->close();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Item Details</h1>
        <p class="page-subtitle"><?php echo htmlspecialchars($item['item_code'] ?? ''); ?> — <?php echo htmlspecialchars($item['item_name'] ?? ''); ?></p>
    </div>
    <div>
        <a href="items.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
        <a href="item-edit.php?id=<?php echo $itemId; ?>" class="btn btn-warning"><i class="fas fa-edit"></i> Edit</a>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="detail-row"><strong>Item Code:</strong> <?php echo htmlspecialchars($item['item_code'] ?? ''); ?></div>
        <div class="detail-row"><strong>Name:</strong> <?php echo htmlspecialchars($item['item_name'] ?? ''); ?></div>
        <div class="detail-row"><strong>Type:</strong> <?php echo htmlspecialchars($item['item_type'] ?? 'Medicine'); ?></div>
        <div class="detail-row"><strong>Category:</strong> <?php echo htmlspecialchars($item['category_name'] ?? 'Uncategorized'); ?></div>
        <div class="detail-row"><strong>Stock:</strong> <?php echo intval($item['quantity_in_stock'] ?? 0); ?></div>
        <div class="detail-row"><strong>Reserved:</strong> <?php echo intval($item['quantity_reserved'] ?? 0); ?></div>
        <div class="detail-row"><strong>Reorder Level:</strong> <?php echo intval($item['reorder_level'] ?? 0); ?></div>
        <div class="detail-row"><strong>Unit Cost:</strong> <?php echo formatCurrency($item['unit_cost'] ?? 0); ?></div>
        <div class="detail-row"><strong>Status:</strong> <?php echo getStatusBadge($item['status'] ?? 'active'); ?></div>
        <div class="detail-row"><strong>Description:</strong>
            <div style="margin-top:8px;"><?php echo nl2br(htmlspecialchars($item['description'] ?? '')); ?></div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
