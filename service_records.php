<?php
session_start();

// Restrict access to admin users only
if (!isset($_SESSION['username']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: log_in_page.php");
    exit();
}

// Database connection
$host = "localhost";
$dbname = "inventory";
$username = "root";
$password = "";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

/* --------------------------------------------------------------
   JSON ENDPOINT – ALL BOOKINGS (NO 90-DAY LIMIT) – ALL-DAY EVENTS
   -------------------------------------------------------------- */
if (isset($_GET['calendar']) && $_GET['calendar'] == 'events') {
    header('Content-Type: application/json');

    $stmt = $pdo->prepare("
        SELECT
            sb.booking_id,
            sb.appointment_date AS date,
            sb.status,
            sb.customer_name,
            sb.reference_code,
            s.service_name
        FROM service_bookings sb
        LEFT JOIN services s ON sb.service_id = s.service_id
        WHERE sb.appointment_date IS NOT NULL
        ORDER BY sb.appointment_date
    ");
    $stmt->execute();

    $events = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $title = htmlspecialchars($row['service_name'] . ' – ' . $row['customer_name']);
        $color = match($row['status']) {
            'Completed'   => '#38a169',
            'In Progress' => '#dd6b20',
            'Cancelled'   => '#e53e3e',
            default       => '#718096',
        };

        $events[] = [
            'id'    => $row['booking_id'],
            'title' => $title,
            'start' => $row['date'],
            'allDay'=> true,
            'color' => $color,
            'extendedProps' => [
                'reference' => $row['reference_code'],
                'status'    => $row['status'],
            ],
        ];
    }

    echo json_encode($events);
    exit;
}

/* --------------------------------------------------------------
   POST HANDLERS
   -------------------------------------------------------------- */
$success_message = '';
$error_message = '';

function validatePriceRange($price_range) {
    if (!preg_match('/^\d+-\d+$/', $price_range)) return false;
    list($min, $max) = explode('-', $price_range);
    return is_numeric($min) && is_numeric($max) && $min < $max;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Add Service
        if (isset($_POST['add_service'])) {
            $service_name = trim($_POST['service_name']);
            $service_type = trim($_POST['service_type']);
            $service_details = trim($_POST['service_details']);
            if (empty($service_name) || empty($service_type)) {
                throw new Exception("Service name and type are required.");
            }
            $stmt = $pdo->prepare("INSERT INTO services (service_name, service_type, service_details) VALUES (?, ?, ?)");
            $stmt->execute([$service_name, $service_type, $service_details]);
            $success_message = "Service '$service_name' added successfully!";
        }

        // Edit Service
        if (isset($_POST['edit_service'])) {
            $service_id = $_POST['service_id'];
            $service_name = trim($_POST['service_name']);
            $service_type = trim($_POST['service_type']);
            $service_details = trim($_POST['service_details']);
            if (empty($service_name) || empty($service_type)) {
                throw new Exception("Service name and type are required.");
            }
            $stmt = $pdo->prepare("UPDATE services SET service_name = ?, service_type = ?, service_details = ? WHERE service_id = ?");
            $stmt->execute([$service_name, $service_type, $service_details, $service_id]);
            $success_message = "Service '$service_name' updated successfully!";
        }

        // Delete Service
        if (isset($_POST['delete_service'])) {
            $service_id = $_POST['service_id'];
            if (!$service_id) throw new Exception("Service ID is missing.");
            $stmt = $pdo->prepare("SELECT service_name FROM services WHERE service_id = ?");
            $stmt->execute([$service_id]);
            $service = $stmt->fetch(PDO::FETCH_ASSOC);
            $service_name = $service ? $service['service_name'] : 'Unknown';
            $stmt = $pdo->prepare("DELETE FROM services WHERE service_id = ?");
            $stmt->execute([$service_id]);
            $stmt = $pdo->prepare("DELETE FROM service_price_ranges WHERE service_id = ?");
            $stmt->execute([$service_id]);
            $success_message = "Service '$service_name' and its price ranges deleted!";
        }

        // Add Price
        if (isset($_POST['add_price'])) {
            $service_id = $_POST['service_id'];
            $price_range = trim($_POST['price_range']);
            $price = floatval($_POST['price']);
            if (!validatePriceRange($price_range)) throw new Exception("Invalid price range format. Use 'X-Y'.");
            if ($price <= 0) throw new Exception("Price must be positive.");
            $stmt = $pdo->prepare("INSERT INTO service_price_ranges (service_id, price_range, price) VALUES (?, ?, ?)");
            $stmt->execute([$service_id, $price_range, $price]);
            $success_message = "Price range '$price_range SQM - ₱" . number_format($price, 2) . "' added!";
        }

        // Edit Price
        if (isset($_POST['edit_price'])) {
            $price_range_id = $_POST['price_range_id'];
            $price_range = trim($_POST['price_range']);
            $price = floatval($_POST['price']);
            if (!validatePriceRange($price_range)) throw new Exception("Invalid price range format.");
            if ($price <= 0) throw new Exception("Price must be positive.");
            $stmt = $pdo->prepare("UPDATE service_price_ranges SET price_range = ?, price = ? WHERE price_range_id = ?");
            $stmt->execute([$price_range, $price, $price_range_id]);
            $success_message = "Price range '$price_range SQM - ₱" . number_format($price, 2) . "' updated!";
        }

        // Delete Price
        if (isset($_POST['delete_price'])) {
            $price_range_id = $_POST['price_range_id'];
            if (!$price_range_id) throw new Exception("Price range ID missing.");
            $stmt = $pdo->prepare("DELETE FROM service_price_ranges WHERE price_range_id = ?");
            $stmt->execute([$price_range_id]);
            $success_message = "Price range deleted!";
        }

        // Update Booking Status
        if (isset($_POST['update_status'])) {
            $record_id = $_POST['record_id'];
            $new_status = $_POST['new_status'];
            $stmt = $pdo->prepare("SELECT reference_code FROM service_bookings WHERE booking_id = ?");
            $stmt->execute([$record_id]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);
            $ref_code = $booking ? $booking['reference_code'] : 'Unknown';
            $stmt = $pdo->prepare("UPDATE service_bookings SET status = ? WHERE booking_id = ?");
            $stmt->execute([$new_status, $record_id]);
            $success_message = "Booking '$ref_code' status updated to '$new_status'!";
        }
    } catch (Exception $e) {
        $error_message = "Error: " . $e->getMessage();
    }
}

