<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin']);

$pageTitle = 'Add Room';
$currentPage = 'rooms';

$conn = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $room_number = sanitize($_POST['room_number'] ?? '');
    $room_type = sanitize($_POST['room_type'] ?? '');
    $floor = sanitize($_POST['floor'] ?? '');
    $capacity = intval($_POST['capacity'] ?? 2);
    $daily_rate = floatval($_POST['daily_rate'] ?? 0);
    $status = sanitize($_POST['status'] ?? 'available');
    $description = sanitize($_POST['description'] ?? '');

    $stmt = $conn->prepare('INSERT INTO rooms (room_number, room_type, floor, capacity, daily_rate, status, description) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('sssidss', $room_number, $room_type, $floor, $capacity, $daily_rate, $status, $description);
    $ok = $stmt->execute();
    if ($ok) {
        $newId = $stmt->insert_id;
        logActivity('create', 'rooms', $newId, null, json_encode($_POST));
        setFlashMessage('success', 'Room added successfully.');
        $stmt->close();
        $conn->close();
        redirect('modules/admin/rooms.php');
    } else {
        setFlashMessage('error', 'Failed to add room: ' . $stmt->error);
        $stmt->close();
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Add Room</h1>
        <p class="page-subtitle">Create a new room</p>
    </div>
    <div>
        <a href="rooms.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <form method="post" action="rooms-add.php">
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label>Room Number</label>
                    <input type="text" name="room_number" class="form-control" required>
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
                    <input type="hidden" name="room_type" id="room_type_hidden" value="">
                </div>
                <div class="form-group col-md-4">
                    <label>Floor</label>
                    <input type="text" name="floor" class="form-control">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group col-md-4">
                    <label>Daily Rate</label>
                    <input type="number" step="0.01" name="daily_rate" class="form-control" value="0.00">
                </div>
                <div class="form-group col-md-4">
                    <label>Capacity</label>
                    <input type="number" min="1" name="capacity" class="form-control" value="2">
                </div>
                <div class="form-group col-md-4">
                    <label>Status</label>
                    <select name="status" class="form-control">
                        <option value="available">Available</option>
                        <option value="occupied">Occupied</option>
                        <option value="maintenance">Maintenance</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Description</label>
                <textarea name="description" class="form-control"></textarea>
            </div>

            <div class="form-group text-right">
                <button type="submit" class="btn btn-primary">Create Room</button>
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

    function syncType() {
        var v = typeSelect.value;
        if (v === 'Other') {
            otherInput.style.display = '';
        } else {
            otherInput.style.display = 'none';
            otherInput.value = '';
        }
        // preset rates for common types
        if (v === 'Ward') rateInput.value = '900.00';
        else if (v === 'Private') rateInput.value = '1500.00';
    }

    typeSelect.addEventListener('change', syncType);

    // ensure correct room_type is posted
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
