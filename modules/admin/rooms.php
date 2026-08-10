<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin']);

$pageTitle = 'Rooms';
$currentPage = 'rooms';

$conn = getDBConnection();

// Fetch rooms with basic occupancy info (if beds table exists)
$roomsSql = "SELECT r.* FROM rooms r ORDER BY r.room_number ASC";
$rooms = $conn->query($roomsSql);

// Optionally fetch bed counts if beds table exists
$bedsInfo = [];
$tbl = $conn->query("SHOW TABLES LIKE 'beds'");
if ($tbl && $tbl->num_rows > 0) {
    $bedRes = $conn->query("SELECT room_id, COUNT(*) AS total_beds, SUM(CASE WHEN status = 'occupied' THEN 1 ELSE 0 END) AS occupied_beds FROM beds GROUP BY room_id");
    if ($bedRes) {
        while ($b = $bedRes->fetch_assoc()) {
            $bedsInfo[$b['room_id']] = $b;
        }
    }
}

$conn->close();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Rooms</h1>
        <p class="page-subtitle">Manage hospital rooms and rates</p>
    </div>
    <div>
        <a href="rooms-add.php" class="btn btn-primary"><i class="fas fa-plus"></i> Add Room</a>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Room List</h3>
        <span class="badge badge-secondary"><?php echo $rooms ? $rooms->num_rows : 0; ?> Rooms</span>
    </div>
    <div class="card-body" style="padding:0;">
        <?php if ($rooms && $rooms->num_rows > 0): ?>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Room #</th>
                        <th>Type</th>
                        <th>Floor</th>
                        <th>Rate / Day</th>
                        <th>Beds</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($r = $rooms->fetch_assoc()):
                        $beds = isset($bedsInfo[$r['id']]) ? $bedsInfo[$r['id']]['total_beds'] : '—';
                        $occupied = isset($bedsInfo[$r['id']]) ? $bedsInfo[$r['id']]['occupied_beds'] : 0;
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($r['room_number']); ?></strong></td>
                        <td><?php echo htmlspecialchars(ucfirst($r['room_type'] ?? $r['room_type'])); ?></td>
                        <td><?php echo htmlspecialchars($r['floor'] ?? '—'); ?></td>
                        <td><?php echo formatCurrency($r['daily_rate']); ?></td>
                        <td><?php echo $beds === '—' ? '—' : ($occupied . ' / ' . $beds); ?></td>
                        <td><?php echo getStatusBadge($r['status'] ?? 'available'); ?></td>
                        <td class="table-actions">
                            <a href="rooms-edit.php?id=<?php echo $r['id']; ?>" class="btn btn-sm btn-secondary"><i class="fas fa-edit"></i></a>
                            <a href="rooms-view.php?id=<?php echo $r['id']; ?>" class="btn btn-sm btn-info"><i class="fas fa-eye"></i></a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <div style="padding:30px; text-align:center; color:#999;">No rooms configured yet.</div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
