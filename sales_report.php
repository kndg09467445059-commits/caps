<?php
session_start();

// Database connection
$host = 'localhost';
$dbname = 'inventory';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Redirect if not admin
if (!isset($_SESSION['username']) || $_SESSION['user_type'] !== 'admin' || !isset($_SESSION['id'])) {
    header("Location: admin_login.php");
    exit();
}

// Get admin full name
$stmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
$stmt->execute([$_SESSION['id']]);
$userData = $stmt->fetch(PDO::FETCH_ASSOC);
$fullName = $userData ? $userData['first_name'] . ' ' . $userData['last_name'] : $_SESSION['username'];

// Date filters
$fromDate = $_GET['from_date'] ?? '2025-01-01';
$toDate = $_GET['to_date'] ?? '2025-12-31';

if (!DateTime::createFromFormat('Y-m-d', $fromDate) || !DateTime::createFromFormat('Y-m-d', $toDate)) {
    $fromDate = '2025-01-01';
    $toDate = '2025-12-31';
}

// === IMAGE UPLOAD & DELETE ===
$uploadMessage = '';
$uploadError = '';

// Create uploads folder
$uploadDir = 'uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Upload handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_image'])) {
    $file = $_FILES['image'];
    $fileName = basename($file['name']);
    $fileTmp = $file['tmp_name'];
    $fileSize = $file['size'];
    $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    $allowed = ['jpg', 'jpeg', 'png', 'gif'];
    if (!in_array($fileExt, $allowed)) {
        $uploadError = "Only JPG, PNG, GIF allowed.";
    } elseif ($fileSize > 5 * 1024 * 1024) {
        $uploadError = "File too large (max 5MB).";
    } else {
        $newFileName = uniqid('img_') . '.' . $fileExt;
        $dest = $uploadDir . $newFileName;
        if (move_uploaded_file($fileTmp, $dest)) {
            $uploadMessage = "Image uploaded: <strong>$fileName</strong>";
        } else {
            $uploadError = "Upload failed.";
        }
    }
}

// Delete image
if (isset($_GET['delete_image'])) {
    $img = basename($_GET['delete_image']);
    $path = $uploadDir . $img;
    if (file_exists($path) && unlink($path)) {
        $uploadMessage = "Image deleted.";
    } else {
        $uploadError = "Failed to delete.";
    }
    $redirect = "sales_report.php?from_date=" . urlencode($fromDate) . "&to_date=" . urlencode($toDate);
    header("Location: $redirect");
    exit;
}

// Load uploaded images
$uploadedImages = [];
if (is_dir($uploadDir)) {
    $files = array_diff(scandir($uploadDir), ['.', '..']);
    foreach ($files as $file) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) {
            $uploadedImages[] = $file;
        }
    }
    rsort($uploadedImages);
}

