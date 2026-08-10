<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin']);

$pageTitle = 'View Room';
$currentPage = 'rooms';

$conn = getDBConnection();
$roomId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($roomId <= 0) {
    setFlashMessage('error', 'Invalid room selected.');
    redirect('modules/admin/rooms.php');
}

$res = $conn->query("SELECT * FROM rooms WHERE id = $roomId");
if (!$res || $res->num_rows === 0) {
    setFlashMessage('error', 'Room not found.');
    $conn->close();
    redirect('modules/admin/rooms.php');
}
$room = $res->fetch_assoc();

// optional beds info
$bedsInfo = null;
$tbl = $conn->query("SHOW TABLES LIKE 'beds'");
if ($tbl && $tbl->num_rows > 0) {
    $bRes = $conn->query("SELECT COUNT(*) AS total_beds, SUM(CASE WHEN status = 'occupied' THEN 1 ELSE 0 END) AS occupied_beds FROM beds WHERE room_id = $roomId");
    if ($bRes) $bedsInfo = $bRes->fetch_assoc();
}

$conn->close();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Room Details</h1>
        <p class="page-subtitle"><?php echo htmlspecialchars($room['room_number'] ?? ''); ?> — <?php echo htmlspecialchars($room['room_type'] ?? ''); ?></p>
    </div>
    <div>
        <a href="rooms.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
        <a href="rooms-edit.php?id=<?php echo $roomId; ?>" class="btn btn-warning"><i class="fas fa-edit"></i> Edit</a>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="detail-row"><strong>Room Number:</strong> <?php echo htmlspecialchars($room['room_number'] ?? ''); ?></div>
        <div class="detail-row"><strong>Type:</strong> <?php echo htmlspecialchars($room['room_type'] ?? ''); ?></div>
        <div class="detail-row"><strong>Floor:</strong> <?php echo htmlspecialchars($room['floor'] ?? '—'); ?></div>
        <div class="detail-row"><strong>Daily Rate:</strong> <?php echo formatCurrency($room['daily_rate'] ?? 0); ?></div>
        <div class="detail-row"><strong>Status:</strong> <?php echo getStatusBadge($room['status'] ?? 'available'); ?></div>
        <div class="detail-row"><strong>Beds:</strong>
            <?php
                if ($bedsInfo === null) {
                    echo '—';
                } else {
                    $total = $bedsInfo['total_beds'] ?? 0;
                    $occ = $bedsInfo['occupied_beds'] ?? 0;
                    echo $occ . ' / ' . $total;
                }
            ?>
        </div>
        <div class="detail-row"><strong>Description:</strong>
            <div style="margin-top:8px;"><?php echo nl2br(htmlspecialchars($room['description'] ?? '')); ?></div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