/* --------------------------------------------------------------
   FETCH BOOKINGS (TABLE)
   -------------------------------------------------------------- */
$searchTerm = $_GET['search'] ?? '';
$filterStatus = $_GET['filter_status'] ?? '';

$query = "
    SELECT
        sb.booking_id, sb.id, sb.service_id, sb.structure_type, sb.appointment_date,
        sb.appointment_time, sb.status, sb.reference_code, sb.customer_name,
        sb.phone_number, sb.address, sb.price_range, s.service_name
    FROM service_bookings sb
    LEFT JOIN services s ON sb.service_id = s.service_id
    WHERE 1=1
";
$params = [];

if (!empty($searchTerm)) {
    $query .= " AND (sb.customer_name LIKE :term OR sb.reference_code LIKE :term OR sb.phone_number LIKE :term OR s.service_name LIKE :term)";
    $params['term'] = "%$searchTerm%";
}
if (!empty($filterStatus)) {
    $query .= " AND sb.status = :status";
    $params['status'] = $filterStatus;
}
$query .= " ORDER BY sb.appointment_date DESC, sb.appointment_time DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* --------------------------------------------------------------
   FETCH SERVICES & PRICES
   -------------------------------------------------------------- */
$services_stmt = $pdo->prepare("SELECT * FROM services ORDER BY service_name");
$services_stmt->execute();
$services = $services_stmt->fetchAll(PDO::FETCH_ASSOC);