// === SALES & BOOKINGS DATA ===
$totalRevenueQuery = $pdo->prepare("
    SELECT SUM(CAST(TRIM(SUBSTRING_INDEX(price_range, '=', -1)) AS UNSIGNED)) AS total_revenue
    FROM service_bookings
    WHERE status = 'Completed' AND DATE(appointment_date) BETWEEN ? AND ?
");
$totalRevenueQuery->execute([$fromDate, $toDate]);
$totalRevenue = $totalRevenueQuery->fetchColumn() ?: 0;

$bookingsByStatusQuery = $pdo->prepare("
    SELECT status, COUNT(*) AS count
    FROM service_bookings
    WHERE DATE(appointment_date) BETWEEN ? AND ?
    GROUP BY status
");
$bookingsByStatusQuery->execute([$fromDate, $toDate]);
$bookingsByStatus = $bookingsByStatusQuery->fetchAll(PDO::FETCH_ASSOC);

$monthlySales = [];
for ($i = 11; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $q = $pdo->prepare("
        SELECT SUM(CAST(TRIM(SUBSTRING_INDEX(price_range, '=', -1)) AS UNSIGNED))
        FROM service_bookings
        WHERE status = 'Completed' AND DATE_FORMAT(appointment_date, '%Y-%m') = ?
    ");
    $q->execute([$month]);
    $monthlySales[$month] = $q->fetchColumn() ?: 0;
}

$debugQuery = $pdo->prepare("SELECT COUNT(*) FROM service_bookings");
$debugQuery->execute();
$totalBookingsInDB = $debugQuery->fetchColumn();

$debugDateQuery = $pdo->prepare("SELECT COUNT(*) FROM service_bookings WHERE DATE(appointment_date) BETWEEN ? AND ?");
$debugDateQuery->execute([$fromDate, $toDate]);
$bookingsInRange = $debugDateQuery->fetchColumn();

$detailedBookingsQuery = $pdo->prepare("
    SELECT
        sb.booking_id, sb.id, sb.service_id, sb.structure_type, sb.appointment_date,
        sb.appointment_time, sb.status, sb.reference_code, sb.customer_name, sb.price_range,
        sb.phone_number, sb.address, s.service_name,
        CAST(TRIM(SUBSTRING_INDEX(sb.price_range, '=', -1)) AS UNSIGNED) AS price
    FROM service_bookings sb
    LEFT JOIN services s ON sb.service_id = s.service_id
    WHERE DATE(sb.appointment_date) BETWEEN ? AND ?
    ORDER BY sb.appointment_date DESC, sb.appointment_time DESC
    LIMIT 100
");
$detailedBookingsQuery->execute([$fromDate, $toDate]);
$detailedBookings = $detailedBookingsQuery->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Report + Image Upload</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Poppins', sans-serif; background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%); min-height: 100vh; color: #333; }
        .dashboard-container { max-width: 1400px; margin: 0 auto; padding: 2rem; }
        .header-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 24px; padding: 2.5rem; margin-bottom: 2rem; box-shadow: 0 20px 40px rgba(0,0,0,0.1); position: relative; overflow: hidden; }
        .header-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="25" cy="25" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="75" cy="75" r="1" fill="rgba(255,255,255,0.1)"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>'); opacity: 0.3; }
        .header-content { position: relative; z-index: 2; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 2rem; }
        .header-title { color: white; font-size: 2.5rem; font-weight: 800; margin-bottom: 0.5rem; text-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .header-subtitle { color: rgba(255,255,255,0.9); font-size: 1.1rem; }
        .btn { padding: 0.875rem 2rem; border-radius: 16px; font-weight: 600; font-size: 0.95rem; display: inline-flex; align-items: center; gap: 0.5rem; transition: all 0.3s ease; border: none; cursor: pointer; }
        .btn-primary { background: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.3); }
        .btn-primary:hover { background: rgba(255,255,255,0.3); transform: translateY(-2px); }
        .btn-danger { background: linear-gradient(135deg, #ff6b6b 0%, #ee5a52 100%); color: white; }
        .btn-danger:hover { transform: translateY(-2px); }
        .filter-card { background: white; border-radius: 20px; padding: 2rem; margin-bottom: 2rem; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
        .filter-title { font-size: 1.5rem; font-weight: 700; color: #2d3748; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem; }
        .filter-title i { color: #667eea; }
        .filter-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; align-items: end; }
        .form-label { font-weight: 600; color: #4a5568; margin-bottom: 0.5rem; }
        .form-input { padding: 0.875rem 1rem; border: 2px solid #e2e8f0; border-radius: 12px; font-size: 0.95rem; background: #f8fafc; transition: all 0.3s ease; }
        .form-input:focus { outline: none; border-color: #667eea; box-shadow: 0 0 0 3px rgba(102,126,234,0.1); }
        .btn-filter { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 0.875rem 2rem; border-radius: 12px; font-weight: 600; }
        .btn-filter:hover { transform: translateY(-2px); }
        .btn-reset { background: #f7fafc; color: #4a5568; border: 2px solid #e2e8f0; padding: 0.875rem 2rem; border-radius: 12px; font-weight: 600; }
        .btn-download { background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; padding: 0.875rem 2rem; border-radius: 12px; font-weight: 600; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 2rem; margin-bottom: 2rem; }
        .stat-card { background: white; border-radius: 20px; padding: 2rem; box-shadow: 0 10px 30px rgba(0,0,0,0.1); position: relative; overflow: hidden; }
        .stat-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .stat-value { font-size: 2.5rem; font-weight: 800; color: #1a202c; }
        .stat-label { font-size: 1rem; color: #718096; }
        .stat-icon { width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: white; }
        .stat-icon.revenue { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
        .chart-card, .table-card { background: white; border-radius: 20px; padding: 2rem; margin-bottom: 2rem; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
        .chart-title, .table-title { font-size: 1.5rem; font-weight: 700; color: #2d3748; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem; }
        .chart-title i, .table-title i { color: #667eea; }
        .table { width: 100%; border-collapse: collapse; }
        .table th { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 1rem; text-align: left; font-weight: 600; }
        .table td { padding: 1rem; border-bottom: 1px solid #e2e8f0; }
        .status-badge { padding: 0.5rem 1rem; border-radius: 20px; font-size: 0.8rem; font-weight: 600; text-transform: uppercase; }
        .status-completed { background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; }
        .status-pending { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white; }
        .status-cancelled { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); color: white; }
        .status-inprogress { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white; }
        .image-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 1rem; margin-top: 1rem; }
        .image-card { position: relative; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .image-card img { width: 100%; height: 150px; object-fit: cover; }
        .image-overlay { position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); opacity: 0; transition: 0.3s; display: flex; align-items: center; justify-content: center; }
        .image-card:hover .image-overlay { opacity: 1; }
        .delete-btn { background: #ef4444; color: white; padding: 0.5rem 1rem; border-radius: 8px; font-size: 0.8rem; }
        .empty-state { text-align: center; padding: 3rem; color: #718096; }
        .empty-state i { font-size: 3rem; margin-bottom: 1rem; opacity: 0.5; }
        @media (max-width: 768px) { .dashboard-container { padding: 1rem; } .header-title { font-size: 2rem; } .filter-form { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="dashboard-container">

    <!-- Header -->
    <div class="header-card">
        <div class="header-content">
            <div>
                <h1 class="header-title"><i class="fas fa-chart-line"></i> Sales Report + Image Upload</h1>
                <p class="header-subtitle">Welcome, <?= htmlspecialchars($fullName) ?></p>
            </div>
            <div class="header-actions">
                <a href="dashboard.php" class="btn btn-primary"><i class="fas fa-arrow-left"></i> Back</a>
                <form action="logout.php" method="post" style="display:inline;">
                    <button type="submit" class="btn btn-danger"><i class="fas fa-sign-out-alt"></i> Logout</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Messages -->
    <?php if ($uploadMessage): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
            <?= $uploadMessage ?>
        </div>
    <?php endif; ?>
    <?php if ($uploadError): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
            <?= $uploadError ?>
        </div>
    <?php endif; ?>

    <!-- Image Upload -->
    <div class="filter-card">
        <h3 class="filter-title"><i class="fas fa-cloud-upload-alt"></i> Upload Images</h3>
        <form method="post" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
            <div>
                <label class="form-label">Select Image</label>
                <input type="file" name="image" accept="image/*" required class="form-input">
            </div>
            <div>
                <button type="submit" name="upload_image" class="btn-filter w-full">
                    <i class="fas fa-upload"></i> Upload
                </button>
            </div>
        </form>

        <?php if (!empty($uploadedImages)): ?>
            <div class="mt-6">
                <h4 class="text-lg font-semibold mb-3">Uploaded Images (<?= count($uploadedImages) ?>)</h4>
                <div class="image-grid">
                    <?php foreach ($uploadedImages as $img): ?>
                        <div class="image-card">
                            <img src="<?= $uploadDir . htmlspecialchars($img) ?>" alt="Upload">
                            <div class="image-overlay">
                                <a href="?delete_image=<?= urlencode($img) ?>&from_date=<?= urlencode($fromDate) ?>&to_date=<?= urlencode($toDate) ?>"
                                   class="delete-btn" onclick="return confirm('Delete this image?')">
                                    <i class="fas fa-trash"></i> Delete
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php else: ?>
            <p class="text-gray-500 mt-4">No images uploaded yet.</p>
        <?php endif; ?>
    </div>

    <!-- Date Filter -->
    <div class="filter-card">
        <h3 class="filter-title"><i class="fas fa-calendar-alt"></i> Filter by Date</h3>
        <form method="get" class="filter-form">
            <div class="form-group">
                <label class="form-label">From</label>
                <input type="date" name="from_date" class="form-input" value="<?= htmlspecialchars($fromDate) ?>">
            </div>
            <div class="form-group">
                <label class="form-label">To</label>
                <input type="date" name="to_date" class="form-input" value="<?= htmlspecialchars($toDate) ?>">
            </div>
            <div class="form-group">
                <button type="submit" class="btn-filter"><i class="fas fa-filter"></i> Apply</button>
            </div>
            <div class="form-group">
                <a href="sales_report.php" class="btn-reset"><i class="fas fa-refresh"></i> Reset</a>
            </div>
            <div class="form-group">
                <button type="button" onclick="downloadPDF()" class="btn-download">
                    <i class="fas fa-download"></i> PDF
                </button>
            </div>
        </form>
    </div>

    <!-- Debug Info -->
    <div class="filter-card">
        <h3 class="filter-title"><i class="fas fa-bug"></i> Debug Info</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
            <div><strong>Date Range:</strong> <?= htmlspecialchars($fromDate) ?> to <?= htmlspecialchars($toDate) ?></div>
            <div><strong>Total Bookings:</strong> <?= $totalBookingsInDB ?></div>
            <div><strong>In Range:</strong> <?= $bookingsInRange ?></div>
            <div><strong>Shown:</strong> <?= count($detailedBookings) ?></div>
        </div>
    </div>

    <!-- Total Sales -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="flex justify-between items-center">
                <div>
                    <div class="stat-value">₱<?= number_format($totalRevenue, 0) ?></div>
                    <div class="stat-label">Total Sales</div>
                </div>
                <div class="stat-icon revenue"><i class="fas fa-dollar-sign"></i></div>
            </div>
        </div>
    </div>

    <!-- Pie Chart -->
    <div class="chart-card">
        <h3 class="chart-title"><i class="fas fa-chart-pie"></i> Bookings by Status</h3>
        <?php if (!empty($bookingsByStatus)): ?>
            <canvas id="statusPieChart" style="max-height: 400px;"></canvas>
        <?php else: ?>
            <div class="empty-state"><i class="fas fa-inbox"></i><div>No data</div></div>
        <?php endif; ?>
    </div>

    <!-- Bar Chart -->
    <div class="chart-card">
        <h3 class="chart-title"><i class="fas fa-chart-bar"></i> Monthly Sales</h3>
        <?php if (array_sum($monthlySales) > 0): ?>
            <canvas id="monthlySalesChart" style="max-height: 400px;"></canvas>
        <?php else: ?>
            <div class="empty-state"><i class="fas fa-inbox"></i><div>No sales</div></div>
        <?php endif; ?>
    </div>

    <!-- Table -->
    <div class="table-card">
        <h3 class="table-title"><i class="fas fa-table"></i> All Bookings</h3>
        <div style="overflow-x: auto;">
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th><th>User</th><th>Svc</th><th>Structure</th><th>Service</th><th>Price Range</th>
                        <th>Customer</th><th>Phone</th><th>Address</th><th>Ref</th><th>Date</th><th>Time</th><th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($detailedBookings as $b): ?>
                        <tr>
                            <td><?= $b['booking_id'] ?></td>
                            <td><?= $b['id'] ?></td>
                            <td><?= $b['service_id'] ?></td>
                            <td><?= htmlspecialchars($b['structure_type']) ?></td>
                            <td><?= htmlspecialchars($b['service_name'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($b['price_range']) ?></td>
                            <td><?= htmlspecialchars($b['customer_name']) ?></td>
                            <td><?= htmlspecialchars($b['phone_number']) ?></td>
                            <td><?= htmlspecialchars($b['address']) ?></td>
                            <td><?= htmlspecialchars($b['reference_code']) ?></td>
                            <td><?= $b['appointment_date'] ?></td>
                            <td>
                                <?php
                                $t = trim($b['appointment_time']);
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
                                }
                                echo htmlspecialchars($formatted);
                                if ($ampm) {
                                    $color = $ampm === 'pm' ? 'bg-yellow-500' : 'bg-green-500';
                                    echo "<span class='inline-block ml-1 px-1.5 py-0.5 text-xs font-bold rounded text-white $color'>$ampm</span>";
                                }
                                ?>
                            </td>
                            <td><span class="status-badge status-<?= strtolower(str_replace(' ', '', $b['status'])) ?>"><?= $b['status'] ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($detailedBookings)): ?>
                        <tr><td colspan="13" class="empty-state"><i class="fas fa-inbox"></i><div>No bookings</div></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
<?php if (!empty($bookingsByStatus)): ?>
document.addEventListener('DOMContentLoaded', () => {
    const ctx = document.getElementById('statusPieChart').getContext('2d');
    const data = <?= json_encode([
        'labels' => array_column($bookingsByStatus, 'status'),
        'data' => array_column($bookingsByStatus, 'count'),
        'colors' => array_map(fn($s) => [
            'Completed'=>'#10b981','Pending'=>'#f59e0b','Cancelled'=>'#ef4444','In Progress'=>'#f59e0b'
        ][$s['status']] ?? '#6b7280', $bookingsByStatus)
    ]) ?>;
    new Chart(ctx, {
        type: 'pie',
        data: { labels: data.labels, datasets: [{ data: data.data, backgroundColor: data.colors, borderWidth: 2, borderColor: '#fff' }] },
        options: { responsive: true, plugins: { legend: { position: 'right' } } }
    });
});
<?php endif; ?>

<?php if (array_sum($monthlySales) > 0): ?>
document.addEventListener('DOMContentLoaded', () => {
    const ctx = document.getElementById('monthlySalesChart').getContext('2d');
    const data = <?= json_encode([
        'labels' => array_map(fn($m) => date('M Y', strtotime($m . '-01')), array_keys($monthlySales)),
        'data' => array_values($monthlySales)
    ]) ?>;
    new Chart(ctx, {
        type: 'bar',
        data: { labels: data.labels, datasets: [{ label: 'Revenue (₱)', data: data.data, backgroundColor: 'rgba(102,126,234,0.6)', borderColor: 'rgba(102,126,234,1)', borderWidth: 1 }] },
        options: {
            responsive: true,
            scales: { y: { beginAtZero: true, ticks: { callback: v => '₱' + v.toLocaleString() } } }
        }
    });
});
<?php endif; ?>

function downloadPDF() {
    const btn = document.querySelector('.btn-download');
    const text = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
    btn.disabled = true;
    setTimeout(() => {
        html2canvas(document.querySelector('.dashboard-container'), { scale: 2 }).then(canvas => {
            const img = canvas.toDataURL('image/png');
            const pdf = new jspdf.jsPDF('p', 'mm', 'a4');
            const w = 210, h = 295, imgH = (canvas.height * w) / canvas.width;
            let y = 0;
            pdf.addImage(img, 'PNG', 0, y, w, imgH);
            while (y += h < imgH) { pdf.addPage(); pdf.addImage(img, 'PNG', 0, y - h, w, imgH); }
            pdf.save('Sales_Report_<?= date('Y-m-d') ?>.pdf');
            btn.innerHTML = text; btn.disabled = false;
        });
    }, 500);
}
</script>
</body>
</html>
