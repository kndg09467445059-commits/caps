<?php
session_start();

// Database connection
$host = 'localhost';
$dbname = 'inventory';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die("Connection failed: " . htmlspecialchars($e->getMessage()));
}

// Redirect if not admin
if (!isset($_SESSION['username']) || ($_SESSION['user_type'] ?? '') !== 'admin' || !isset($_SESSION['id'])) {
    header("Location: admin_login.php");
    exit();
}

// Get admin full name
$stmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
$stmt->execute([$_SESSION['id']]);
$userData = $stmt->fetch();
$fullName = $userData ? trim(($userData['first_name'] ?? '') . ' ' . ($userData['last_name'] ?? '')) : $_SESSION['username'];

// Date filters (default range: this year)
$fromDate = $_GET['from_date'] ?? date('Y-01-01');
$toDate = $_GET['to_date'] ?? date('Y-12-31');

$fromObj = DateTime::createFromFormat('Y-m-d', $fromDate);
$toObj = DateTime::createFromFormat('Y-m-d', $toDate);
if (!$fromObj || !$toObj) {
    $fromDate = date('Y-01-01');
    $toDate = date('Y-12-31');
}

// === IMAGE UPLOAD & DELETE ===
$uploadMessage = '';
$uploadError = '';

// Create uploads folder
$uploadDir = 'uploads/';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
}

// Upload handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_image'])) {
    if (!isset($_FILES['image'])) {
        $uploadError = "No file uploaded.";
    } else {
        $file = $_FILES['image'];

        if (!isset($file['error']) || is_array($file['error'])) {
            $uploadError = "Invalid file upload parameters.";
        } elseif ($file['error'] !== UPLOAD_ERR_OK) {
            $uploadError = "Upload error code: " . intval($file['error']);
        } else {
            $fileName = basename($file['name']);
            $fileTmp = $file['tmp_name'];
            $fileSize = $file['size'];
            $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            $allowed = ['jpg', 'jpeg', 'png', 'gif'];
            if (!in_array($fileExt, $allowed, true)) {
                $uploadError = "Only JPG, PNG, GIF allowed.";
            } elseif ($fileSize > 5 * 1024 * 1024) {
                $uploadError = "File too large (max 5MB).";
            } elseif (!is_uploaded_file($fileTmp)) {
                $uploadError = "Possible file upload attack.";
            } else {
                // Validate that the file is an actual image
                $imgInfo = @getimagesize($fileTmp);
                if ($imgInfo === false) {
                    $uploadError = "Uploaded file is not a valid image.";
                } else {
                    // Generate a safe unique name
                    $newFileName = uniqid('img_', true) . '.' . $fileExt;
                    $dest = $uploadDir . $newFileName;

                    if (move_uploaded_file($fileTmp, $dest)) {
                        $uploadMessage = "Image uploaded: <strong>" . htmlspecialchars($fileName, ENT_QUOTES, 'UTF-8') . "</strong>";
                    } else {
                        $uploadError = "Upload failed.";
                    }
                }
            }
        }
    }
}

// Delete image
if (isset($_GET['delete_image'])) {
    $img = basename($_GET['delete_image']);
    $path = $uploadDir . $img;
    if (file_exists($path) && is_file($path) && unlink($path)) {
        $uploadMessage = "Image deleted.";
    } else {
        $uploadError = "Failed to delete.";
    }
    $redirect = "sales_report.php?from_date=" . urlencode($fromDate) . "&to_date=" . urlencode($toDate);
    header("Location: $redirect");
    exit;
}

// Load uploaded images (sorted newest first)
$uploadedImages = [];
if (is_dir($uploadDir)) {
    $files = array_diff(scandir($uploadDir), ['.', '..']);
    foreach ($files as $file) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'], true)) {
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
$totalRevenue = (int)($totalRevenueQuery->fetchColumn() ?: 0);

$bookingsByStatusQuery = $pdo->prepare("
    SELECT status, COUNT(*) AS count
    FROM service_bookings
    WHERE DATE(appointment_date) BETWEEN ? AND ?
    GROUP BY status
");
$bookingsByStatusQuery->execute([$fromDate, $toDate]);
$bookingsByStatus = $bookingsByStatusQuery->fetchAll();

$monthlySales = [];
for ($i = 11; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $q = $pdo->prepare("
        SELECT SUM(CAST(TRIM(SUBSTRING_INDEX(price_range, '=', -1)) AS UNSIGNED)) AS revenue
        FROM service_bookings
        WHERE status = 'Completed' AND DATE_FORMAT(appointment_date, '%Y-%m') = ?
    ");
    $q->execute([$month]);
    $monthlySales[$month] = (int)($q->fetchColumn() ?: 0);
}

$debugQuery = $pdo->prepare("SELECT COUNT(*) FROM service_bookings");
$debugQuery->execute();
$totalBookingsInDB = (int)$debugQuery->fetchColumn();

$debugDateQuery = $pdo->prepare("SELECT COUNT(*) FROM service_bookings WHERE DATE(appointment_date) BETWEEN ? AND ?");
$debugDateQuery->execute([$fromDate, $toDate]);
$bookingsInRange = (int)$debugDateQuery->fetchColumn();

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
$detailedBookings = $detailedBookingsQuery->fetchAll();
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
        .header-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0; opacity: 0.05; }
        .header-content { position: relative; z-index: 2; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 2rem; }
        .header-title { color: white; font-size: 2.5rem; font-weight: 800; margin-bottom: 0.5rem; text-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .header-subtitle { color: rgba(255,255,255,0.9); font-size: 1.1rem; }
        .btn { padding: 0.875rem 2rem; border-radius: 16px; font-weight: 600; font-size: 0.95rem; display: inline-flex; align-items: center; gap: 0.5rem; transition: all 0.3s ease; border: none; cursor: pointer; }
        .btn-primary { background: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.3); }
        .btn-primary:hover { background: rgba(255,255,255,0.3); transform: translateY(-2px); }
        .btn-danger { background: linear-gradient(135deg, #ff6b6b 0%, #ee5a52 100%); color: white; }
        .btn-danger:hover { transform: translateY(-2px); }
        .filter-card { background: white; border-radius: 20px; padding: 2rem; margin-bottom: 2rem; box-shadow: 0 10px 30px rgba(0,0,0,0.06); }
        .filter-title { font-size: 1.5rem; font-weight: 700; color: #2d3748; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem; }
        .form-label { font-weight: 600; color: #4a5568; margin-bottom: 0.5rem; display: block; }
        .form-input { padding: 0.875rem 1rem; border: 2px solid #e2e8f0; border-radius: 12px; font-size: 0.95rem; background: #f8fafc; transition: all 0.3s ease; width: 100%; }
        .form-input:focus { outline: none; border-color: #667eea; box-shadow: 0 0 0 3px rgba(102,126,234,0.08); }
        .btn-filter { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 0.875rem 2rem; border-radius: 12px; font-weight: 600; }
        .btn-reset { background: #f7fafc; color: #4a5568; border: 2px solid #e2e8f0; padding: 0.875rem 2rem; border-radius: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: .5rem; }
        .btn-download { background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; padding: 0.875rem 2rem; border-radius: 12px; font-weight: 600; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 2rem; margin-bottom: 2rem; }
        .stat-card { background: white; border-radius: 20px; padding: 2rem; box-shadow: 0 10px 30px rgba(0,0,0,0.06); position: relative; overflow: hidden; }
        .stat-value { font-size: 2.5rem; font-weight: 800; color: #1a202c; }
        .stat-label { font-size: 1rem; color: #718096; }
        .stat-icon { width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: white; }
