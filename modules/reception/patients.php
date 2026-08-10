<?php
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'staff', 'reception', 'nurse', 'doctor']);

$pageTitle = 'Patients';
$currentPage = 'patients';

$conn = getDBConnection();

// Search & Filter functionality
$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';
$gender = isset($_GET['gender']) ? sanitize($_GET['gender']) : '';
$blood_type = isset($_GET['blood_type']) ? sanitize($_GET['blood_type']) : '';
$civil_status = isset($_GET['civil_status']) ? sanitize($_GET['civil_status']) : '';
$status = isset($_GET['status']) ? sanitize($_GET['status']) : 'active';
$is_pregnant = isset($_GET['is_pregnant']) ? sanitize($_GET['is_pregnant']) : '';
$sort_by = isset($_GET['sort_by']) ? sanitize($_GET['sort_by']) : 'newest';

$whereClause = "WHERE 1=1";
if ($status && $status !== 'all') {
    $whereClause .= " AND status = '$status'";
}
if ($search) {
    $whereClause .= " AND (first_name LIKE '%$search%' OR last_name LIKE '%$search%' OR patient_code LIKE '%$search%' OR contact_number LIKE '%$search%')";
}
if ($gender) {
    $whereClause .= " AND gender = '$gender'";
}
if ($blood_type) {
    $whereClause .= " AND blood_type = '$blood_type'";
}
if ($civil_status) {
    $whereClause .= " AND civil_status = '$civil_status'";
}
if ($is_pregnant !== '') {
    $whereClause .= " AND is_pregnant = " . (int)$is_pregnant;
}

// Sorting logic
$orderBy = "ORDER BY created_at DESC";
switch ($sort_by) {
    case 'oldest':
        $orderBy = "ORDER BY created_at ASC";
        break;
    case 'name_asc':
        $orderBy = "ORDER BY last_name ASC, first_name ASC";
        break;
    case 'name_desc':
        $orderBy = "ORDER BY last_name DESC, first_name DESC";
        break;
    case 'age_youngest':
        $orderBy = "ORDER BY date_of_birth DESC";
        break;
    case 'age_oldest':
        $orderBy = "ORDER BY date_of_birth ASC";
        break;
}

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Get total count
$countResult = $conn->query("SELECT COUNT(*) as total FROM patients $whereClause");
$totalRecords = $countResult->fetch_assoc()['total'];
$totalPages = ceil($totalRecords / $limit);

