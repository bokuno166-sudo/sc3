<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'doctor']);

$pageTitle = 'Referrals';
$currentPage = 'referrals';

$conn = getDBConnection();

// Get filter parameters
$statusFilter = isset($_GET['status']) ? sanitize($_GET['status']) : '';
$searchTerm = isset($_GET['search']) ? sanitize($_GET['search']) : '';

// Build query with filters
$whereClause = "WHERE 1=1";
if (!empty($statusFilter)) {
    $whereClause .= " AND r.status = '" . $conn->real_escape_string($statusFilter) . "'";
}
if (!empty($searchTerm)) {
    $whereClause .= " AND (p.first_name LIKE '%" . $conn->real_escape_string($searchTerm) . "%' 
                     OR p.last_name LIKE '%" . $conn->real_escape_string($searchTerm) . "%' 
                     OR p.patient_code LIKE '%" . $conn->real_escape_string($searchTerm) . "%'
                     OR r.referral_code LIKE '%" . $conn->real_escape_string($searchTerm) . "%')";
}

// Fetch referrals with pagination
$perPage = 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $perPage;

$countQuery = "SELECT COUNT(*) as total FROM referrals r
               LEFT JOIN patients p ON r.patient_id = p.id
               LEFT JOIN users u ON r.doctor_id = u.id
               $whereClause";
$countResult = $conn->query($countQuery);
$totalRows = $countResult->fetch_assoc()['total'];
$totalPages = ceil($totalRows / $perPage);

$query = "SELECT r.*, p.first_name, p.last_name, p.patient_code, u.full_name as doctor_name
          FROM referrals r
          LEFT JOIN patients p ON r.patient_id = p.id
          LEFT JOIN users u ON r.doctor_id = u.id
          $whereClause
          ORDER BY r.created_at DESC
          LIMIT $offset, $perPage";

$result = $conn->query($query);
$referrals = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Referrals Management</h1>
        <p class="page-subtitle">Track and manage patient referrals to other hospitals</p>
    </div>
</div>

