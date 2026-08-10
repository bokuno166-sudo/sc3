<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin']);

$pageTitle = 'Edit Room';
$currentPage = 'rooms';

$conn = getDBConnection();
$roomId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($roomId <= 0) {
    setFlashMessage('error', 'Invalid room selected.');
    redirect('modules/admin/rooms.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $room_number = sanitize($_POST['room_number'] ?? '');
    $room_type = sanitize($_POST['room_type'] ?? '');
    $floor = sanitize($_POST['floor'] ?? '');
    $capacity = intval($_POST['capacity'] ?? 2);
    $daily_rate = floatval($_POST['daily_rate'] ?? 0);
    $status = sanitize($_POST['status'] ?? 'available');
    $description = sanitize($_POST['description'] ?? '');

    $stmt = $conn->prepare("UPDATE rooms SET room_number = ?, room_type = ?, floor = ?, capacity = ?, daily_rate = ?, status = ?, description = ? WHERE id = ?");
    $stmt->bind_param('sssidssi', $room_number, $room_type, $floor, $capacity, $daily_rate, $status, $description, $roomId);
    $executed = $stmt->execute();
    $stmt->close();

    if ($executed) {
        logActivity('update', 'rooms', $roomId, null, json_encode($_POST));
        setFlashMessage('success', 'Room updated successfully.');
        $conn->close();
        redirect('modules/admin/rooms.php');
    } else {
        setFlashMessage('error', 'Failed to update room.');
    }
}

$res = $conn->query("SELECT * FROM rooms WHERE id = $roomId");
if (!$res || $res->num_rows === 0) {
    setFlashMessage('error', 'Room not found.');
    $conn->close();
    redirect('modules/admin/rooms.php');
}
$room = $res->fetch_assoc();
$conn->close();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Edit Room</h1>
        <p class="page-subtitle">Update room details and rates</p>
    </div>
    <div>
        <a href="rooms.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <form method="post" action="rooms-edit.php?id=<?php echo $roomId; ?>">
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label>Room Number</label>
                    <input type="text" name="room_number" class="form-control" value="<?php echo htmlspecialchars($room['room_number'] ?? ''); ?>" required>
                </div>
                <div class="form-group col-md-4">
                    <label>Type</label>
                    <select id="room_type_select" class="form-control">
                        <option value="">Select type</option>
                        <option value="Ward">Ward</option>
                        <option value="Private">Private</option>
                        <option value="Other">Other (specify)</option>
                    </select>
                    <input type="text" id="room_type_other" class="form-control" placeholder="Specify type" style="margin-top:8px; display:none;">
                    <input type="hidden" name="room_type" id="room_type_hidden" value="<?php echo htmlspecialchars($room['room_type'] ?? ''); ?>">
                </div>
                <div class="form-group col-md-4">
                    <label>Floor</label>
                    <input type="text" name="floor" class="form-control" value="<?php echo htmlspecialchars($room['floor'] ?? ''); ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group col-md-4">
                    <label>Daily Rate</label>
                        <input type="number" step="0.01" name="daily_rate" class="form-control" value="<?php echo htmlspecialchars($room['daily_rate'] ?? '0.00'); ?>">
                </div>
                <div class="form-group col-md-4">
                    <label>Status</label>
                    <select name="status" class="form-control">
                        <option value="available" <?php echo ($room['status'] ?? '')=='available' ? 'selected' : ''; ?>>Available</option>
                        <option value="occupied" <?php echo ($room['status'] ?? '')=='occupied' ? 'selected' : ''; ?>>Occupied</option>
                        <option value="maintenance" <?php echo ($room['status'] ?? '')=='maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label>Capacity</label>
                    <input type="number" min="1" name="capacity" class="form-control" value="<?php echo htmlspecialchars($room['capacity'] ?? 2); ?>">
                </div>
            </div>

            <div class="form-group">
                <label>Description</label>
                <textarea name="description" class="form-control"><?php echo htmlspecialchars($room['description'] ?? ''); ?></textarea>
            </div>

            <div class="form-group text-right">
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
    var typeSelect = document.getElementById('room_type_select');
    var otherInput = document.getElementById('room_type_other');
    var hidden = document.getElementById('room_type_hidden');
    var rateInput = document.querySelector('input[name="daily_rate"]');

    // initialize select based on existing hidden value
    var existing = hidden.value || '';
    if (existing === 'Ward' || existing === 'Private') {
        typeSelect.value = existing;
    } else if (existing !== '') {
        typeSelect.value = 'Other';
        otherInput.style.display = '';
        otherInput.value = existing;
    }

    function syncType() {
        var v = typeSelect.value;
        if (v === 'Other') {
            otherInput.style.display = '';
        } else {
            otherInput.style.display = 'none';
            otherInput.value = '';
        }
        if (v === 'Ward') rateInput.value = '900.00';
        else if (v === 'Private') rateInput.value = '1500.00';
    }

    typeSelect.addEventListener('change', syncType);

    var form = document.forms[0];
    form.addEventListener('submit', function(){
        var v = typeSelect.value;
        if (v === 'Other') {
            hidden.value = otherInput.value.trim();
        } else {
            hidden.value = v;
        }
    });
    syncType();
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