// Get patients
$result = $conn->query("
    SELECT * FROM patients 
    $whereClause 
    $orderBy 
    LIMIT $limit OFFSET $offset
");

$conn->close();

// Pagination helper function
function getPaginationUrl($pageNum) {
    $params = $_GET;
    $params['page'] = $pageNum;
    return '?' . http_build_query($params);
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Patients</h1>
        <p class="page-subtitle">Manage patient records and information</p>
    </div>
    <?php if (hasRole(['admin', 'staff', 'reception'])): ?>
    <a href="patient-add.php" class="btn btn-primary">
        <i class="fas fa-user-plus"></i> Add New Patient
    </a>
    <?php endif; ?>
</div>

<!-- Search and Filter -->
<div class="card">
    <div class="card-body">
        <form method="GET" class="search-filter-form">
            <div class="search-row" style="display: flex; gap: 10px; margin-bottom: 0; align-items: center; width: 100%; flex-wrap: wrap;">
                <div class="search-box" style="flex: 1; min-width: 250px; position: relative;">
                    <i class="fas fa-search" style="position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: var(--secondary-color);"></i>
                    <input type="text" name="search" class="form-control" placeholder="Search by name, patient code, or contact number..." value="<?php echo htmlspecialchars($search); ?>" style="width: 100%; padding: 10px 15px 10px 40px; border: 1px solid var(--border-color); border-radius: var(--border-radius); background: var(--surface-color); color: var(--text-color);">
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i> Search
                </button>
                <button type="button" class="btn btn-secondary" id="toggle-filters-btn" onclick="toggleAdvancedFilters()" style="display: flex; align-items: center; gap: 6px; background: <?php echo ($gender || $blood_type || $civil_status || $status !== 'active' || $is_pregnant !== '' || $sort_by !== 'newest') ? 'var(--primary-color)' : 'transparent'; ?>; border: 1px solid <?php echo ($gender || $blood_type || $civil_status || $status !== 'active' || $is_pregnant !== '' || $sort_by !== 'newest') ? 'var(--primary-color)' : 'var(--border-color)'; ?>; color: <?php echo ($gender || $blood_type || $civil_status || $status !== 'active' || $is_pregnant !== '' || $sort_by !== 'newest') ? 'white' : 'var(--text-color)'; ?>; padding: 10px 15px; border-radius: var(--border-radius); cursor: pointer; font-weight: 500;">
                    <i class="fas fa-sliders-h"></i> Filters
                </button>
                <?php if ($search || $gender || $blood_type || $civil_status || $status !== 'active' || $is_pregnant !== '' || $sort_by !== 'newest'): ?>
                <a href="patients.php" class="btn btn-secondary" style="display: flex; align-items: center; gap: 6px; padding: 10px 15px; border-radius: var(--border-radius); text-decoration: none; font-weight: 500; font-size: 0.9rem;">
                    <i class="fas fa-times"></i> Clear All
                </a>
                <?php endif; ?>
            </div>
            
            <div class="filters-row" id="advanced-filters" style="display: <?php echo ($gender || $blood_type || $civil_status || $status !== 'active' || $is_pregnant !== '' || $sort_by !== 'newest') ? 'grid' : 'none'; ?>; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-top: 15px; padding-top: 15px; border-top: 1px solid var(--border-color);">
                <div class="filter-group">
                    <label style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.8rem; text-transform: uppercase; color: var(--text-muted);">Status</label>
                    <select name="status" class="form-control" onchange="this.form.submit()" style="width:100%; padding:8px 12px; border:1px solid var(--border-color); border-radius:var(--border-radius); background:var(--surface-color); color:var(--text-color); outline: none;">
                        <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                        <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        <option value="deceased" <?php echo $status === 'deceased' ? 'selected' : ''; ?>>Deceased</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.8rem; text-transform: uppercase; color: var(--text-muted);">Gender</label>
                    <select name="gender" class="form-control" onchange="this.form.submit()" style="width:100%; padding:8px 12px; border:1px solid var(--border-color); border-radius:var(--border-radius); background:var(--surface-color); color:var(--text-color); outline: none;">
                        <option value="" <?php echo $gender === '' ? 'selected' : ''; ?>>All Genders</option>
                        <option value="Male" <?php echo $gender === 'Male' ? 'selected' : ''; ?>>Male</option>
                        <option value="Female" <?php echo $gender === 'Female' ? 'selected' : ''; ?>>Female</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.8rem; text-transform: uppercase; color: var(--text-muted);">Blood Type</label>
                    <select name="blood_type" class="form-control" onchange="this.form.submit()" style="width:100%; padding:8px 12px; border:1px solid var(--border-color); border-radius:var(--border-radius); background:var(--surface-color); color:var(--text-color); outline: none;">
                        <option value="" <?php echo $blood_type === '' ? 'selected' : ''; ?>>All Blood Types</option>
                        <option value="A+" <?php echo $blood_type === 'A+' ? 'selected' : ''; ?>>A+</option>
                        <option value="A-" <?php echo $blood_type === 'A-' ? 'selected' : ''; ?>>A-</option>
                        <option value="B+" <?php echo $blood_type === 'B+' ? 'selected' : ''; ?>>B+</option>
                        <option value="B-" <?php echo $blood_type === 'B-' ? 'selected' : ''; ?>>B-</option>
                        <option value="AB+" <?php echo $blood_type === 'AB+' ? 'selected' : ''; ?>>AB+</option>
                        <option value="AB-" <?php echo $blood_type === 'AB-' ? 'selected' : ''; ?>>AB-</option>
                        <option value="O+" <?php echo $blood_type === 'O+' ? 'selected' : ''; ?>>O+</option>
                        <option value="O-" <?php echo $blood_type === 'O-' ? 'selected' : ''; ?>>O-</option>
                        <option value="Unknown" <?php echo $blood_type === 'Unknown' ? 'selected' : ''; ?>>Unknown</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.8rem; text-transform: uppercase; color: var(--text-muted);">Civil Status</label>
                    <select name="civil_status" class="form-control" onchange="this.form.submit()" style="width:100%; padding:8px 12px; border:1px solid var(--border-color); border-radius:var(--border-radius); background:var(--surface-color); color:var(--text-color); outline: none;">
                        <option value="" <?php echo $civil_status === '' ? 'selected' : ''; ?>>All Civil Statuses</option>
                        <option value="Single" <?php echo $civil_status === 'Single' ? 'selected' : ''; ?>>Single</option>
                        <option value="Married" <?php echo $civil_status === 'Married' ? 'selected' : ''; ?>>Married</option>
                        <option value="Widowed" <?php echo $civil_status === 'Widowed' ? 'selected' : ''; ?>>Widowed</option>
                        <option value="Separated" <?php echo $civil_status === 'Separated' ? 'selected' : ''; ?>>Separated</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.8rem; text-transform: uppercase; color: var(--text-muted);">Pregnancy</label>
                    <select name="is_pregnant" class="form-control" onchange="this.form.submit()" style="width:100%; padding:8px 12px; border:1px solid var(--border-color); border-radius:var(--border-radius); background:var(--surface-color); color:var(--text-color); outline: none;">
                        <option value="" <?php echo $is_pregnant === '' ? 'selected' : ''; ?>>All Patients</option>
                        <option value="1" <?php echo $is_pregnant === '1' ? 'selected' : ''; ?>>Pregnant</option>
                        <option value="0" <?php echo $is_pregnant === '0' ? 'selected' : ''; ?>>Not Pregnant</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.8rem; text-transform: uppercase; color: var(--text-muted);">Sort By</label>
                    <select name="sort_by" class="form-control" onchange="this.form.submit()" style="width:100%; padding:8px 12px; border:1px solid var(--border-color); border-radius:var(--border-radius); background:var(--surface-color); color:var(--text-color); outline: none;">
                        <option value="newest" <?php echo $sort_by === 'newest' ? 'selected' : ''; ?>>Newest Registered</option>
                        <option value="oldest" <?php echo $sort_by === 'oldest' ? 'selected' : ''; ?>>Oldest Registered</option>
                        <option value="name_asc" <?php echo $sort_by === 'name_asc' ? 'selected' : ''; ?>>Name (A-Z)</option>
                        <option value="name_desc" <?php echo $sort_by === 'name_desc' ? 'selected' : ''; ?>>Name (Z-A)</option>
                        <option value="age_youngest" <?php echo $sort_by === 'age_youngest' ? 'selected' : ''; ?>>Age (Youngest)</option>
                        <option value="age_oldest" <?php echo $sort_by === 'age_oldest' ? 'selected' : ''; ?>>Age (Oldest)</option>
                    </select>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
function toggleAdvancedFilters() {
    const filters = document.getElementById('advanced-filters');
    const btn = document.getElementById('toggle-filters-btn');
    if (filters.style.display === 'none') {
        filters.style.display = 'grid';
        btn.style.background = 'var(--primary-color)';
        btn.style.color = 'white';
        btn.style.borderColor = 'var(--primary-color)';
    } else {
        filters.style.display = 'none';
        btn.style.background = 'transparent';
        btn.style.color = 'var(--text-color)';
        btn.style.borderColor = 'var(--border-color)';
    }
}
</script>

<!-- Patients Table -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">Patient List</h3>
        <span class="badge badge-secondary"><?php echo $totalRecords; ?> Total</span>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-container">
            <table class="table" id="patientsTable">
                <thead>
                    <tr>
                        <th>Patient Code</th>
                        <th>Name</th>
                        <th>Age/Gender</th>
                        <th>Contact</th>
                        <th>Address</th>
                        <th>Blood Type</th>
                        <th>Registered</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result && $result->num_rows > 0): ?>
                        <?php while ($patient = $result->fetch_assoc()): ?>
                        <tr>
                            <td>
                                <strong><?php echo $patient['patient_code']; ?></strong>
                                <?php if ($patient['status'] !== 'active'): ?>
                                    <div style="margin-top: 4px;">
                                        <span class="badge badge-<?php echo $patient['status'] === 'deceased' ? 'danger' : 'warning'; ?>" style="font-size: 0.7rem; padding: 2px 6px; text-transform: uppercase;">
                                            <?php echo htmlspecialchars($patient['status']); ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $patient['first_name'] . ' ' . $patient['last_name']; ?></td>
                            <td><?php echo calculateAge($patient['date_of_birth']) . ' / ' . $patient['gender']; ?></td>
                            <td><?php echo $patient['contact_number']; ?></td>
                            <td><?php echo substr($patient['address'], 0, 30) . (strlen($patient['address']) > 30 ? '...' : ''); ?></td>
                            <td><?php echo $patient['blood_type']; ?></td>
                            <td><?php echo formatDate($patient['created_at']); ?></td>
                            <td class="table-actions">
                                <a href="patient-view.php?id=<?php echo $patient['id']; ?>" class="btn btn-sm btn-info" title="View">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="patient-edit.php?id=<?php echo $patient['id']; ?>" class="btn btn-sm btn-warning" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php if (hasRole(['admin', 'reception', 'staff'])): ?>
                                <a href="visit-add.php?patient_id=<?php echo $patient['id']; ?>" class="btn btn-sm btn-success" title="New Visit">
                                    <i class="fas fa-clipboard-list"></i>
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 30px;">
                                <i class="fas fa-inbox" style="font-size: 48px; color: #ddd; margin-bottom: 15px; display: block;"></i>
                                <p>No patients found</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
            <a href="<?php echo getPaginationUrl($page - 1); ?>"><i class="fas fa-chevron-left"></i></a>
            <?php endif; ?>
            
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <?php if ($i == $page): ?>
                <span class="active"><?php echo $i; ?></span>
                <?php else: ?>
                <a href="<?php echo getPaginationUrl($i); ?>"><?php echo $i; ?></a>
                <?php endif; ?>
            <?php endfor; ?>
            
            <?php if ($page < $totalPages): ?>
            <a href="<?php echo getPaginationUrl($page + 1); ?>"><i class="fas fa-chevron-right"></i></a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
