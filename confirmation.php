<?php
session_start();

$host = 'localhost';
$dbname = 'inventory_system';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

if (!isset($_SESSION['username']) || $_SESSION['user_type'] !== 'customer' || !isset($_SESSION['id'])) {
    header("Location: customer_login.php");
    exit();
}

if (!isset($_GET['booking_id'])) {
    echo "Invalid request.";
    exit();
}

$booking_id = $_GET['booking_id'];

$stmt = $pdo->prepare("
    SELECT
        sb.booking_id, sb.appointment_date, sb.appointment_time, sb.phone_number,
        sb.service_name, sb.reference_code, sb.customer_name, sb.price_range,
        sb.status, sb.address, sb.structure_type,
        s.service_type, s.service_details
    FROM service_bookings sb
    JOIN services s ON sb.service_id = s.service_id
    WHERE sb.booking_id = ? AND sb.id = ?
");
$stmt->execute([$booking_id, $_SESSION['id']]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    echo "Booking not found or access denied.";
    exit();
}

// Download receipt
if (isset($_POST['download_receipt']) && isset($_POST['reference_code'])) {
    $ref = $_POST['reference_code'];
    $stmt = $pdo->prepare("
        SELECT sb.reference_code, sb.service_name, sb.service_type, sb.appointment_date, sb.appointment_time,
               sb.price_range, sb.structure_type, sb.status, sb.customer_name, sb.address
        FROM service_bookings sb
        WHERE sb.reference_code = ? AND sb.id = ?
    ");
    $stmt->execute([$ref, $_SESSION['id']]);
    $booking_receipt = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking_receipt) {
        $_SESSION['error'] = "Booking not found or you don't have permission to access it.";
        header("Location: booking_form.php");
        exit();
    }

    // Generate HTML receipt
    $receipt = '<!DOCTYPE html>' . "\n";
    $receipt .= '<html lang="en">' . "\n";
    $receipt .= '<head>' . "\n";
    $receipt .= '    <meta charset="UTF-8">' . "\n";
    $receipt .= '    <title>Pest Control Service Receipt</title>' . "\n";
    $receipt .= '    <style>' . "\n";
    $receipt .= '        body { font-family: "Inter", sans-serif; margin: 0; padding: 0; background-color: #f5f5f5; }' . "\n";
    $receipt .= '        .receipt-container { max-width: 800px; margin: 40px auto; border: 1px solid #e0e0e0; padding: 40px; background-color: #fff; border-radius: 12px; box-shadow: 0 8px 24px rgba(0,0,0,0.05); }' . "\n";
    $receipt .= '        .receipt-header { text-align: center; border-bottom: 2px solid #22c55e; padding-bottom: 20px; margin-bottom: 30px; }' . "\n";
    $receipt .= '        .receipt-header h1 { margin: 0; font-size: 32px; color: #22c55e; font-weight: 700; }' . "\n";
    $receipt .= '        .receipt-header p { margin: 6px 0; font-size: 15px; color: #4b5563; }' . "\n";
    $receipt .= '        table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }' . "\n";
    $receipt .= '        th, td { padding: 14px; text-align: left; border-bottom: 1px solid #e5e7eb; font-size: 15px; }' . "\n";
    $receipt .= '        th { background-color: #f9fafb; color: #22c55e; width: 30%; font-weight: 600; }' . "\n";
    $receipt .= '        tr:nth-child(even) { background-color: #f9fafb; }' . "\n";
    $receipt .= '        .receipt-footer { text-align: center; margin-top: 40px; padding-top: 20px; border-top: 1px solid #e5e7eb; font-size: 14px; color: #6b7280; }' . "\n";
    $receipt .= '        .receipt-footer p { margin: 6px 0; }' . "\n";
    $receipt .= '        @media print { ' . "\n";
    $receipt .= '            body { margin: 0; padding: 0; }' . "\n";
    $receipt .= '            .receipt-container { box-shadow: none; border: none; margin: 0; width: 100%; max-width: 800px; }' . "\n";
    $receipt .= '            @page { size: A4; margin: 20mm; }' . "\n";
    $receipt .= '        }' . "\n";
    $receipt .= '    </style>' . "\n";
    $receipt .= '</head>' . "\n";
    $receipt .= '<body>' . "\n";
    $receipt .= '    <div class="receipt-container">' . "\n";
    $receipt .= '        <div class="receipt-header">' . "\n";
    $receipt .= '            <h1>Pest Control Services</h1>' . "\n";
    $receipt .= '            <p>123 Greenway Ave, City, Country</p>' . "\n";
    $receipt .= '            <p>Email: support@pestcontrol.com | Phone: +1-800-555-1234</p>' . "\n";
    $receipt .= '            <p>Receipt Reference: ' . htmlspecialchars($booking_receipt['reference_code']) . '</p>' . "\n";
    $receipt .= '        </div>' . "\n";
    $receipt .= '        <table>' . "\n";
    $receipt .= '            <tr><th>Customer Name</th><td>' . htmlspecialchars($booking_receipt['customer_name']) . '</td></tr>' . "\n";
    $receipt .= '            <tr><th>Address</th><td>' . htmlspecialchars($booking_receipt['address']) . '</td></tr>' . "\n";
    $receipt .= '            <tr><th>Service Name</th><td>' . htmlspecialchars($booking_receipt['service_name']) . '</td></tr>' . "\n";
    $receipt .= '            <tr><th>Service Type</th><td>' . htmlspecialchars($booking_receipt['service_type']) . '</td></tr>' . "\n";
    $receipt .= '            <tr><th>Appointment Date</th><td>' . htmlspecialchars($booking_receipt['appointment_date']) . '</td></tr>' . "\n";
    $receipt .= '            <tr><th>Appointment Time</th><td>' . htmlspecialchars($booking_receipt['appointment_time']) . '</td></tr>' . "\n";
    $receipt .= '            <tr><th>Price Range</th><td>' . htmlspecialchars($booking_receipt['price_range']) . '</td></tr>' . "\n";
    $receipt .= '            <tr><th>Structure Type</th><td>' . htmlspecialchars($booking_receipt['structure_type'] ?: 'N/A') . '</td></tr>' . "\n";
    $receipt .= '            <tr><th>Status</th><td>' . htmlspecialchars($booking_receipt['status']) . '</td></tr>' . "\n";
    $receipt .= '        </table>' . "\n";
    $receipt .= '        <div class="receipt-footer">' . "\n";
    $receipt .= '            <p>Thank you for choosing Pest Control Services!</p>' . "\n";
    $receipt .= '            <p>For inquiries, contact us at support@pestcontrol.com or +1-800-555-1234.</p>' . "\n";
    $receipt .= '        </div>' . "\n";
    $receipt .= '    </div>' . "\n";
    $receipt .= '</body>' . "\n";
    $receipt .= '</html>' . "\n";

    // Set headers for HTML file download
    ob_start();
    header('Content-Type: text/html');
    header('Content-Disposition: attachment; filename="receipt_' . $booking_receipt['reference_code'] . '.html"');
    header('Content-Length: ' . strlen($receipt));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo $receipt;
    ob_end_flush();
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Confirmation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Booking Confirmation</h2>
        <a href="logout.php" class="btn btn-danger">Logout</a>
    </div>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success">
            <?= htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>

    <div class="card p-4">
        <h4 class="mb-3 text-success">Your booking was successful!</h4>
        <p><strong>Booking ID:</strong> <?= htmlspecialchars($booking['booking_id']) ?></p>
        <p><strong>Reference Code:</strong> <?= htmlspecialchars($booking['reference_code']) ?></p>
        <p><strong>Customer Name:</strong> <?= htmlspecialchars($booking['customer_name']) ?></p>
        <p><strong>Service Name:</strong> <?= htmlspecialchars($booking['service_name']) ?></p>
        <p><strong>Service Type:</strong> <?= htmlspecialchars($booking['service_type']) ?></p>
        <p><strong>Price Range:</strong> <?= htmlspecialchars($booking['price_range']) ?></p>
        <p><strong>Details:</strong> <?= nl2br(htmlspecialchars($booking['service_details'])) ?></p>
        <p><strong>Phone Number:</strong> <?= htmlspecialchars($booking['phone_number']) ?></p>
        <p><strong>Appointment Date:</strong> <?= htmlspecialchars($booking['appointment_date']) ?></p>
        <p><strong>Appointment Time:</strong> <?= htmlspecialchars($booking['appointment_time']) ?></p>
        <p><strong>Address:</strong> <?= htmlspecialchars($booking['address']) ?></p>
        <p><strong>Structure Type:</strong> <?= htmlspecialchars($booking['structure_type'] ?: 'N/A') ?></p>
        <p><strong>Status:</strong> <?= htmlspecialchars($booking['status']) ?></p>
        <form method="post" class="mt-3">
            <input type="hidden" name="reference_code" value="<?= htmlspecialchars($booking['reference_code']) ?>">
            <button type="submit" name="download_receipt" class="btn btn-success">Download Receipt</button>
        </form>
    </div>

    <div class="mt-4 text-center">
        <a href="booking_form.php" class="btn btn-primary">Book Another Service</a>
    </div>
</div>
</body>
</html>
