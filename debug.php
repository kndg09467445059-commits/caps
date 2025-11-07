<?php
session_start();

$host = 'localhost';
$dbname = 'inventory';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Redirect if not a logged-in customer
if (!isset($_SESSION['username']) || $_SESSION['user_type'] !== 'customer' || !isset($_SESSION['id'])) {
    header("Location: customer_login.php");
    exit();
}

// Fetch user full name
$stmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
$stmt->execute([$_SESSION['id']]);
$userData = $stmt->fetch(PDO::FETCH_ASSOC);
$fullName = $userData ? $userData['first_name'] . ' ' . $userData['last_name'] : $_SESSION['username'];

// Handle AJAX service detail request
if (isset($_POST['action']) && $_POST['action'] === 'get_service_details' && isset($_POST['service_name'])) {
    $stmt = $pdo->prepare("SELECT service_id, service_name, service_type, service_details FROM services WHERE service_name = ?");
    $stmt->execute([$_POST['service_name']]);
    $service = $stmt->fetch(PDO::FETCH_ASSOC);

    $response = [];
    if ($service) {
        // Fetch price ranges from service_price_ranges table
        $price_stmt = $pdo->prepare("
            SELECT price_range, price
            FROM service_price_ranges
            WHERE service_id = ?
            ORDER BY CAST(SUBSTRING_INDEX(price_range, '-', 1) AS UNSIGNED)
        ");
        $price_stmt->execute([$service['service_id']]);
        $price_ranges = $price_stmt->fetchAll(PDO::FETCH_ASSOC);

        $response = [
            'service_id' => $service['service_id'],
            'service_name' => $service['service_name'],
            'service_type' => $service['service_type'],
            'service_details' => $service['service_details'],
            'price_ranges' => $price_ranges
        ];
    } else {
        $response = ['error' => 'Service not found'];
    }
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

// Cancel booking
if (isset($_POST['cancel_booking']) && isset($_POST['reference_code'])) {
    $ref = $_POST['reference_code'];
    try {
        $pdo->beginTransaction();

        // Fetch service_name for the booking
        $stmt = $pdo->prepare("SELECT service_name FROM service_bookings WHERE reference_code = ? AND id = ?");
        $stmt->execute([$ref, $_SESSION['id']]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$booking) {
            $pdo->rollBack();
            $_SESSION['error'] = "Booking not found or you don't have permission to cancel it.";
            header("Location: booking_form.php");
            exit();
        }

        // Update booking status to Cancelled
        $cancel = $pdo->prepare("UPDATE service_bookings SET status = 'Cancelled' WHERE reference_code = ? AND id = ?");
        $cancel->execute([$ref, $_SESSION['id']]);

        // Fetch inventory requirements for the service
        $stmt = $pdo->prepare("SELECT active_ingredient, stocks_used FROM service_inventory WHERE service_name = ?");
        $stmt->execute([$booking['service_name']]);
        $requirements = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Restore inventory stocks
        foreach ($requirements as $req) {
            $restore = $pdo->prepare("UPDATE inventory SET stocks = stocks + ? WHERE LOWER(active_ingredient) = LOWER(?)");
            $restore->execute([$req['stocks_used'], $req['active_ingredient']]);
        }

        $pdo->commit();
        $_SESSION['success'] = "Booking with reference $ref has been cancelled.";
        header("Location: booking_form.php");
        exit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Failed to cancel booking: " . $e->getMessage();
        header("Location: booking_form.php");
        exit();
    }
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
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking || in_array($booking['status'], ['Cancelled', 'Completed'])) {
        $_SESSION['error'] = "Booking not found, completed, cancelled, or you don't have permission to access it.";
        header("Location: booking_form.php");
        exit();
    }

    // Generate HTML receipt
    $receipt = "
    <!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <title>Booking Receipt - {$booking['reference_code']}</title>
        <style>
            body { font-family: 'Inter', sans-serif; max-width: 800px; margin: 20px auto; padding: 20px; background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%); }
            .receipt { border: 1px solid #ddd; padding: 30px; border-radius: 16px; background: white; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
            h1 { text-align: center; color: #22c55e; font-size: 2rem; margin-bottom: 30px; }
            .details { margin-top: 20px; }
            .details p { margin: 10px 0; font-size: 1.1rem; }
            .footer { text-align: center; margin-top: 30px; font-size: 1rem; color: #666; }
        </style>
    </head>
    <body>
        <div class='receipt'>
            <h1>Booking Receipt</h1>
            <div class='details'>
                <p><strong>Reference Code:</strong> " . htmlspecialchars($booking['reference_code']) . "</p>
                <p><strong>Customer Name:</strong> " . htmlspecialchars($booking['customer_name']) . "</p>
                <p><strong>Service Name:</strong> " . htmlspecialchars($booking['service_name']) . "</p>
                <p><strong>Service Type:</strong> " . htmlspecialchars($booking['service_type']) . "</p>
                <p><strong>Address:</strong> " . htmlspecialchars($booking['address']) . "</p>
                <p><strong>Appointment Date:</strong> " . htmlspecialchars($booking['appointment_date']) . "</p>
                <p><strong>Appointment Time:</strong> " . htmlspecialchars($booking['appointment_time']) . "</p>
                <p><strong>Structure Type:</strong> " . htmlspecialchars($booking['structure_type'] ?: 'N/A') . "</p>
                <p><strong>Price Range:</strong> " . htmlspecialchars($booking['price_range']) . "</p>
                <p><strong>Status:</strong> " . htmlspecialchars($booking['status']) . "</p>
            </div>
            <div class='footer'>
                <p>Thank you for choosing our services!</p>
                <p>Contact us at support@pestcontrol.com for any inquiries.</p>
            </div>
        </div>
    </body>
    </html>";

    ob_start();
    header('Content-Type: text/html');
    header('Content-Disposition: attachment; filename="receipt_' . $booking['reference_code'] . '.html"');
    header('Content-Length: ' . strlen($receipt));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo $receipt;
    ob_end_flush();
    exit();
}

$maxBookingsPerDay = 10;

// Store booking data for confirmation modal
$bookingConfirmation = null;

if (isset($_POST['submit_booking'])) {
    $id = $_SESSION['id'];
    $service_name = $_POST['service_type'];
    $phone = $_POST['phone_number'];
    $address = $_POST['address'];
    $appointment = $_POST['appointment_date'];
    $appointment_time = $_POST['appointment_time'];
    $price_range = $_POST['price_range'];
    $reference_code = strtoupper(uniqid('REF'));

    // Validate structure type
    $structure_type = isset($_POST['structure_type']) ? htmlspecialchars($_POST['structure_type']) : '';
    if (empty($structure_type)) {
        $_SESSION['error'] = "Please select a structure type.";
        header("Location: booking_form.php");
        exit();
    }
    if ($structure_type === 'Other') {
        $structure_type = isset($_POST['structure_type_other']) ? htmlspecialchars($_POST['structure_type_other']) : '';
        if (empty($structure_type)) {
            $_SESSION['error'] = "Please specify the structure type for 'Other'.";
            header("Location: booking_form.php");
            exit();
        }
    }

    // Validate appointment time contains AM or PM
    if (!preg_match('/(AM|PM)$/', $appointment_time)) {
        $_SESSION['error'] = "Invalid appointment time format. Please select a time with AM or PM.";
        header("Location: booking_form.php");
        exit();
    }

    // Check if service exists
    $stmt = $pdo->prepare("SELECT service_id, service_type FROM services WHERE service_name = ?");
    $stmt->execute([$service_name]);
    $service_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$service_data) {
        $_SESSION['error'] = "Selected service does not exist.";
        header("Location: booking_form.php");
        exit();
    }

    // Validate price range - Extract the range and price from the submitted value
    // Expected format: "50-70 SQM = 25000 KES"
    if (!preg_match('/^(.+?)\s+SQM\s*=\s*(.+?)\s+KES$/', $price_range, $matches)) {
        $_SESSION['error'] = "Invalid price range format.";
        header("Location: booking_form.php");
        exit();
    }

    $selected_range = trim($matches[1]);
    $selected_price = trim(str_replace(',', '', $matches[2]));

    // Verify the price range exists in the database
    $price_stmt = $pdo->prepare("
        SELECT price_range, price
        FROM service_price_ranges
        WHERE service_id = ? AND price_range = ?
    ");
    $price_stmt->execute([$service_data['service_id'], $selected_range]);
    $valid_price = $price_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$valid_price) {
        $_SESSION['error'] = "Invalid price range selected for the service.";
        header("Location: booking_form.php");
        exit();
    }

    // Verify the price matches
    if (number_format($valid_price['price'], 0) != number_format($selected_price, 0)) {
        $_SESSION['error'] = "Price mismatch. Please select a valid price range.";
        header("Location: booking_form.php");
        exit();
    }

    // Check daily limit
    $checkBookings = $pdo->prepare("SELECT COUNT(*) FROM service_bookings WHERE appointment_date = ?");
    $checkBookings->execute([$appointment]);
    if ($checkBookings->fetchColumn() >= $maxBookingsPerDay) {
        $_SESSION['error'] = "The selected date is fully booked. Please choose another date.";
        header("Location: booking_form.php");
        exit();
    }

    // Check inventory for stock and expiration
    $stmt = $pdo->prepare("SELECT active_ingredient, stocks_used FROM service_inventory WHERE service_name = ?");
    $stmt->execute([$service_name]);
    $requirements = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $insufficient = [];
    $expired = [];
    $debug_info = [];
    $now = new DateTime();
    foreach ($requirements as $req) {
        $check = $pdo->prepare("SELECT stocks, expiry_date FROM inventory WHERE LOWER(active_ingredient) = LOWER(?)");
        $check->execute([$req['active_ingredient']]);
        $inventory_item = $check->fetch(PDO::FETCH_ASSOC);

        if ($inventory_item === false) {
            $insufficient[] = $req['active_ingredient'];
            $debug_info[] = "{$req['active_ingredient']}: Not found in inventory";
        } elseif ($inventory_item['stocks'] < $req['stocks_used']) {
            $insufficient[] = $req['active_ingredient'];
            $debug_info[] = "{$req['active_ingredient']}: Available={$inventory_item['stocks']}, Required={$req['stocks_used']}";
        } elseif (!empty($inventory_item['expiry_date'])) {
            $expiry = new DateTime($inventory_item['expiry_date']);
            if ($expiry < $now) {
                $expired[] = $req['active_ingredient'];
                $debug_info[] = "{$req['active_ingredient']}: Expired on {$inventory_item['expiry_date']}";
            }
        }
    }

    if (!empty($insufficient)) {
        $error_message = "Sorry, we cannot process your booking due to insufficient stock for: " . implode(", ", $insufficient) . ". Please try another service or contact support at support@pestcontrol.com.";
        if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin') {
            $error_message .= "<br><strong>Debug Info:</strong> " . implode("; ", $debug_info);
        }
        $_SESSION['error'] = $error_message;
        header("Location: booking_form.php");
        exit();
    }

    if (!empty($expired)) {
        $error_message = "Sorry, we cannot process your booking because the following items are expired: " . implode(", ", $expired) . ". Please try another service or contact support at support@pestcontrol.com.";
        if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin') {
            $error_message .= "<br><strong>Debug Info:</strong> " . implode("; ", $debug_info);
        }
        $_SESSION['error'] = $error_message;
        header("Location: booking_form.php");
        exit();
    }

    try {
        $pdo->beginTransaction();

        $service_id = $service_data['service_id'];
        $service_type = $service_data['service_type'];

        // Store the formatted price range
        $formatted_price_range = $selected_range . ' SQM = ' . number_format($valid_price['price'], 0) . ' KES';

        // Insert booking
        $insert = $pdo->prepare("INSERT INTO service_bookings
            (id, service_id, phone_number, address, appointment_date, appointment_time, reference_code, customer_name, price_range, status, service_type, structure_type, service_name)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?, ?, ?)
        ");
        $insert->execute([
            $id, $service_id, $phone, $address, $appointment, $appointment_time,
            $reference_code, $fullName, $formatted_price_range, $service_type, $structure_type, $service_name
        ]);

        // Deduct inventory stocks
        foreach ($requirements as $req) {
            $update_stmt = $pdo->prepare("UPDATE inventory SET stocks = stocks - ? WHERE LOWER(active_ingredient) = LOWER(?)");
            $update_stmt->execute([$req['stocks_used'], $req['active_ingredient']]);
        }

        // Update session inventory
        if (isset($_SESSION['inventory'])) {
            foreach ($_SESSION['inventory'] as &$item) {
                foreach ($requirements as $req) {
                    if (strtolower($item['active_ingredient']) === strtolower($req['active_ingredient'])) {
                        $item['stocks'] -= $req['stocks_used'];
                    }
                }
            }
            unset($item); // Break reference
        }

        $pdo->commit();

        // Store booking data for confirmation modal
        $bookingConfirmation = [
            'reference_code' => $reference_code,
            'service_name' => $service_name,
            'service_type' => $service_type,
            'appointment_date' => $appointment,
            'appointment_time' => $appointment_time,
            'price_range' => $formatted_price_range,
            'structure_type' => $structure_type ?: 'N/A',
            'status' => 'Pending',
            'customer_name' => $fullName,
            'address' => $address,
            'phone_number' => $phone
        ];

        $_SESSION['success'] = "Booking submitted successfully!";
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Booking failed: " . $e->getMessage();
        header("Location: booking_form.php");
        exit();
    }
}