<!-- Filters and Search -->
<div class="card">
    <div class="card-body">
        <form method="GET" action="" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; align-items: flex-end;">
            <div>
                <label class="form-label">Search (Patient or Referral Code)</label>
                <input type="text" name="search" class="form-control" placeholder="Search..." value="<?php echo htmlspecialchars($searchTerm); ?>">
            </div>
            
            <div>
                <label class="form-label">Status</label>
                <select name="status" class="form-control">
                    <option value="">-- All Status --</option>
                    <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="printed" <?php echo $statusFilter === 'printed' ? 'selected' : ''; ?>>Printed</option>
                    <option value="handed-to-patient" <?php echo $statusFilter === 'handed-to-patient' ? 'selected' : ''; ?>>Handed to Patient</option>
                    <option value="received-by-hospital" <?php echo $statusFilter === 'received-by-hospital' ? 'selected' : ''; ?>>Received by Hospital</option>
                    <option value="completed" <?php echo $statusFilter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                    <option value="cancelled" <?php echo $statusFilter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
            </div>
            
            <div style="display: flex; gap: 10px;">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i> Filter
                </button>
                <a href="referrals.php" class="btn btn-secondary">
                    <i class="fas fa-redo"></i> Reset
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Referrals Table -->
<div class="card">
    <div class="card-body">
        <?php if (count($referrals) === 0): ?>
            <p class="text-muted" style="text-align: center; padding: 30px;">
                <i class="fas fa-inbox" style="font-size: 40px; margin-bottom: 10px; display: block;"></i>
                No referrals found.
            </p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Referral Code</th>
                            <th>Patient</th>
                            <th>Hospital</th>
                            <th>Department</th>
                            <th>Doctor</th>
                            <th>Urgency</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($referrals as $ref): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($ref['referral_code']); ?></strong>
                            </td>
                            <td>
                                <div><?php echo htmlspecialchars($ref['first_name'] . ' ' . $ref['last_name']); ?></div>
                                <small class="text-muted"><?php echo htmlspecialchars($ref['patient_code']); ?></small>
                            </td>
                            <td><?php echo htmlspecialchars($ref['referral_hospital']); ?></td>
                            <td><?php echo htmlspecialchars($ref['referral_department'] ?: 'General'); ?></td>
                            <td><?php echo htmlspecialchars($ref['doctor_name'] ?? 'N/A'); ?></td>
                            <td>
                                <?php
                                $urgencyClass = match($ref['urgency']) {
                                    'emergency' => 'badge-danger',
                                    'urgent' => 'badge-warning',
                                    default => 'badge-info'
                                };
                                $urgencyLabel = ucfirst($ref['urgency']);
                                ?>
                                <span class="badge <?php echo $urgencyClass; ?>"><?php echo $urgencyLabel; ?></span>
                            </td>
                            <td>
                                <?php
                                $statusClass = match($ref['status']) {
                                    'pending' => 'badge-secondary',
                                    'printed' => 'badge-info',
                                    'handed-to-patient' => 'badge-primary',
                                    'received-by-hospital' => 'badge-success',
                                    'completed' => 'badge-success',
                                    'cancelled' => 'badge-danger',
                                    default => 'badge-secondary'
                                };
                                ?>
                                <span class="badge <?php echo $statusClass; ?>"><?php echo ucfirst(str_replace('-', ' ', $ref['status'])); ?></span>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($ref['created_at'])); ?></td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <a href="referral-letter.php?referral_id=<?php echo $ref['id']; ?>" class="btn btn-primary" title="View/Print Letter">
                                        <i class="fas fa-file-pdf"></i> Letter
                                    </a>
                                    <button class="btn btn-secondary" onclick="updateReferralStatus(<?php echo $ref['id']; ?>)" title="Update Status">
                                        <i class="fas fa-edit"></i> Status
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <nav aria-label="Page navigation" style="margin-top: 20px;">
                <ul class="pagination justify-content-center">
                    <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=1<?php echo $statusFilter ? '&status=' . urlencode($statusFilter) : ''; ?><?php echo $searchTerm ? '&search=' . urlencode($searchTerm) : ''; ?>">First</a>
                        </li>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo $statusFilter ? '&status=' . urlencode($statusFilter) : ''; ?><?php echo $searchTerm ? '&search=' . urlencode($searchTerm) : ''; ?>">Previous</a>
                        </li>
                    <?php endif; ?>

                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    
                    for ($p = $startPage; $p <= $endPage; $p++):
                        $active = $p === $page ? 'active' : '';
                    ?>
                        <li class="page-item <?php echo $active; ?>">
                            <a class="page-link" href="?page=<?php echo $p; ?><?php echo $statusFilter ? '&status=' . urlencode($statusFilter) : ''; ?><?php echo $searchTerm ? '&search=' . urlencode($searchTerm) : ''; ?>"><?php echo $p; ?></a>
                        </li>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo $statusFilter ? '&status=' . urlencode($statusFilter) : ''; ?><?php echo $searchTerm ? '&search=' . urlencode($searchTerm) : ''; ?>">Next</a>
                        </li>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo $totalPages; ?><?php echo $statusFilter ? '&status=' . urlencode($statusFilter) : ''; ?><?php echo $searchTerm ? '&search=' . urlencode($searchTerm) : ''; ?>">Last</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Status Update Modal -->
<div id="statusModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: white; padding: 30px; border-radius: 8px; min-width: 400px; box-shadow: 0 4px 20px rgba(0,0,0,0.2);">
        <h5 style="margin-bottom: 20px;">Update Referral Status</h5>
        <form id="statusForm" method="POST" action="referral-update-status.php">
            <input type="hidden" id="referralId" name="referral_id">
            <div class="form-group">
                <label class="form-label">New Status</label>
                <select name="status" class="form-control" required>
                    <option value="">-- Select Status --</option>
                    <option value="pending">Pending</option>
                    <option value="printed">Printed</option>
                    <option value="handed-to-patient">Handed to Patient</option>
                    <option value="received-by-hospital">Received by Hospital</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Notes (Optional)</label>
                <textarea name="notes" class="form-control" rows="3" placeholder="Add any notes about this status change..."></textarea>
            </div>
            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('statusModal').style.display='none';">Cancel</button>
                <button type="submit" class="btn btn-primary">Update Status</button>
            </div>
        </form>
    </div>
</div>

<script>
function updateReferralStatus(referralId) {
    document.getElementById('referralId').value = referralId;
    document.getElementById('statusModal').style.display = 'flex';
}

document.addEventListener('click', function(event) {
    const modal = document.getElementById('statusModal');
    if (event.target === modal) {
        modal.style.display = 'none';
    }
});
</script>

<?php
$conn->close();
include __DIR__ . '/../../includes/footer.php';
?>