$prices = [];
foreach ($services as $service) {
    $stmt = $pdo->prepare("SELECT * FROM service_price_ranges WHERE service_id = ? ORDER BY price_range");
    $stmt->execute([$service['service_id']]);
    $prices[$service['service_id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard - Service Management</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%); font-family: 'Poppins', sans-serif; min-height: 100vh; color: #333; }
        .dashboard-container { padding: 2rem; max-width: 1400px; margin: 0 auto; }
        .header-card { background: rgba(255,255,255,0.95); backdrop-filter: blur(20px); border-radius: 20px; padding: 2rem; margin-bottom: 2rem; box-shadow: 0 20px 40px rgba(0,0,0,0.1); border: 1px solid rgba(255,255,255,0.2); position: relative; overflow: hidden; }
        .header-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: linear-gradient(90deg, #667eea 0%, #764ba2 100%); }
        .header-title { color: #2d3748; font-size: 2.5rem; font-weight: 700; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .btn { padding: 0.75rem 1.5rem; border-radius: 12px; font-weight: 600; font-size: 0.9rem; border: none; cursor: pointer; transition: all 0.3s ease; display: inline-flex; align-items: center; gap: 0.5rem; }
        .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; box-shadow: 0 4px 15px rgba(102,126,234,0.4); }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(102,126,234,0.6); }
        .btn-danger { background: linear-gradient(135deg, #f56565 0%, #e53e3e 100%); color: white; }
        .btn-success { background: linear-gradient(135deg, #48bb78 0%, #38a169 100%); color: white; }
        .btn-warning { background: linear-gradient(135deg, #ed8936 0%, #dd6b20 100%); color: white; }
        .nav-tabs .nav-link { background: rgba(255,255,255,0.1); border: none; border-radius: 15px 15px 0 0; color: rgba(255,255,255,0.8); font-weight: 600; padding: 1rem 2rem; margin-right: 0.5rem; }
        .nav-tabs .nav-link.active { background: rgba(255,255,255,0.95); color: #2d3748; border-bottom: 2px solid #667eea; }
        .tab-content { background: rgba(255,255,255,0.95); backdrop-filter: blur(20px); border-radius: 20px; padding: 2rem; box-shadow: 0 20px 40px rgba(0,0,0,0.1); border: 1px solid rgba(255,255,255,0.2); }
        .filter-card { background: rgba(255,255,255,0.95); border-radius: 20px; padding: 1.5rem; margin-bottom: 2rem; box-shadow: 0 20px 40px rgba(0,0,0,0.1); }
        .table th { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .badge { padding: 0.5rem 1rem; font-size: 0.8rem; font-weight: 600; border-radius: 20px; text-transform: uppercase; }
        .bg-success { background: linear-gradient(135deg, #48bb78 0%, #38a169 100%) !important; }
        .bg-warning { background: linear-gradient(135deg, #ed8936 0%, #dd6b20 100%) !important; color: white !important; }
        .bg-danger { background: linear-gradient(135deg, #f56565 0%, #e53e3e 100%) !important; }
        .bg-secondary { background: linear-gradient(135deg, #a0aec0 0%, #718096 100%) !important; }
        .time-am-pm { font-weight: bold; padding: 2px 6px; border-radius: 4px; font-size: 0.8rem; margin-left: 4px; }
        .time-am-pm.pm {.web:3 { background: linear-gradient(135deg, #ffd700 0%, #ffed4e 100%); color: #744210; }
        .time-am-pm.am { background: linear-gradient(135deg, #68d391 0%, #48bb78 100%); color: white; }
        .summary-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem; margin-top: 2rem; }
        .summary-card { background: rgba(255,255,255,0.95); border-radius: 20px; padding: 2rem; text-align: center; box-shadow: 0 20px 40px rgba(0,0,0,0.1); transition: all 0.3s ease; }
        .summary-card:hover { transform: translateY(-5px); }
        .summary-card .card-text { font-size: 2.5rem; font-weight: 700; }
        .service-card { background: rgba(255,255,255,0.9); border-radius: 15px; padding: 1.5rem; margin-bottom: 1rem; border: 1px solid rgba(0,0,0,0.05); transition: all 0.3s ease; }
        .service-card:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
        .service-name { font-weight: 700; font-size: 1.2rem; color: #2d3748; }
        .service-type { font-size: 0.9rem; color: #667eea; font-weight: 600; }
        .service-details { font-size: 0.85rem; color: #4a5568; margin-top: 0.5rem; }
        #calendar { max-width: 1100px; margin: 2rem auto; background: rgba(255,255,255,0.95); border-radius: 20px; padding: 1.5rem; box-shadow: 0 20px 40px rgba(0,0,0,0.1); }
        .fc-event { cursor: pointer; }
        @media (max-width: 768px) { .dashboard-container { padding: 1rem; } .filter-form { flex-direction: column; } }
    </style>
</head>
<body>
<div class="dashboard-container">
    <!-- Header -->
    <div class="header-card">
        <div class="header-content d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h1 class="header-title">Service Management Dashboard</h1>
                <p class="header-subtitle">Manage services and track all bookings</p>
                <a href="dashboard.php" class="btn btn-secondary mt-3">Back</a>
            </div>
            <div class="admin-info d-flex align-items-center gap-3">
                <span class="admin-name">Admin: <?= htmlspecialchars($_SESSION['username']) ?></span>
                <form action="logout.php" method="post" class="mb-0">
                    <button type="submit" class="btn btn-danger btn-sm">Logout</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Messages -->
    <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show">Success: <?= htmlspecialchars($success_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php elseif ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show">Error: <?= htmlspecialchars($error_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Tabs -->
    <ul class="nav nav-tabs" id="myTab" role="tablist">
        <li class="nav-item"><button class="nav-link active" id="table-tab" data-bs-toggle="tab" data-bs-target="#tableView">Table</button></li>
        <li class="nav-item"><button class="nav-link" id="calendar-tab" data-bs-toggle="tab" data-bs-target="#calendarView">Calendar</button></li>
        <li class="nav-item"><button class="nav-link" id="maintenance-tab" data-bs-toggle="tab" data-bs-target="#maintenance">Maintenance</button></li>
    </ul>

    <div class="tab-content" id="myTabContent">

        <!-- TABLE VIEW -->
        <div class="tab-pane fade show active" id="tableView">
            <div class="filter-card">
                <form method="get" class="filter-form">
                    <div class="d-flex gap-2 flex-wrap">
                        <select name="filter_status" class="form-select" style="min-width:200px;">
                            <option value="">All Statuses</option>
                            <option value="Pending" <?= $filterStatus === 'Pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="In Progress" <?= $filterStatus === 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                            <option value="Completed" <?= $filterStatus === 'Completed' ? 'selected' : '' ?>>Completed</option>
                            <option value="Cancelled" <?= $filterStatus === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        </select>
                        <input type="text" name="search" class="form-control" placeholder="Search name, phone, ref, service..." value="<?= htmlspecialchars($searchTerm) ?>" style="min-width:300px;">
                        <button class="btn btn-primary" type="submit">Filter</button>
                    </div>
                </form>
            </div>

            <?php if (empty($records)): ?>
                <div class="alert alert-info">No bookings found.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead><tr>
                            <th>ID</th><th>User</th><th>Svc ID</th><th>Structure</th><th>Service</th><th>Customer</th><th>Phone</th><th>Address</th><th>Ref</th><th>Date</th><th>Time</th><th>Range</th><th>Status</th><th>Action</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($records as $r): ?>
                            <tr>
                                <td><?= $r['booking_id'] ?></td>
                                <td><?= $r['id'] ?></td>
                                <td><?= $r['service_id'] ?></td>
                                <td><?= htmlspecialchars($r['structure_type']) ?></td>
                                <td><?= htmlspecialchars($r['service_name']) ?></td>
                                <td><?= htmlspecialchars($r['customer_name']) ?></td>
                                <td><?= htmlspecialchars($r['phone_number']) ?></td>
                                <td><?= htmlspecialchars($r['address']) ?></td>
                                <td><?= htmlspecialchars($r['reference_code']) ?></td>
                                <td><?= $r['appointment_date'] ?></td>
                                <td>
                                    <?php
                                    $t = trim($r['appointment_time']);
                                    $formatted = $t;
                                    $ampm = '';

                                    if (preg_match('/^(\d{1,2}):(\d{2})\s*(AM|PM)$/i', $t, $m)) {
                                        $formatted = $m[1] . ':' . $m[2];
                                        $ampm = strtolower($m[3]);
                                    } elseif (preg_match('/^\d{1,2}:\d{2}$/', $t)) {
                                        $timeObj = DateTime::createFromFormat('H:i', $t);
                                        if ($timeObj) {
                                            $formatted = $timeObj->format('g:i');
                                            $ampm = strtolower($timeObj->format('A'));
                                        }
                                    } else {
                                        $formatted = htmlspecialchars($t);
                                    }

                                    echo htmlspecialchars($formatted);
                                    if ($ampm === 'am' || $ampm === 'pm') {
                                        echo '<span class="time-am-pm ' . $ampm . '">' . $ampm . '</span>';
                                    }
                                    ?>
                                </td>
                                <td><?= htmlspecialchars($r['price_range']) ?></td>
                                <td><span class="badge <?= match($r['status']) {
                                    'Completed'=>'bg-success','In Progress'=>'bg-warning',
                                    'Cancelled'=>'bg-danger',default=>'bg-secondary'}; ?>">
                                    <?= $r['status'] ?>
                                </span></td>
                                <td>
                                    <form method="post" class="d-flex gap-1">
                                        <input type="hidden" name="record_id" value="<?= $r['booking_id'] ?>">
                                        <select name="new_status" class="form-select form-select-sm">
                                            <option value="Pending" <?= $r['status']==='Pending'?'selected':'' ?>>Pending</option>
                                            <option value="In Progress" <?= $r['status']==='In Progress'?'selected':'' ?>>In Progress</option>
                                            <option value="Completed" <?= $r['status']==='Completed'?'selected':'' ?>>Completed</option>
                                            <option value="Cancelled" <?= $r['status']==='Cancelled'?'selected':'' ?>>Cancelled</option>
                                        </select>
                                        <button type="submit" name="update_status" class="btn btn-primary btn-sm">Update</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php
                $total = count($records);
                $completed = count(array_filter($records, fn($r) => $r['status'] === 'Completed'));
                $in_progress = count(array_filter($records, fn($r) => $r['status'] === 'In Progress'));
                $cancelled = count(array_filter($records, fn($r) => $r['status'] === 'Cancelled'));
                ?>
                <div class="summary-cards">
                    <div class="summary-card bg-info"><h5>Total</h5><p class="card-text"><?= $total ?></p></div>
                    <div class="summary-card bg-success"><h5>Completed</h5><p class="card-text"><?= $completed ?></p></div>
                    <div class="summary-card bg-warning"><h5>In Progress</h5><p class="card-text"><?= $in_progress ?></p></div>
                    <div class="summary-card bg-danger"><h5>Cancelled</h5><p class="card-text"><?= $cancelled ?></p></div>
                </div>
            <?php endif; ?>
        </div>

        <!-- CALENDAR VIEW -->
        <div class="tab-pane fade" id="calendarView">
            <div id="calendar"></div>
        </div>

        <!-- MAINTENANCE -->
        <div class="tab-pane fade" id="maintenance">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3>Service Management</h3>
                <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addServiceModal">Add Service</button>
            </div>
            <div class="row">
                <?php foreach ($services as $s): ?>
                    <div class="col-md-6 col-lg-4 mb-3">
                        <div class="service-card">
                            <div class="service-name"><?= htmlspecialchars($s['service_name']) ?></div>
                            <div class="service-type"><?= htmlspecialchars($s['service_type']) ?></div>
                            <div class="service-details"><?= htmlspecialchars($s['service_details']) ?></div>
                            <div class="price-range-list mt-3">
                                <strong>Prices:</strong>
                                <?php if (!empty($prices[$s['service_id']])): ?>
                                    <?php foreach ($prices[$s['service_id']] as $p): ?>
                                        <div class="price-range-item d-flex justify-content-between align-items-center py-1 border-bottom">
                                            <span><?= $p['price_range'] ?> SQM - ₱<?= number_format($p['price'], 2) ?></span>
                                            <div>
                                                <button class="btn btn-warning btn-sm" onclick="editPrice(<?= htmlspecialchars(json_encode($p)) ?>)">Edit</button>
                                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this price range?')">
                                                    <input type="hidden" name="price_range_id" value="<?= $p['price_range_id'] ?>">
                                                    <button type="submit" name="delete_price" class="btn btn-danger btn-sm">Delete</button>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="text-muted">No prices</div>
                                <?php endif; ?>
                            </div>
                            <div class="mt-3 d-flex gap-2">
                                <button class="btn btn-warning btn-sm" onclick="editService(<?= htmlspecialchars(json_encode($s)) ?>)">Edit</button>
                                <button class="btn btn-primary btn-sm" onclick="openPriceModal(<?= $s['service_id'] ?>, '<?= htmlspecialchars($s['service_name']) ?>')">Prices</button>
                                <form method="post" class="d-inline" onsubmit="return confirm('Delete service and all prices?')">
                                    <input type="hidden" name="service_id" value="<?= $s['service_id'] ?>">
                                    <button type="submit" name="delete_service" class="btn btn-danger btn-sm">Delete</button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if (empty($services)): ?>
                <div class="alert alert-info text-center">No services. Add one!</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ==================== MODALS ==================== -->

<!-- Add Service Modal -->
<div class="modal fade" id="addServiceModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="post">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">Add New Service</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Service Name</label>
                            <input type="text" class="form-control" name="service_name" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Service Type</label>
                            <select class="form-select" name="service_type" required>
                                <option value="">Select Type</option>
                                <option>Pest Control</option>
                                <option>Termite Treatment</option>
                                <option>Rodent Control</option>
                                <option>Mosquito Control</option>
                                <option>General Cleaning</option>
                                <option>Disinfection</option>
                                <option>Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Service Details</label>
                        <textarea class="form-control" name="service_details" rows="4" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_service" class="btn btn-success">Add Service</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Edit Service Modal -->
<div class="modal fade" id="editServiceModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="post">
            <input type="hidden" id="edit_service_id" name="service_id">
            <div class="modal-content">
                <div class="modal-header bg-warning text-white">
                    <h5 class="modal-title">Edit Service</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Service Name</label>
                            <input type="text" class="form-control" id="edit_service_name" name="service_name" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Service Type</label>
                            <select class="form-select" id="edit_service_type" name="service_type" required>
                                <option value="">Select Type</option>
                                <option>Pest Control</option>
                                <option>Termite Treatment</option>
                                <option>Rodent Control</option>
                                <option>Mosquito Control</option>
                                <option>General Cleaning</option>
                                <option>Disinfection</option>
                                <option>Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Service Details</label>
                        <textarea class="form-control" id="edit_service_details" name="service_details" rows="4" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="edit_service" class="btn btn-warning">Update Service</button>
                  </div>
</div>
</form>
</div>
</div>

<!-- Add Price Modal -->
<div class="modal fade" id="managePriceModal" tabindex="-1">
<div class="modal-dialog modal-lg">
<form method="post" id="addPriceForm">
<input type="hidden" id="price_service_id" name="service_id">
<div class="modal-content">
  <div class="modal-header bg-primary text-white">
      <h5 class="modal-title">Add Price for <span id="price_service_name"></span></h5>
      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body">
      <div class="row">
          <div class="col-md-6 mb-3">
              <label class="form-label">Size Range (e.g. 50-70)</label>
              <input type="text" class="form-control" name="price_range" placeholder="50-70" required pattern="\d+-\d+">
          </div>
          <div class="col-md-6 mb-3">
              <label class="form-label">Price (₱)</label>
              <input type="number" step="0.01" min="0.01" class="form-control" name="price" required>
          </div>
      </div>
  </div>
  <div class="modal-footer">
      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      <button type="submit" name="add_price" class="btn btn-success">Add Price</button>
  </div>
</div>
</form>
</div>
</div>

<!-- Edit Price Modal -->
<div class="modal fade" id="editPriceModal" tabindex="-1">
<div class="modal-dialog modal-lg">
<form method="post">
<input type="hidden" id="edit_price_range_id" name="price_range_id">
<div class="modal-content">
  <div class="modal-header bg-warning text-white">
      <h5 class="modal-title">Edit Price Range</h5>
      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body">
      <div class="row">
          <div class="col-md-6 mb-3">
              <label class="form-label">Size Range</label>
              <input type="text" class="form-control" id="edit_price_range" name="price_range" required pattern="\d+-\d+">
          </div>
          <div class="col-md-6 mb-3">
              <label class="form-label">Price (₱)</label>
              <input type="number" step="0.01" min="0.01" class="form-control" id="edit_price" name="price" required>
          </div>
      </div>
  </div>
  <div class="modal-footer">
      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      <button type="submit" name="edit_price" class="btn btn-warning">Update Price</button>
  </div>
</div>
</form>
</div>
</div>

<!-- SCRIPTS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
const calendarEl = document.getElementById('calendar');

const calendar = new FullCalendar.Calendar(calendarEl, {
initialView: 'dayGridMonth',
headerToolbar: {
left:   'prev,next today',
center: 'title',
right:  'dayGridMonth,timeGridWeek,timeGridDay'
},
initialDate: new Date(),
events: '?calendar=events',

eventClick: function(info) {
const p = info.event.extendedProps;
const badgeClass = p.status === 'Completed' ? 'bg-success' :
                 p.status === 'In Progress' ? 'bg-warning' :
                 p.status === 'Cancelled' ? 'bg-danger' : 'bg-secondary';

const modalHTML = `
  <div class="modal fade" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Event: ${info.event.title}</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p><strong>Reference:</strong> ${p.reference}</p>
          <p><strong>Status:</strong> <span class="badge ${badgeClass}">${p.status}</span></p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>`;

const div = document.createElement('div');
div.innerHTML = modalHTML;
document.body.appendChild(div);
const modal = new bootstrap.Modal(div.querySelector('.modal'));
modal.show();
div.querySelector('.modal').addEventListener('hidden.bs.modal', () => div.remove());
}
});

calendar.render();
});

// Modal Functions
function editService(s) {
document.getElementById('edit_service_id').value = s.service_id;
document.getElementById('edit_service_name').value = s.service_name;
document.getElementById('edit_service_type').value = s.service_type;
document.getElementById('edit_service_details').value = s.service_details;
new bootstrap.Modal(document.getElementById('editServiceModal')).show();
}

function openPriceModal(id, name) {
document.getElementById('price_service_id').value = id;
document.getElementById('price_service_name').textContent = name;
document.getElementById('addPriceForm').reset();
new bootstrap.Modal(document.getElementById('managePriceModal')).show();
}

function editPrice(p) {
document.getElementById('edit_price_range_id').value = p.price_range_id;
document.getElementById('edit_price_range').value = p.price_range;
document.getElementById('edit_price').value = p.price;
new bootstrap.Modal(document.getElementById('editPriceModal')).show();
}
</script>
</body>
</html>