// Fetch booking history
$bookingHistory = [];
$historyQuery = $pdo->prepare("
    SELECT sb.reference_code, sb.service_name, sb.service_type, sb.appointment_date, sb.appointment_time, sb.status, sb.price_range, sb.structure_type, sb.address
    FROM service_bookings sb
    WHERE sb.id = ?
    ORDER BY STR_TO_DATE(CONCAT(sb.appointment_date, ' ', sb.appointment_time), '%Y-%m-%d %h:%i %p') DESC
");
$historyQuery->execute([$_SESSION['id']]);
$bookingHistory = $historyQuery->fetchAll(PDO::FETCH_ASSOC);

// Fetch services with availability status, including expiration check
$services_status = [];
$all_services = $pdo->query("SELECT service_id, service_name FROM services ORDER BY service_name")->fetchAll(PDO::FETCH_ASSOC);
$now = new DateTime();

// Fetch all price ranges for services
$price_ranges_map = [];
$price_stmt = $pdo->prepare("SELECT service_id, price_range, price FROM service_price_ranges ORDER BY service_id, price_range");
$price_stmt->execute();
$price_ranges = $price_stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($price_ranges as $pr) {
    $price_ranges_map[$pr['service_id']][] = [
        'price_range' => $pr['price_range'],
        'price' => $pr['price']
    ];
}

foreach ($all_services as $service) {
    $service_name = $service['service_name'];
    $service_id = $service['service_id'];
    $stmt = $pdo->prepare("SELECT active_ingredient, stocks_used FROM service_inventory WHERE service_name = ?");
    $stmt->execute([$service_name]);
    $requirements = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $has_sufficient_stock = true;
    $unavailable_reasons = [];
    foreach ($requirements as $req) {
        $check = $pdo->prepare("SELECT stocks, expiry_date FROM inventory WHERE LOWER(active_ingredient) = LOWER(?)");
        $check->execute([$req['active_ingredient']]);
        $inventory_item = $check->fetch(PDO::FETCH_ASSOC);
        if ($inventory_item === false) {
            $has_sufficient_stock = false;
            $unavailable_reasons[] = "{$req['active_ingredient']}: Not found in inventory";
        } elseif ($inventory_item['stocks'] < $req['stocks_used']) {
            $has_sufficient_stock = false;
            $unavailable_reasons[] = "{$req['active_ingredient']}: Available={$inventory_item['stocks']}, Required={$req['stocks_used']}";
        } elseif (!empty($inventory_item['expiry_date'])) {
            $expiry = new DateTime($inventory_item['expiry_date']);
            if ($expiry < $now) {
                $has_sufficient_stock = false;
                $unavailable_reasons[] = "{$req['active_ingredient']}: Expired on {$inventory_item['expiry_date']}";
            }
        }
    }
    $services_status[] = [
        'name' => $service_name,
        'service_id' => $service_id,
        'available' => $has_sufficient_stock,
        'reasons' => $unavailable_reasons
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pest Control Booking - Professional Service</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #0f4c3a 0%, #1a5f3f 25%, #2d7a47 50%, #22c55e 75%, #16a34a 100%);
            background-size: 400% 400%;
            animation: gradientShift 15s ease infinite;
            min-height: 100vh;
            color: #1f2937;
            overflow-x: hidden;
        }

        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem 1.5rem;
            position: relative;
            z-index: 1;
        }

        .hero-section {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
            padding: 4rem 3rem;
            border-radius: 32px;
            margin-bottom: 3rem;
            text-align: center;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.2);
            position: relative;
            overflow: hidden;
            transform: translateY(0);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .hero-section:hover {
            transform: translateY(-10px);
            box-shadow: 0 35px 70px rgba(0, 0, 0, 0.3);
        }

        .hero-section::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
            animation: float 8s ease-in-out infinite;
        }

        .hero-section::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(45deg, transparent 30%, rgba(255, 255, 255, 0.1) 50%, transparent 70%);
            animation: shimmer 3s ease-in-out infinite;
        }

        .hero-title {
            font-size: 3.5rem;
            font-weight: 900;
            margin-bottom: 1.5rem;
            text-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);
            position: relative;
            z-index: 2;
            background: linear-gradient(45deg, #fff, #f0f9ff, #fff);
            background-size: 200% 200%;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            animation: textShimmer 3s ease-in-out infinite;
        }

        .hero-subtitle {
            font-size: 1.4rem;
            opacity: 0.95;
            margin-bottom: 2.5rem;
            position: relative;
            z-index: 2;
            font-weight: 300;
            letter-spacing: 0.5px;
        }

        .card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            margin-bottom: 3rem;
            transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid rgba(255, 255, 255, 0.3);
            position: relative;
        }

        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #0f4c3a, #1a5f3f, #2d7a47, #22c55e, #16a34a);
            background-size: 300% 100%;
            animation: gradientMove 3s ease infinite;
        }

        .card:hover {
            transform: translateY(-12px) scale(1.02);
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.2);
        }

        .card-header {
            background: linear-gradient(135deg, #0f4c3a 0%, #1a5f3f 100%);
            color: #ffffff;
            padding: 2.5rem;
            font-size: 2rem;
            font-weight: 800;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .card-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.8s;
        }

        .card:hover .card-header::before {
            left: 100%;
        }

        .card-body {
            padding: 3rem;
            background: rgba(255, 255, 255, 0.95);
        }

        .form-group {
            margin-bottom: 2rem;
            position: relative;
        }

        .form-label {
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 1rem;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            position: relative;
        }

        .form-label::before {
            content: '';
            width: 6px;
            height: 24px;
            background: linear-gradient(135deg, #0f4c3a, #1a5f3f);
            border-radius: 3px;
            box-shadow: 0 2px 4px rgba(15, 76, 58, 0.3);
        }

        .form-control, .form-select {
            border: 2px solid #e5e7eb;
            border-radius: 16px;
            padding: 1.25rem;
            font-size: 1.1rem;
            background: rgba(249, 250, 251, 0.8);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            width: 100%;
            position: relative;
        }

        .form-control:focus, .form-select:focus {
            border-color: #0f4c3a;
            box-shadow: 0 0 0 6px rgba(15, 76, 58, 0.15);
            outline: none;
            background: rgba(255, 255, 255, 0.95);
            transform: translateY(-3px);
        }

        .form-control:hover, .form-select:hover {
            border-color: #d1d5db;
            background: rgba(255, 255, 255, 0.9);
            transform: translateY(-1px);
        }

        .form-control:disabled, .form-select:disabled {
            background: rgba(229, 231, 235, 0.8);
            cursor: not-allowed;
            opacity: 0.7;
        }

        .btn {
            padding: 1.25rem 3rem;
            border-radius: 16px;
            font-weight: 700;
            font-size: 1.1rem;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
            position: relative;
            overflow: hidden;
            border: none;
            text-transform: uppercase;
            letter-spacing: 1px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.6s;
        }

        .btn:hover::before {
            left: 100%;
        }

        .btn-primary {
            background: linear-gradient(135deg, #0f4c3a 0%, #1a5f3f 100%);
            color: #ffffff;
            box-shadow: 0 8px 25px rgba(15, 76, 58, 0.4);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #1a5f3f 0%, #2d7a47 100%);
            transform: translateY(-4px);
            box-shadow: 0 15px 35px rgba(15, 76, 58, 0.6);
        }

        .btn-primary:active {
            transform: translateY(-2px);
        }

        .btn-danger {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
            color: #ffffff;
            box-shadow: 0 8px 25px rgba(220, 38, 38, 0.4);
        }

        .btn-danger:hover {
            background: linear-gradient(135deg, #b91c1c 0%, #991b1b 100%);
            transform: translateY(-4px);
            box-shadow: 0 15px 35px rgba(220, 38, 38, 0.6);
        }

        .btn-success {
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            color: #ffffff;
            box-shadow: 0 8px 25px rgba(34, 197, 94, 0.4);
        }

        .btn-success:hover {
            background: linear-gradient(135deg, #16a34a 0%, #15803d 100%);
            transform: translateY(-4px);
            box-shadow: 0 15px 35px rgba(34, 197, 94, 0.6);
        }

        .btn-outline-danger {
            border: 3px solid #dc2626;
            color: #dc2626;
            background: rgba(255, 255, 255, 0.9);
            text-transform: none;
            letter-spacing: normal;
            font-weight: 600;
        }

        .btn-outline-danger:hover {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
            color: white;
            transform: translateY(-4px);
            box-shadow: 0 15px 35px rgba(220, 38, 38, 0.4);
        }

        .btn-history {
            background: linear-gradient(135deg, #2d7a47 0%, #22c55e 100%);
            color: #ffffff;
            box-shadow: 0 8px 25px rgba(45, 122, 71, 0.4);
            margin-bottom: 2rem;
        }

        .btn-history:hover {
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            transform: translateY(-4px);
            box-shadow: 0 15px 35px rgba(45, 122, 71, 0.6);
        }

        .confirmation-modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(10px);
            animation: fadeIn 0.4s ease;
        }

        .confirmation-modal-content {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(20px);
            margin: 3% auto;
            padding: 0;
            border-radius: 24px;
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow: hidden;
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.4);
            animation: slideInDown 0.5s ease;
            position: relative;
            display: flex;
            flex-direction: column;
        }

        .confirmation-modal-content::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 6px;
            background: linear-gradient(90deg, #0f4c3a, #1a5f3f, #2d7a47, #22c55e, #16a34a);
            background-size: 300% 100%;
            animation: gradientMove 3s ease infinite;
        }

        .confirmation-modal-header {
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            color: white;
            padding: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
            flex-shrink: 0;
        }

        .confirmation-modal-header h2 {
            font-size: 1.8rem;
            font-weight: 800;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .confirmation-modal-body {
            padding: 2.5rem;
            flex: 1;
            overflow-y: auto;
        }

        .booking-summary {
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 2rem;
            border: 2px solid #bbf7d0;
        }

        .booking-summary h3 {
            color: #0f4c3a;
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .booking-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
        }

        .detail-item {
            background: rgba(255, 255, 255, 0.8);
            padding: 1rem;
            border-radius: 12px;
            border-left: 4px solid #22c55e;
        }

        .detail-label {
            font-weight: 600;
            color: #0f4c3a;
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }

        .detail-value {
            color: #1f2937;
            font-size: 1rem;
            font-weight: 500;
        }

        .confirmation-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-top: 2rem;
        }

        .btn-confirm {
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            color: #ffffff;
            padding: 1rem 2rem;
            border-radius: 12px;
            font-weight: 600;
            font-size: 1rem;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(34, 197, 94, 0.3);
        }

        .btn-confirm:hover {
            background: linear-gradient(135deg, #16a34a 0%, #15803d 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(34, 197, 94, 0.4);
        }

        .btn-download {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: #ffffff;
            padding: 1rem 2rem;
            border-radius: 12px;
            font-weight: 600;
            font-size: 1rem;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);
        }

        .btn-download:hover {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(59, 130, 246, 0.4);
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(5px);
            animation: fadeIn 0.3s ease;
        }

        .modal-content {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            margin: 1% auto;
            padding: 0;
            border-radius: 20px;
            width: 98%;
            max-width: 1400px;
            max-height: 98vh;
            overflow: hidden;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.3);
            animation: slideInDown 0.4s ease;
            position: relative;
            display: flex;
            flex-direction: column;
        }

        .modal-content::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #0f4c3a, #1a5f3f, #2d7a47, #22c55e, #16a34a);
            background-size: 300% 100%;
            animation: gradientMove 3s ease infinite;
        }

        .modal-header {
            background: linear-gradient(135deg, #0f4c3a 0%, #1a5f3f 100%);
            color: white;
            padding: 1.5rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
            flex-shrink: 0;
        }

        .modal-header h2 {
            font-size: 1.5rem;
            font-weight: 800;
            margin: 0;
        }

        .close {
            color: white;
            font-size: 1.8rem;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 35px;
            height: 35px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
        }

        .close:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 1.5rem;
            flex: 1;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .table-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 16px;
            overflow: auto;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
            flex: 1;
            position: relative;
        }

        .table-container::-webkit-scrollbar {
            height: 12px;
            width: 12px;
        }

        .table-container::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 6px;
        }

        .table-container::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #0f4c3a, #1a5f3f);
            border-radius: 6px;
            border: 2px solid #f1f5f9;
        }

        .table-container::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #1a5f3f, #2d7a47);
        }

        .table-container::-webkit-scrollbar-corner {
            background: #f1f5f9;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1200px;
        }

        .table th {
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            color: #0f4c3a;
            font-weight: 800;
            padding: 1rem;
            font-size: 0.9rem;
            text-align: left;
            border-bottom: 3px solid #dcfce7;
            position: relative;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .table th::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, #0f4c3a, #1a5f3f, #2d7a47, #22c55e, #16a34a);
            background-size: 300% 100%;
            animation: gradientMove 3s ease infinite;
        }

        .table td {
            padding: 1rem;
            vertical-align: middle;
            font-size: 0.85rem;
            border-bottom: 1px solid #f1f5f9;
            transition: all 0.3s ease;
            white-space: nowrap;
        }

        .table tbody tr:hover {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
        }

        .table tbody tr:last-child td {
            border-bottom: none;
        }

        .badge {
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }

        .action-buttons {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            min-width: 120px;
        }

        .btn-small {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
            border-radius: 8px;
            font-weight: 600;
            text-transform: none;
            letter-spacing: normal;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }

        .btn-small:hover {
            transform: translateY(-2px);
        }

        .structure-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }

        .structure-option {
            position: relative;
            cursor: pointer;
        }

        .structure-option input[type="radio"] {
            position: absolute;
            opacity: 0;
            cursor: pointer;
        }

        .structure-option-label {
            display: block;
            padding: 1.5rem;
            background: rgba(249, 250, 251, 0.8);
            border: 3px solid #e5e7eb;
            border-radius: 16px;
            text-align: center;
            font-weight: 600;
            font-size: 1.1rem;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }

        .structure-option-label::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(15, 76, 58, 0.1), transparent);
            transition: left 0.5s;
        }

        .structure-option input[type="radio"]:checked + .structure-option-label {
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            border-color: #0f4c3a;
            color: #0f4c3a;
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(15, 76, 58, 0.3);
        }

        .structure-option input[type="radio"]:checked + .structure-option-label::before {
            left: 100%;
        }

        .structure-option:hover .structure-option-label {
            border-color: #d1d5db;
            background: rgba(255, 255, 255, 0.9);
            transform: translateY(-2px);
        }

        .alert {
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2.5rem;
            animation: slideInDown 0.8s cubic-bezier(0.4, 0, 0.2, 1);
            font-size: 1.1rem;
            line-height: 1.7;
            position: relative;
            overflow: hidden;
            backdrop-filter: blur(10px);
            border: 2px solid rgba(255, 255, 255, 0.3);
        }

        .alert::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            width: 6px;
            height: 100%;
            background: currentColor;
            opacity: 0.4;
        }

        .alert-success {
            background: linear-gradient(135deg, rgba(34, 197, 94, 0.1) 0%, rgba(22, 163, 74, 0.1) 100%);
            color: #065f46;
            border-color: rgba(34, 197, 94, 0.3);
        }

        .alert-error {
            background: linear-gradient(135deg, rgba(220, 38, 38, 0.1) 0%, rgba(185, 28, 28, 0.1) 100%);
            color: #991b1b;
            border-color: rgba(220, 38, 38, 0.3);
        }

        .alert-warning {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1) 0%, rgba(217, 119, 6, 0.1) 100%);
            color: #92400e;
            border-color: rgba(245, 158, 11, 0.3);
        }

        .alert-info {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.1) 0%, rgba(37, 99, 235, 0.1) 100%);
            color: #1e40af;
            border-color: rgba(59, 130, 246, 0.3);
        }

        #serviceDetails .card {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            padding: 2.5rem;
            border-radius: 20px;
            margin-top: 2.5rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            border: 2px solid #e2e8f0;
        }

        .loading {
            position: relative;
            overflow: hidden;
        }

        .loading::after {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.6), transparent);
            animation: loading 2s infinite;
        }

        .fade-in {
            animation: fadeIn 0.8s cubic-bezier(0.4, 0, 0.2, 1);
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes slideInDown {
            from { opacity: 0; transform: translateY(-30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes loading {
            0% { left: -100%; }
            100% { left: 100%; }
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(5deg); }
        }

        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        @keyframes textShimmer {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        @keyframes gradientMove {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        .particles {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 0;
        }

        .particle {
            position: absolute;
            width: 4px;
            height: 4px;
            background: rgba(255, 255, 255, 0.6);
            border-radius: 50%;
            animation: particleFloat 20s linear infinite;
        }

        @keyframes particleFloat {
            0% {
                transform: translateY(100vh) translateX(0);
                opacity: 0;
            }
            10% {
                opacity: 1;
            }
            90% {
                opacity: 1;
            }
            100% {
                transform: translateY(-100vh) translateX(100px);
                opacity: 0;
            }
        }

        @media (max-width: 1024px) {
            .container {
                padding: 1.5rem;
            }
            .card-body {
                padding: 2rem;
            }
            .card-header {
                font-size: 1.5rem;
                padding: 1.5rem;
            }
            .hero-title {
                font-size: 2.5rem;
            }
            .hero-subtitle {
                font-size: 1.1rem;
            }
            .modal-content {
                width: 98%;
                margin: 1% auto;
            }
            .table th, .table td {
                padding: 0.75rem;
                font-size: 0.8rem;
            }
            .confirmation-modal-content {
                width: 95%;
                margin: 2% auto;
            }
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }
            .card-body {
                padding: 1.5rem;
            }
            .card-header {
                font-size: 1.25rem;
                padding: 1rem;
            }
            .hero-section {
                padding: 2rem 1rem;
            }
            .hero-title {
                font-size: 2rem;
            }
            .structure-options {
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            }
            .table-container {
                overflow-x: auto;
            }
            .btn {
                padding: 1rem 2rem;
                font-size: 1rem;
            }
            .modal-body {
                padding: 1rem;
            }
            .table th, .table td {
                padding: 0.5rem;
                font-size: 0.75rem;
            }
            .action-buttons {
                min-width: 100px;
            }
            .btn-small {
                padding: 0.4rem 0.8rem;
                font-size: 0.7rem;
            }
            .confirmation-modal-content {
                width: 98%;
                margin: 1% auto;
            }
            .confirmation-modal-body {
                padding: 1.5rem;
            }
            .booking-details {
                grid-template-columns: 1fr;
            }
            .confirmation-actions {
                flex-direction: column;
            }
        }

        @media (max-width: 480px) {
            .hero-section {
                padding: 1.5rem 1rem;
            }
            .hero-title {
                font-size: 1.75rem;
            }
            .structure-options {
                grid-template-columns: 1fr;
            }
            .btn {
                padding: 0.875rem 1.5rem;
                font-size: 0.9rem;
            }
            .modal-content {
                width: 100%;
                height: 100%;
                margin: 0;
                border-radius: 0;
            }
            .modal-body {
                padding: 0.5rem;
            }
            .table th, .table td {
                padding: 0.4rem;
                font-size: 0.7rem;
            }
            .action-buttons {
                min-width: 80px;
            }
            .btn-small {
                padding: 0.3rem 0.6rem;
                font-size: 0.65rem;
            }
            .confirmation-modal-content {
                width: 100%;
                height: 100%;
                margin: 0;
                border-radius: 0;
            }
            .confirmation-modal-body {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <!-- Floating Particles -->
    <div class="particles" id="particles"></div>

    <div class="container">
        <!-- Hero Section -->
        <div class="hero-section fade-in">
            <h1 class="hero-title">
                <i class="fas fa-shield-alt"></i> Welcome, <?= htmlspecialchars($fullName) ?>
            </h1>
            <p class="hero-subtitle">Experience premium pest control services with professional care and guaranteed results</p>
            <form action="logout.php" method="post">
                <button type="submit" class="btn btn-outline-danger">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </button>
            </form>
        </div>

        <!-- Booking History Button -->
        <?php if (!empty($bookingHistory)): ?>
            <div class="text-center mb-6">
                <button onclick="openBookingHistoryModal()" class="btn btn-history">
                    <i class="fas fa-history"></i> View Booking History
                </button>
            </div>
        <?php endif; ?>

        <!-- Booking Form Card -->
        <div class="card fade-in">
            <div class="card-header">
                <i class="fas fa-calendar-plus"></i> Book Your Service
            </div>
            <div class="card-body">
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-triangle"></i>
                        <?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                    </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?= htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                    </div>
                <?php endif; ?>

                <?php
                $has_available_services = false;
                foreach ($services_status as $service) {
                    if ($service['available']) {
                        $has_available_services = true;
                        break;
                    }
                }
                if (!$has_available_services):
                ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-circle"></i>
                        No services are currently available due to insufficient inventory or expired items. Please contact support at support@pestcontrol.com for assistance.
                        <?php if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin'): ?>
                            <br><strong>Debug Info:</strong>
                            <?php foreach ($services_status as $service): ?>
                                <?php if (!empty($service['reasons'])): ?>
                                    <br><?= htmlspecialchars($service['name']) ?>: <?= htmlspecialchars(implode("; ", $service['reasons'])) ?>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-8" id="bookingForm">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-list-alt"></i> Service Selection
                            </label>
                            <select name="service_type" class="form-select" required onchange="fetchServiceDetails(this.value)">
                                <option value="">-- Choose Your Service --</option>
                                <?php
                                foreach ($services_status as $service) {
                                    $label = $service['name'];
                                    if (!$service['available']) {
                                        $label .= ' (Currently Unavailable)';
                                    }
                                    $disabled = $service['available'] ? '' : 'disabled';
                                    echo "<option value='" . htmlspecialchars($service['name']) . "' $disabled>" . htmlspecialchars($label) . "</option>";
                                }
                                ?>
                            </select>
                            <?php if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin'): ?>
                                <small class="text-gray-500 mt-2 block">
                                    <i class="fas fa-info-circle"></i>
                                    Unavailable services are marked due to insufficient stock or expired items. Check inventory at <a href='inventory.php?view=all' class='text-blue-500 hover:underline'>Inventory Management</a>.
                                </small>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-phone"></i> Contact Number
                            </label>
                            <input type="text" name="phone_number" class="form-control" required pattern="[0-9]{10,15}"
                                   title="Enter a valid phone number (10-15 digits)" placeholder="Enter your phone number">
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-map-marker-alt"></i> Service Address
                            </label>
                            <input type="text" name="address" class="form-control" required
                                   placeholder="Enter your complete address">
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-calendar"></i> Preferred Date
                            </label>
                            <input type="date" name="appointment_date" class="form-control" required
                                   min="<?= date('Y-m-d'); ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-clock"></i> Preferred Time
                            </label>
                            <select name="appointment_time" class="form-select" required>
                                <option value="">-- Select Time Slot --</option>
                                <?php
                                for ($hour = 6; $hour <= 23; $hour++) {
                                    $formatted = date("g:i A", strtotime("$hour:00"));
                                    echo "<option value='$formatted'>$formatted</option>";
                                    if ($hour < 23) {
                                        $formatted = date("g:i A", strtotime("$hour:30"));
                                        echo "<option value='$formatted'>$formatted</option>";
                                    }
                                }
                                ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-dollar-sign"></i> Price Range
                            </label>
                            <select name="price_range" id="price_range_select" class="form-select" required>
                                <option value="">-- Select Price Range --</option>
                            </select>
                            <div id="price_range_message" class="text-sm text-gray-500 mt-2"></div>
                        </div>

                        <div class="col-span-1 md:col-span-2">
                            <label class="form-label">
                                <i class="fas fa-building"></i> Property Type <span class="text-red-500">*</span>
                            </label>
                            <div class="structure-options">
                                <?php
                                $options = ['Residential', 'Commercial', 'Restaurant', 'Plant', 'Warehouse', 'Building', 'Bank', 'School'];
                                foreach ($options as $opt) {
                                    echo '<div class="structure-option">
                                            <input type="radio" name="structure_type" value="' . htmlspecialchars($opt) . '" id="' . htmlspecialchars($opt) . '">
                                            <label class="structure-option-label" for="' . htmlspecialchars($opt) . '">' . htmlspecialchars($opt) . '</label>
                                          </div>';
                                }
                                ?>
                                <div class="structure-option">
                                    <input type="radio" id="otherCheckbox" name="structure_type" value="Other">
                                    <label class="structure-option-label" for="otherCheckbox">Other</label>
                                </div>
                            </div>
                            <div class="mt-6" id="otherInputDiv" style="display: none;">
                                <input type="text" class="form-control" name="structure_type_other" id="structureTypeOther"
                                       placeholder="Please specify the property type">
                            </div>
                        </div>

                        <div class="col-span-1 md:col-span-2 flex justify-center">
                            <button type="submit" name="submit_booking" class="btn btn-primary">
                                <i class="fas fa-paper-plane"></i> Submit Booking Request
                            </button>
                        </div>
                    </form>
                <?php endif; ?>

                <div id="serviceDetails"></div>
            </div>
        </div>

        <?php if (empty($bookingHistory)): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i>
                You have no previous bookings. Start by booking your first service above!
            </div>
        <?php endif; ?>
    </div>

    <!-- Booking Confirmation Modal -->
    <?php if ($bookingConfirmation): ?>
        <div id="bookingConfirmationModal" class="confirmation-modal" style="display: block;">
            <div class="confirmation-modal-content">
                <div class="confirmation-modal-header">
                    <h2>
                        <i class="fas fa-check-circle"></i> Booking Confirmed!
                    </h2>
                </div>
                <div class="confirmation-modal-body">
                    <div class="booking-summary">
                        <h3>
                            <i class="fas fa-receipt"></i> Booking Summary
                        </h3>
                        <div class="booking-details">
                            <div class="detail-item">
                                <div class="detail-label">Reference Code</div>
                                <div class="detail-value"><?= htmlspecialchars($bookingConfirmation['reference_code']) ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Customer Name</div>
                                <div class="detail-value"><?= htmlspecialchars($bookingConfirmation['customer_name']) ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Service Name</div>
                                <div class="detail-value"><?= htmlspecialchars($bookingConfirmation['service_name']) ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Service Type</div>
                                <div class="detail-value"><?= htmlspecialchars($bookingConfirmation['service_type']) ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Contact Number</div>
                                <div class="detail-value"><?= htmlspecialchars($bookingConfirmation['phone_number']) ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Service Address</div>
                                <div class="detail-value"><?= htmlspecialchars($bookingConfirmation['address']) ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Appointment Date</div>
                                <div class="detail-value"><?= htmlspecialchars($bookingConfirmation['appointment_date']) ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Appointment Time</div>
                                <div class="detail-value"><?= htmlspecialchars($bookingConfirmation['appointment_time']) ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Property Type</div>
                                <div class="detail-value"><?= htmlspecialchars($bookingConfirmation['structure_type']) ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Price Range</div>
                                <div class="detail-value"><?= htmlspecialchars($bookingConfirmation['price_range']) ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Status</div>
                                <div class="detail-value">
                                    <span class="badge bg-yellow-100 text-yellow-800">
                                        <i class="fas fa-clock"></i> <?= htmlspecialchars($bookingConfirmation['status']) ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="confirmation-actions">
                        <button onclick="downloadReceipt('<?= htmlspecialchars($bookingConfirmation['reference_code']) ?>')" class="btn-download">
                            <i class="fas fa-download"></i> Download Receipt
                        </button>
                        <button onclick="closeConfirmationModal()" class="btn-confirm">
                            <i class="fas fa-check"></i> Continue
                        </button>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Booking History Modal -->
    <div id="bookingHistoryModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-history"></i> Your Booking History</h2>
                <span class="close" onclick="closeBookingHistoryModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th><i class="fas fa-hashtag"></i> Reference</th>
                                <th><i class="fas fa-bug"></i> Service</th>
                                <th><i class="fas fa-tag"></i> Type</th>
                                <th><i class="fas fa-map-marker-alt"></i> Location</th>
                                <th><i class="fas fa-calendar"></i> Date</th>
                                <th><i class="fas fa-clock"></i> Time</th>
                                <th><i class="fas fa-building"></i> Property</th>
                                <th><i class="fas fa-dollar-sign"></i> Price</th>
                                <th><i class="fas fa-info-circle"></i> Status</th>
                                <th><i class="fas fa-cogs"></i> Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bookingHistory as $booking): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($booking['reference_code']) ?></strong></td>
                                    <td><?= htmlspecialchars($booking['service_name']) ?></td>
                                    <td><?= htmlspecialchars($booking['service_type']) ?></td>
                                    <td><?= htmlspecialchars($booking['address']) ?></td>
                                    <td><?= htmlspecialchars($booking['appointment_date']) ?></td>
                                    <td><?= htmlspecialchars($booking['appointment_time']) ?></td>
                                    <td><?= htmlspecialchars($booking['structure_type'] ?: 'N/A') ?></td>
                                    <td><?= htmlspecialchars($booking['price_range']) ?></td>
                                    <td>
                                        <span class="badge
                                            <?= $booking['status'] === 'Pending' ? 'bg-yellow-100 text-yellow-800' :
                                                ($booking['status'] === 'Completed' ? 'bg-green-100 text-green-800' :
                                                ($booking['status'] === 'Cancelled' ? 'bg-red-100 text-red-800' : 'bg-gray-100 text-gray-800')) ?>">
                                            <?php if ($booking['status'] === 'Pending'): ?>
                                                <i class="fas fa-clock"></i>
                                            <?php elseif ($booking['status'] === 'Completed'): ?>
                                                <i class="fas fa-check"></i>
                                            <?php elseif ($booking['status'] === 'Cancelled'): ?>
                                                <i class="fas fa-times"></i>
                                            <?php else: ?>
                                                <i class="fas fa-question"></i>
                                            <?php endif; ?>
                                            <?= htmlspecialchars($booking['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if ($booking['status'] === 'Pending'): ?>
                                                <form method="post" onsubmit="return confirm('Are you sure you want to cancel this booking?');">
                                                    <input type="hidden" name="reference_code" value="<?= htmlspecialchars($booking['reference_code']) ?>">
                                                    <button type="submit" name="cancel_booking" class="btn btn-outline-danger btn-small">
                                                        <i class="fas fa-times"></i> Cancel
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            <form method="post">
                                                <input type="hidden" name="reference_code" value="<?= htmlspecialchars($booking['reference_code']) ?>">
                                                <button type="submit" name="download_receipt" class="btn btn-success btn-small">
                                                    <i class="fas fa-download"></i> Receipt
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Pass price ranges to JavaScript
        const priceRangesMap = <?php echo json_encode($price_ranges_map); ?>;
        const serviceIdMap = <?php
            $service_id_map = [];
            foreach ($services_status as $service) {
                $service_id_map[$service['name']] = $service['service_id'];
            }
            echo json_encode($service_id_map);
        ?>;

        // Create floating particles
        function createParticles() {
            const particlesContainer = document.getElementById('particles');
            const particleCount = 30;
            for (let i = 0; i < particleCount; i++) {
                const particle = document.createElement('div');
                particle.className = 'particle';
                particle.style.left = `${Math.random() * 100}vw`;
                particle.style.animationDuration = `${15 + Math.random() * 10}s`;
                particle.style.animationDelay = `${Math.random() * 5}s`;
                particle.style.opacity = `${0.2 + Math.random() * 0.4}`;
                particlesContainer.appendChild(particle);
            }
        }

        // Initialize particles
        document.addEventListener('DOMContentLoaded', createParticles);

        // Fetch service details via AJAX
        function fetchServiceDetails(serviceName) {
            console.log('Fetching service details for:', serviceName);

            if (!serviceName) {
                document.getElementById('serviceDetails').innerHTML = '';
                document.getElementById('price_range_select').innerHTML = '<option value="">-- Select Price Range --</option>';
                document.getElementById('price_range_message').innerHTML = '';
                return;
            }

            document.getElementById('serviceDetails').classList.add('loading');
            document.getElementById('price_range_select').innerHTML = '<option value="">-- Loading... --</option>';
            document.getElementById('price_range_message').innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading pricing information...';

            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'booking_form.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onreadystatechange = function () {
                if (xhr.readyState === 4) {
                    document.getElementById('serviceDetails').classList.remove('loading');
                    console.log('XHR Status:', xhr.status);
                    console.log('XHR Response:', xhr.responseText);

                    if (xhr.status === 200) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            console.log('Parsed response:', response);

                            if (response.error) {
                                document.getElementById('serviceDetails').innerHTML = `
                                    <div class="alert alert-error">
                                        <i class="fas fa-exclamation-triangle"></i> ${response.error}
                                    </div>`;
                                document.getElementById('price_range_select').innerHTML = '<option value="">-- Select Price Range --</option>';
                                document.getElementById('price_range_message').innerHTML = '<i class="fas fa-exclamation-circle"></i> Unable to load pricing information.';
                                return;
                            }

                            // Display service details
                            document.getElementById('serviceDetails').innerHTML = `
                                <div class="card">
                                    <h3 class="text-lg font-bold mb-4"><i class="fas fa-info-circle"></i> Service Details</h3>
                                    <p><strong>Name:</strong> ${response.service_name}</p>
                                    <p><strong>Type:</strong> ${response.service_type}</p>
                                    <p><strong>Details:</strong> ${response.service_details || 'No additional details available'}</p>
                                </div>`;

                            // Populate price range dropdown from response
                            const priceSelect = document.getElementById('price_range_select');
                            priceSelect.innerHTML = '<option value="">-- Select Price Range --</option>';

                            if (response.price_ranges && response.price_ranges.length > 0) {
                                console.log('Price ranges found:', response.price_ranges.length);
                                response.price_ranges.forEach(range => {
                                    const option = document.createElement('option');
                                    const formattedPrice = parseFloat(range.price).toLocaleString('en-US');
                                    option.value = `${range.price_range} SQM = ${formattedPrice} KES`;
                                    option.textContent = `${range.price_range} SQM - ${formattedPrice} KES`;
                                    priceSelect.appendChild(option);
                                });
                                document.getElementById('price_range_message').innerHTML = `
                                    <i class="fas fa-info-circle"></i> Select the area size that best matches your property.`;
                            } else {
                                console.warn('No price ranges available');
                                document.getElementById('price_range_message').innerHTML = `
                                    <i class="fas fa-exclamation-circle"></i> No pricing available for this service. Please add prices in the admin panel or contact support.`;
                                priceSelect.disabled = true;
                            }
                        } catch (e) {
                            console.error('Error parsing AJAX response:', e);
                            console.error('Response text:', xhr.responseText);
                            document.getElementById('serviceDetails').innerHTML = `
                                <div class="alert alert-error">
                                    <i class="fas fa-exclamation-triangle"></i> Error processing service details. Check console for details.
                                </div>`;
                            document.getElementById('price_range_select').innerHTML = '<option value="">-- Select Price Range --</option>';
                            document.getElementById('price_range_message').innerHTML = `
                                <i class="fas fa-exclamation-circle"></i> Failed to load pricing information.`;
                        }
                    } else {
                        console.error('AJAX request failed with status:', xhr.status);
                        document.getElementById('serviceDetails').innerHTML = `
                            <div class="alert alert-error">
                                <i class="fas fa-exclamation-triangle"></i> Failed to fetch service details (Status: ${xhr.status}).
                            </div>`;
                        document.getElementById('price_range_select').innerHTML = '<option value="">-- Select Price Range --</option>';
                        document.getElementById('price_range_message').innerHTML = `
                            <i class="fas fa-exclamation-circle"></i> Unable to load pricing information.`;
                    }
                }
            };
            xhr.onerror = function () {
                document.getElementById('serviceDetails').classList.remove('loading');
                console.error('AJAX request error');
                document.getElementById('serviceDetails').innerHTML = `
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-triangle"></i> Network error while fetching service details.
                    </div>`;
                document.getElementById('price_range_select').innerHTML = '<option value="">-- Select Price Range --</option>';
                document.getElementById('price_range_message').innerHTML = `
                    <i class="fas fa-exclamation-circle"></i> Unable to load pricing information due to a network error.`;
            };
            xhr.send(`action=get_service_details&service_name=${encodeURIComponent(serviceName)}`);
        }

        // Open booking history modal
        function openBookingHistoryModal() {
            document.getElementById('bookingHistoryModal').style.display = 'block';
        }

        // Close booking history modal
        function closeBookingHistoryModal() {
            document.getElementById('bookingHistoryModal').style.display = 'none';
        }

        // Close confirmation modal
        function closeConfirmationModal() {
            document.getElementById('bookingConfirmationModal').style.display = 'none';
            window.location.href = 'booking_form.php'; // Refresh to clear confirmation
        }

        // Download receipt
        function downloadReceipt(referenceCode) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'booking_form.php';
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'reference_code';
            input.value = referenceCode;
            form.appendChild(input);
            const submitInput = document.createElement('input');
            submitInput.type = 'hidden';
            submitInput.name = 'download_receipt';
            submitInput.value = 'true';
            form.appendChild(submitInput);
            document.body.appendChild(form);
            form.submit();
        }

        // Handle structure type 'Other' input
        document.addEventListener('DOMContentLoaded', function () {
            const otherCheckbox = document.getElementById('otherCheckbox');
            const otherInputDiv = document.getElementById('otherInputDiv');
            const structureRadios = document.querySelectorAll('input[name="structure_type"]');

            structureRadios.forEach(radio => {
                radio.addEventListener('change', function () {
                    otherInputDiv.style.display = this.value === 'Other' ? 'block' : 'none';
                    if (this.value === 'Other') {
                        document.getElementById('structureTypeOther').focus();
                    }
                });
            });
        });

        // Close modal when clicking outside
        window.addEventListener('click', function (event) {
            const historyModal = document.getElementById('bookingHistoryModal');
            const confirmationModal = document.getElementById('bookingConfirmationModal');
            if (event.target === historyModal) {
                closeBookingHistoryModal();
            }
            if (event.target === confirmationModal) {
                closeConfirmationModal();
            }
        });
    </script>
</body>
</html>
