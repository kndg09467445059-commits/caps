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

// CRITICAL HELPER: Clean price string
function clean_price($price) {
    return (float)preg_replace('/[^\d.]/', '', $price);
}

// Redirect if not logged in
if (!isset($_SESSION['username']) || $_SESSION['user_type'] !== 'customer' || !isset($_SESSION['id'])) {
    header("Location: customer_login.php");
    exit();
}

// Fetch user full name
$stmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
$stmt->execute([$_SESSION['id']]);
$userData = $stmt->fetch(PDO::FETCH_ASSOC);
$fullName = $userData ? $userData['first_name'] . ' ' . $userData['last_name'] : $_SESSION['username'];

// Determine current rotation period and ingredient
$month_names = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
$start_month = (int)date('n');
$start_year = (int)date('Y');
$rotation_period = floor(($start_month - 1) / 3);
$current_period = $month_names[($rotation_period * 3) % 12] . " $start_year - " .
                 $month_names[($rotation_period * 3 + 2) % 12] . " " . ($start_year + floor(($rotation_period * 3 + 2) / 12));
$stmt = $pdo->query("SELECT active_ingredient FROM inventory ORDER BY active_ingredient");
$ingredients = $stmt->fetchAll(PDO::FETCH_ASSOC);
$ingredient_count = count($ingredients);
$current_ingredient_index = $rotation_period % $ingredient_count;
$current_rotated_ingredient = $ingredient_count > 0 ? $ingredients[$current_ingredient_index]['active_ingredient'] : null;

// Handle AJAX service detail request
if (isset($_POST['action']) && $_POST['action'] === 'get_service_details' && isset($_POST['service_name'])) {
    $stmt = $pdo->prepare("
        SELECT s.service_id, s.service_name, s.service_type, s.service_details,
               GROUP_CONCAT(si.active_ingredient) as active_ingredients
        FROM services s
        LEFT JOIN service_inventory si ON s.service_name = si.service_name
        WHERE s.service_name = ?
        GROUP BY s.service_id
    ");
    $stmt->execute([$_POST['service_name']]);
    $service = $stmt->fetch(PDO::FETCH_ASSOC);

    $response = [];
    if ($service) {
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
            'active_ingredients' => $service['active_ingredients'] ? explode(',', $service['active_ingredients']) : [],
            'price_ranges' => $price_ranges,
            'is_rotated' => $current_rotated_ingredient && in_array(strtolower($current_rotated_ingredient), array_map('strtolower', explode(',', $service['active_ingredients'] ?? '')))
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

        $stmt = $pdo->prepare("SELECT service_name FROM service_bookings WHERE reference_code = ? AND id = ?");
        $stmt->execute([$ref, $_SESSION['id']]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$booking) {
            $pdo->rollBack();
            $_SESSION['error'] = "Booking not found or you don't have permission to cancel it.";
            header("Location: booking_form.php");
            exit();
        }

        $cancel = $pdo->prepare("UPDATE service_bookings SET status = 'Cancelled' WHERE reference_code = ? AND id = ?");
        $cancel->execute([$ref, $_SESSION['id']]);

        $stmt = $pdo->prepare("SELECT active_ingredient, stocks_used FROM service_inventory WHERE service_name = ?");
        $stmt->execute([$booking['service_name']]);
        $requirements = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

// === FIXED: ONLY ONE DOWNLOAD RECEIPT BLOCK ===
// (The broken duplicate was REMOVED — this is the ONLY one)
if (isset($_POST['download_receipt']) && isset($_POST['reference_code'])) {
    $ref = $_POST['reference_code'];
    $stmt = $pdo->prepare("
        SELECT sb.reference_code, sb.service_name, sb.service_type, sb.appointment_date, sb.appointment_time,
               sb.price_range, sb.structure_type, sb.status, sb.customer_name, sb.address, sb.phone_number
        FROM service_bookings sb
        WHERE sb.reference_code = ? AND sb.id = ?
    ");
    $stmt->execute([$ref, $_SESSION['id']]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking || in_array($booking['status'], ['Cancelled', 'Completed'])) {
        $_SESSION['error'] = "Invalid booking or access denied.";
        header("Location: booking_form.php");
        exit();
    }

    // FORMAT PHONE: 09123456789 → 0912 345 6789
    $raw_phone = $booking['phone_number'];
    $formatted_phone = '0' . substr($raw_phone, 0, 3) . ' ' . substr($raw_phone, 3, 3) . ' ' . substr($raw_phone, 6, 4);

    $address_parts = explode(', ', $booking['address']);
    $street = $address_parts[0] ?? '';
    $barangay = $address_parts[1] ?? '';
    $municipality = $address_parts[2] ?? '';
    $province = $address_parts[3] ?? '';

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
                <p><strong>Contact Number:</strong> 🇵🇭 " . $formatted_phone . "</p>
                <p><strong>Service Name:</strong> " . htmlspecialchars($booking['service_name']) . "</p>
                <p><strong>Service Type:</strong> " . htmlspecialchars($booking['service_type']) . "</p>
                <p><strong>Street:</strong> " . htmlspecialchars($street) . "</p>
                <p><strong>Barangay:</strong> " . htmlspecialchars($barangay) . "</p>
                <p><strong>Municipality/City:</strong> " . htmlspecialchars($municipality) . "</p>
                <p><strong>Province:</strong> " . htmlspecialchars($province) . "</p>
                <p><strong>Appointment Date:</strong> " . htmlspecialchars($booking['appointment_date']) . "</p>
                <p><strong>Appointment Time:</strong> " . htmlspecialchars($booking['appointment_time']) . "</p>
                <p><strong>Structure Type:</strong> " . htmlspecialchars($booking['structure_type'] ?: 'N/A') . "</p>
                <p><strong>Price Range:</strong> " . htmlspecialchars(str_replace('PHP', '₱', $booking['price_range'])) . "</p>
                <p><strong>Status:</strong> " . htmlspecialchars($booking['status']) . "</p>
            </div>
            <div class='footer'>
                <p>Thank you for choosing our services!</p>
                <p>Contact us at support@pestcontrol.com for any inquiries.</p>
            </div>
        </div>
    </body>
    </html>";

    header('Content-Type: text/html');
    header('Content-Disposition: attachment; filename="receipt_' . $booking['reference_code'] . '.html"');
    echo $receipt;
    exit();
}

$maxBookingsPerDay = 10;
$bookingConfirmation = null;

// Handle booking submission
if (isset($_POST['submit_booking'])) {
    $id = $_SESSION['id'];
    $service_name = $_POST['service_type'];
    $phone = $_POST['phone_number'];

    if (!preg_match('/^[0-9]{11}$/', $phone)) {
        $_SESSION['error'] = "Phone number must be exactly 11 digits.";
        header("Location: booking_form.php");
        exit();
    }

    $street = htmlspecialchars(ucwords(strtolower(trim($_POST['street']))));
    $barangay = htmlspecialchars(ucwords(strtolower(trim($_POST['barangay']))));
    $municipality = htmlspecialchars(ucwords(strtolower(trim($_POST['municipality']))));
    $province = htmlspecialchars(ucwords(strtolower(trim($_POST['province']))));

    if (empty($street) || empty($barangay) || empty($municipality) || empty($province)) {
        $_SESSION['error'] = "All address fields are required.";
        header("Location: booking_form.php");
        exit();
    }

    $address = "$street, $barangay, $municipality, $province";
    $appointment = $_POST['appointment_date'];
    $appointment_time = $_POST['appointment_time'];
    $price_range = $_POST['price_range'];
    $reference_code = strtoupper(uniqid('REF'));

    $structure_type = isset($_POST['structure_type']) ? htmlspecialchars($_POST['structure_type']) : '';
    if (empty($structure_type)) {
        $_SESSION['error'] = "Please select a structure type.";
        header("Location: booking_form.php");
        exit();
    }
    if ($structure_type === 'Other') {
        $structure_type = isset($_POST['structure_type_other']) ? htmlspecialchars(ucwords(strtolower(trim($_POST['structure_type_other'])))) : '';
        if (empty($structure_type)) {
        $_SESSION['error'] = "Please specify the structure type for 'Other'.";
        header("Location: booking_form.php");
        exit();
        }
    }

    if (!preg_match('/(AM|PM)$/', $appointment_time)) {
        $_SESSION['error'] = "Invalid appointment time format.";
        header("Location: booking_form.php");
        exit();
    }

    $stmt = $pdo->prepare("SELECT service_id, service_type FROM services WHERE service_name = ?");
    $stmt->execute([$service_name]);
    $service_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$service_data) {
        $_SESSION['error'] = "Selected service does not exist.";
        header("Location: booking_form.php");
        exit();
    }

    if (!preg_match('/^(.+?)\s+SQM\s*=\s*(.+?)\s+PHP$/', $price_range, $matches)) {
        $_SESSION['error'] = "Invalid price range format.";
        header("Location: booking_form.php");
        exit();
    }

    $selected_range = trim($matches[1]);
    $selected_price = trim(str_replace(',', '', $matches[2]));

    $price_stmt = $pdo->prepare("
        SELECT price_range, price
        FROM service_price_ranges
        WHERE service_id = ? AND price_range = ?
    ");
    $price_stmt->execute([$service_data['service_id'], $selected_range]);
    $valid_price = $price_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$valid_price || number_format(clean_price($valid_price['price']), 0) != number_format(clean_price($selected_price), 0)) {
        $_SESSION['error'] = "Invalid price range selected.";
        header("Location: booking_form.php");
        exit();
    }

    $checkBookings = $pdo->prepare("SELECT COUNT(*) FROM service_bookings WHERE appointment_date = ? AND status != 'Cancelled'");
    $checkBookings->execute([$appointment]);
    if ($checkBookings->fetchColumn() >= $maxBookingsPerDay) {
        $_SESSION['error'] = "The selected date is fully booked. Please choose another date.";
        header("Location: booking_form.php");
        exit();
    }

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
            $debug_info[] = "{$req['active_ingredient']}: Not found";
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
        $error_message = "Insufficient stock for: " . implode(", ", $insufficient) . ". Contact support.";
        if ($_SESSION['user_type'] === 'admin') {
            $error_message .= "<br><strong>Debug:</strong> " . implode("; ", $debug_info);
        }
        $_SESSION['error'] = $error_message;
        header("Location: booking_form.php");
        exit();
    }

    if (!empty($expired)) {
        $error_message = "Expired items: " . implode(", ", $expired) . ".";
        if ($_SESSION['user_type'] === 'admin') {
            $error_message .= "<br><strong>Debug:</strong> " . implode("; ", $debug_info);
        }
        $_SESSION['error'] = $error_message;
        header("Location: booking_form.php");
        exit();
    }

    try {
        $pdo->beginTransaction();

        $service_id = $service_data['service_id'];
        $service_type = $service_data['service_type'];
        $formatted_price_range = $selected_range . ' SQM = ₱' . number_format(clean_price($valid_price['price']), 0) . ' PHP';

        $insert = $pdo->prepare("
            INSERT INTO service_bookings
            (id, service_id, phone_number, address, appointment_date, appointment_time, reference_code, customer_name, price_range, status, service_type, structure_type, service_name)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?, ?, ?)
        ");
        $insert->execute([
            $id, $service_id, $phone, $address, $appointment, $appointment_time,
            $reference_code, $fullName, $formatted_price_range, $service_type, $structure_type, $service_name
        ]);

        foreach ($requirements as $req) {
            $update_stmt = $pdo->prepare("UPDATE inventory SET stocks = stocks - ? WHERE LOWER(active_ingredient) = LOWER(?)");
            $update_stmt->execute([$req['stocks_used'], $req['active_ingredient']]);
        }

        $pdo->commit();

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
            'street' => $street,
            'barangay' => $barangay,
            'municipality' => $municipality,
            'province' => $province,
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

// Fetch booked dates for calendar
$bookedDates = [];
$stmt = $pdo->query("SELECT appointment_date, COUNT(*) as count FROM service_bookings WHERE status != 'Cancelled' GROUP BY appointment_date");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $bookedDates[$row['appointment_date']] = $row['count'];
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

// Fetch services with availability status
$services_status = [];
$all_services = $pdo->query("SELECT service_id, service_name FROM services ORDER BY service_name")->fetchAll(PDO::FETCH_ASSOC);
$now = new DateTime();

$service_ingredients = [];
foreach ($all_services as $service) {
    $stmt = $pdo->prepare("SELECT active_ingredient FROM service_inventory WHERE service_name = ?");
    $stmt->execute([$service['service_name']]);
    $ingredients = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $service_ingredients[$service['service_name']] = $ingredients;
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
            $unavailable_reasons[] = "{$req['active_ingredient']}: Not found";
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
    $is_rotated = $current_rotated_ingredient && in_array(strtolower($current_rotated_ingredient), array_map('strtolower', $service_ingredients[$service_name]));
    $services_status[] = [
        'name' => $service_name,
        'service_id' => $service_id,
        'available' => $has_sufficient_stock,
        'reasons' => $unavailable_reasons,
        'is_rotated' => $is_rotated
    ];
}

// Fetch all price ranges
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
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #0f4c3a 0%, #1a5f3f 25%, #2d7a47 50%, #22c55e 75%, #16a34a 100%);
            background-size: 400% 400%;
            animation: gradientShift 15s ease infinite;
            min-height: 100vh;
            color: #1f2937;
            overflow-x: hidden;
        }
        @keyframes gradientShift { 0% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } 100% { background-position: 0% 50%; } }
        .container { max-width: 1400px; margin: 0 auto; padding: 2rem 1.5rem; position: relative; z-index: 1; }
        .hero-section { background: rgba(255, 255, 255, 0.15); backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.2); color: white; padding: 4rem 3rem; border-radius: 32px; margin-bottom: 3rem; text-align: center; box-shadow: 0 25px 50px rgba(0, 0, 0, 0.2); }
        .hero-section:hover { transform: translateY(-10px); box-shadow: 0 35px 70px rgba(0, 0, 0, 0.3); }
        .hero-title { font-size: 3.5rem; font-weight: 900; margin-bottom: 1.5rem; text-shadow: 0 4px 8px rgba(0, 0, 0, 0.3); background: linear-gradient(45deg, #fff, #f0f9ff, #fff); -webkit-background-clip: text; -webkit-text-fill-color: transparent; animation: textShimmer 3s ease-in-out infinite; }
        .hero-subtitle { font-size: 1.4rem; opacity: 0.95; margin-bottom: 0.75rem; font-weight: 300; letter-spacing: 0.5px; }
        .card { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(20px); border-radius: 24px; box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1); margin-bottom: 3rem; border: 1px solid rgba(255, 255, 255, 0.3); }
        .card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: linear-gradient(90deg, #0f4c3a, #1a5f3f, #2d7a47, #22c55e, #16a34a); background-size: 300% 100%; animation: gradientMove 3s ease infinite; }
        .card:hover { transform: translateY(-12px) scale(1.02); box-shadow: 0 30px 60px rgba(0, 0, 0, 0.2); }
        .card-header { background: linear-gradient(135deg, #0f4c3a 0%, #1a5f3f 100%); color: #ffffff; padding: 2.5rem; font-size: 2rem; font-weight: 800; text-align: center; }
        .card-body { padding: 3rem; background: rgba(255, 255, 255, 0.95); }
        .form-group { margin-bottom: 2rem; position: relative; }
        .form-label { font-weight: 700; color: #1f2937; margin-bottom: 1rem; font-size: 1.1rem; display: flex; align-items: center; gap: 0.75rem; }
        .form-control, .form-select { border: 2px solid #e5e7eb; border-radius: 16px; padding: 1.25rem; font-size: 1.1rem; background: rgba(249, 250, 251, 0.8); transition: all 0.4s; width: 100%; }
        .form-control:focus, .form-select:focus { border-color: #0f4c3a; box-shadow: 0 0 0 6px rgba(15, 76, 58, 0.15); outline: none; background: rgba(255, 255, 255, 0.95); transform: translateY(-3px); }
        .btn { padding: 1.25rem 3rem; border-radius: 16px; font-weight: 700; font-size: 1.1rem; transition: all 0.4s; cursor: pointer; border: none; text-transform: uppercase; letter-spacing: 1px; box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15); }
        .btn-primary { background: linear-gradient(135deg, #0f4c3a 0%, #1a5f3f 100%); color: #ffffff; }
        .btn-primary:hover { background: linear-gradient(135deg, #1a5f3f 0%, #2d7a47 100%); transform: translateY(-4px); box-shadow: 0 15px 35px rgba(15, 76, 58, 0.6); }
        .btn-outline-danger { border: 3px solid #dc2626; color: #dc2626; background: rgba(255, 255, 255, 0.9); text-transform: none; font-weight: 600; }
        .btn-outline-danger:hover { background: #dc2626; color: white; }
        .btn-history { background: linear-gradient(135deg, #2d7a47 0%, #22c55e 100%); color: #ffffff; margin-bottom: 2rem; }
        .btn-history:hover { background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%); transform: translateY(-4px); }
        .confirmation-modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.8); backdrop-filter: blur(10px); }
        .confirmation-modal-content { background: rgba(255, 255, 255, 0.98); margin: 3% auto; padding: 0; border-radius: 24px; width: 90%; max-width: 800px; max-height: 90vh; overflow: hidden; box-shadow: 0 30px 60px rgba(0, 0, 0, 0.4); display: flex; flex-direction: column; }
        .confirmation-modal-header { background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%); color: white; padding: 2rem; display: flex; justify-content: space-between; align-items: center; }
        .confirmation-modal-body { padding: 2.5rem; flex: 1; overflow-y: auto; }
        .booking-summary { background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%); border-radius: 16px; padding: 2rem; margin-bottom: 2rem; border: 2px solid #bbf7d0; }
        .booking-details { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; }
        .detail-item { background: rgba(255, 255, 255, 0.8); padding: 1rem; border-radius: 12px; border-left: 4px solid #22c55e; }
        .detail-label { font-weight: 600; color: #0f4c3a; font-size: 0.9rem; }
        .detail-value { color: #1f2937; font-size: 1rem; font-weight: 500; }
        .confirmation-actions { display: flex; gap: 1rem; justify-content: center; margin-top: 2rem; }
        .btn-confirm { background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%); color: #ffffff; padding: 1rem 2rem; border-radius: 12px; font-weight: 600; border: none; cursor: pointer; }
        .btn-download { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: #ffffff; padding: 1rem 2rem; border-radius: 12px; font-weight: 600; border: none; cursor: pointer; }
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.7); backdrop-filter: blur(5px); }
        .modal-content { background: rgba(255, 255, 255, 0.95); margin: 1% auto; padding: 0; border-radius: 20px; width: 98%; max-width: 1400px; max-height: 98vh; overflow: hidden; box-shadow: 0 25px 50px rgba(0, 0, 0, 0.3); display: flex; flex-direction: column; }
        .modal-header { background: linear-gradient(135deg, #0f4c3a 0%, #1a5f3f 100%); color: white; padding: 1.5rem 2rem; display: flex; justify-content: space-between; align-items: center; }
        .close { color: white; font-size: 1.8rem; font-weight: bold; cursor: pointer; }
        .table-container { background: rgba(255, 255, 255, 0.95); border-radius: 16px; overflow: auto; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1); flex: 1; }
        .table { width: 100%; border-collapse: collapse; min-width: 1200px; }
        .table th { background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%); color: #0f4c3a; font-weight: 800; padding: 1rem; font-size: 0.9rem; text-align: left; border-bottom: 3px solid #dcfce7; position: sticky; top: 0; z-index: 10; }
        .table td { padding: 1rem; vertical-align: middle; font-size: 0.85rem; border-bottom: 1px solid #f1f5f9; }
        .badge { padding: 0.5rem 1rem; border-radius: 50px; font-size: 0.8rem; font-weight: 700; display: inline-flex; align-items: center; gap: 0.25rem; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15); }
        .action-buttons { display: flex; flex-direction: column; gap: 0.5rem; min-width: 120px; }
        .btn-small { padding: 0.5rem 1rem; font-size: 0.8rem; border-radius: 8px; font-weight: 600; text-transform: none; letter-spacing: normal; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15); }
        .structure-options { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.5rem; margin-top: 1.5rem; }
        .structure-option input[type="radio"] { position: absolute; opacity: 0; cursor: pointer; }
        .structure-option-label { display: block; padding: 1.5rem; background: rgba(249, 250, 251, 0.8); border: 3px solid #e5e7eb; border-radius: 16px; text-align: center; font-weight: 600; font-size: 1.1rem; transition: all 0.4s; cursor: pointer; }
        .structure-option input[type="radio"]:checked + .structure-option-label { background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%); border-color: #0f4c3a; color: #0f4c3a; transform: translateY(-4px); box-shadow: 0 8px 25px rgba(15, 76, 58, 0.3); }
        .alert { border-radius: 20px; padding: 2rem; margin-bottom: 2.5rem; font-size: 1.1rem; line-height: 1.7; position: relative; overflow: hidden; backdrop-filter: blur(10px); border: 2px solid rgba(255, 255, 255, 0.3); }
        .alert-success { background: linear-gradient(135deg, rgba(34, 197, 94, 0.1) 0%, rgba(22, 163, 74, 0.1) 100%); color: #065f46; border-color: rgba(34, 197, 94, 0.3); }
        .alert-error { background: linear-gradient(135deg, rgba(220, 38, 38, 0.1) 0%, rgba(185, 28, 28, 0.1) 100%); color: #991b1b; border-color: rgba(220, 38, 38, 0.3); }
        .alert-warning { background: linear-gradient(135deg, rgba(245, 158, 11, 0.1) 0%, rgba(217, 119, 6, 0.1) 100%); color: #92400e; border-color: rgba(245, 158, 11, 0.3); }
        .alert-info { background: linear-gradient(135deg, rgba(59, 130, 246, 0.1) 0%, rgba(37, 99, 235, 0.1) 100%); color: #1e40af; border-color: rgba(59, 130, 246, 0.3); }
        #serviceDetails .card { background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); padding: 2.5rem; border-radius: 20px; margin-top: 2.5rem; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1); border: 2px solid #e2e8f0; }
        .fade-in { animation: fadeIn 0.8s cubic-bezier(0.4, 0, 0.2, 1); }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes textShimmer { 0% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } 100% { background-position: 0% 50%; } }
        @keyframes gradientMove { 0% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } 100% { background-position: 0% 50%; } }
        .particles { position: fixed; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; z-index: 0; }
        .particle { position: absolute; width: 4px; height: 4px; background: rgba(255, 255, 255, 0.6); border-radius: 50%; animation: particleFloat 20s linear infinite; }
        @keyframes particleFloat { 0% { transform: translateY(100vh) translateX(0); opacity: 0; } 10% { opacity: 1; } 90% { opacity: 1; } 100% { transform: translateY(-100vh) translateX(100px); opacity: 0; } }

        /* CALENDAR STYLES */
        .calendar { background: rgba(255, 255, 255, 0.95); border-radius: 20px; padding: 1.5rem; box-shadow: 0 10px 30px rgba(0,0,0,0.1); margin-top: 2rem; }
        .calendar-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; font-weight: 700; font-size: 1.2rem; color: #0f4c3a; }
        .calendar-header button { background: rgba(255,255,255,0.8); border: none; padding: 0.5rem 0.75rem; border-radius: 8px; cursor: pointer; transition: 0.3s; }
        .calendar-header button:hover { background: #22c55e; color: white; }
        .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 0.5rem; text-align: center; }
        .calendar-day { padding: 0.75rem; border-radius: 12px; font-size: 0.9rem; transition: all 0.3s; cursor: pointer; position: relative; }
        .calendar-day:hover { background: #e5e7eb; transform: scale(1.05); }
        .calendar-day.booked { background: #fecaca; color: #991b1b; font-weight: 600; }
        .calendar-day.selected { background: #bbf7d0; color: #166534; font-weight: 700; border: 2px solid #22c55e; }
        .calendar-day.disabled { color: #9ca3af; pointer-events: none; background: #f3f4f6; }
        .calendar-day-header { font-weight: 600; color: #4b5563; padding: 0.5rem; }
        .calendar-count { font-size: 0.7rem; display: block; margin-top: 2px; }
        @media (max-width: 1024px) { .container { padding: 1.5rem; } .card-body { padding: 2rem; } .hero-title { font-size: 2.5rem; } .table { min-width: 1600px; } }
        @media (max-width: 768px) { .hero-title { font-size: 2rem; } .booking-details { grid-template-columns: 1fr; } .confirmation-actions { flex-direction: column; } }
    </style>
</head>
<body>
    <div class="particles" id="particles"></div>

    <div class="container">
        <div class="hero-section fade-in">
            <h1 class="hero-title">Welcome, <?= htmlspecialchars($fullName) ?></h1>
            <p class="hero-subtitle">Experience premium pest control services with professional care and guaranteed results</p>
            <form action="logout.php" method="post">
                <button type="submit" class="btn btn-outline-danger">Logout</button>
            </form>
        </div>

        <?php if (!empty($bookingHistory)): ?>
            <div class="text-center mb-6">
                <button onclick="openBookingHistoryModal()" class="btn btn-history">View Booking History</button>
            </div>
        <?php endif; ?>

        <div class="card fade-in">
            <div class="card-header">Book Your Service</div>
            <div class="card-body">
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-error"><?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
                <?php endif; ?>
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success"><?= htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
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
                    <div class="alert alert-warning">No services are currently available due to insufficient inventory or expired items. Please contact support at support@pestcontrol.com for assistance.</div>
                <?php else: ?>
                    <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-8" id="bookingForm">
                        <div class="form-group">
                            <label class="form-label">Service Selection</label>
                            <select name="service_type" class="form-select" required onchange="fetchServiceDetails(this.value)">
                                <option value="">-- Choose Your Service --</option>
                                <?php
                                foreach ($services_status as $service) {
                                    $label = $service['name'];
                                    if ($service['is_rotated']) $label .= ' (Recommended)';
                                    if (!$service['available']) $label .= ' (Currently Unavailable)';
                                    $disabled = $service['available'] ? '' : 'disabled';
                                    echo "<option value='" . htmlspecialchars($service['name']) . "' $disabled>" . htmlspecialchars($label) . "</option>";
                                }
                                ?>
                            </select>
                        </div>

                        <div class="form-group">
    <label class="form-label">
        <i class="fas fa-phone"></i> Philippine Mobile Number
    </label>

    <!-- CLEAN PHILIPPINE NUMBER INPUT -->
    <input type="tel"
           name="phone_number"
           id="phone_number"
           class="form-control"
           placeholder="e.g. 0912 345 6789"
           maxlength="15"
           required
           autocomplete="tel-national">

    <div id="phone_error" class="text-red-600 text-sm mt-2 hidden">
        <i class="fas fa-exclamation-triangle"></i>
        Please enter a valid 11-digit Philippine mobile number (09xxxxxxxxx)
    </div>
</div>

                        <!-- SPACIOUS & FUNCTIONAL ADDRESS (replace this block only) -->
                        <!-- 100% WORKING SPACIOUS ADDRESS -->
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-map-marker-alt"></i> Complete Address
                            </label>

                            <!-- BIG WHITE CARD WITH REAL SPACE -->
                            <div class="bg-white rounded-3xl shadow-xl p-8 mt-4 border-4 border-green-100">
                                <div class="space-y-7">

                                    <input type="text" name="street"
                                           class="w-full h-20 px-6 text-xl border-0 bg-gray-50 rounded-2xl focus:bg-white focus:ring-4 focus:ring-green-300 transition"
                                           placeholder="House No. / Bldg / Street Name" required
                                           oninput="capitalizeAddress(this)">

                                    <input type="text" name="barangay"
                                           class="w-full h-20 px-6 text-xl border-0 bg-gray-50 rounded-2xl focus:bg-white focus:ring-4 focus:ring-green-300 transition"
                                           placeholder="Barangay" required
                                           oninput="capitalizeAddress(this)">

                                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-7">
                                        <input type="text" name="municipality"
                                               class="w-full h-20 px-6 text-xl border-0 bg-gray-50 rounded-2xl focus:bg-white focus:ring-4 focus:ring-green-300 transition"
                                               placeholder="City / Municipality" required
                                               oninput="capitalizeAddress(this)">

                                        <input type="text" name="province"
                                               class="w-full h-20 px-6 text-xl border-0 bg-gray-50 rounded-2xl focus:bg-white focus:ring-4 focus:ring-green-300 transition"
                                               placeholder="Province" required
                                               oninput="capitalizeAddress(this)">
                                    </div>
                                </div>
                            </div>

                            <p class="text-sm text-gray-600 mt-4 text-center">
                                We’ll dispatch the team to this exact location.
                            </p>
                        </div>

                        <!-- FULLY NAVIGABLE CALENDAR -->
                        <div class="form-group md:col-span-2">
                            <label class="form-label">Preferred Date</label>
                            <div class="calendar" id="calendar"></div>
                            <input type="hidden" name="appointment_date" id="selectedDate" required>
                            <p class="text-sm text-gray-600 mt-2">Red = Booked | Green = Your Selection | Gray = Unavailable</p>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Preferred Time</label>
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
                            <label class="form-label">Price Range</label>
                            <select name="price_range" id="price_range_select" class="form-select" required>
                                <option value="">-- Select Price Range --</option>
                            </select>
                            <div id="price_range_message" class="text-sm text-gray-500 mt-2"></div>
                        </div>

                        <div class="col-span-1 md:col-span-2">
                            <label class="form-label">Property Type</label>
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
                                <input type="text" class="form-control capitalize" name="structure_type_other" id="structureTypeOther" placeholder="Please specify the property type" oninput="capitalizeAddress(this)">
                            </div>
                        </div>

                        <div class="col-span-1 md:col-span-2 flex justify-center">
                            <button type="submit" name="submit_booking" class="btn btn-primary">Submit Booking Request</button>
                        </div>
                    </form>
                <?php endif; ?>

                <div id="serviceDetails"></div>
            </div>
        </div>

        <?php if (empty($bookingHistory)): ?>
            <div class="alert alert-info">You have no previous bookings. Start by booking your first service above!</div>
        <?php endif; ?>
    </div>

    <!-- Booking Confirmation Modal -->
    <?php if ($bookingConfirmation): ?>
        <div id="bookingConfirmationModal" class="confirmation-modal" style="display: block;">
            <div class="confirmation-modal-content">
                <div class="confirmation-modal-header">
                    <h2>Booking Confirmed!</h2>
                </div>
                <div class="confirmation-modal-body">
                    <div class="booking-summary">
                        <h3>Booking Summary</h3>
                        <div class="booking-details">
                            <div class="detail-item"><div class="detail-label">Reference Code</div><div class="detail-value"><?= htmlspecialchars($bookingConfirmation['reference_code']) ?></div></div>
                            <div class="detail-item"><div class="detail-label">Customer Name</div><div class="detail-value"><?= htmlspecialchars($bookingConfirmation['customer_name']) ?></div></div>
                            <div class="detail-item"><div class="detail-label">Service Name</div><div class="detail-value"><?= htmlspecialchars($bookingConfirmation['service_name']) ?></div></div>
                            <div class="detail-item"><div class="detail-label">Service Type</div><div class="detail-value"><?= htmlspecialchars($bookingConfirmation['service_type']) ?></div></div>
                            <div class="detail-item"><div class="detail-label">Contact Number</div><div class="detail-value"><?= htmlspecialchars($bookingConfirmation['phone_number']) ?></div></div>
                            <div class="detail-item"><div class="detail-label">Street</div><div class="detail-value"><?= htmlspecialchars($bookingConfirmation['street']) ?></div></div>
                            <div class="detail-item"><div class="detail-label">Barangay</div><div class="detail-value"><?= htmlspecialchars($bookingConfirmation['barangay']) ?></div></div>
                            <div class="detail-item"><div class="detail-label">Municipality/City</div><div class="detail-value"><?= htmlspecialchars($bookingConfirmation['municipality']) ?></div></div>
                            <div class="detail-item"><div class="detail-label">Province</div><div class="detail-value"><?= htmlspecialchars($bookingConfirmation['province']) ?></div></div>
                            <div class="detail-item"><div class="detail-label">Appointment Date</div><div class="detail-value"><?= htmlspecialchars($bookingConfirmation['appointment_date']) ?></div></div>
                            <div class="detail-item"><div class="detail-label">Appointment Time</div><div class="detail-value"><?= htmlspecialchars($bookingConfirmation['appointment_time']) ?></div></div>
                            <div class="detail-item"><div class="detail-label">Property Type</div><div class="detail-value"><?= htmlspecialchars($bookingConfirmation['structure_type']) ?></div></div>
                            <div class="detail-item"><div class="detail-label">Price Range</div><div class="detail-value"><?= htmlspecialchars(str_replace('PHP', '₱', $bookingConfirmation['price_range'])) ?></div></div>
                            <div class="detail-item"><div class="detail-label">Status</div><div class="detail-value"><span class="badge bg-yellow-100 text-yellow-800">Pending</span></div></div>
                        </div>
                        <div class="confirmation-actions">
                            <form action="" method="post" style="display: inline;">
                                <input type="hidden" name="reference_code" value="<?= htmlspecialchars($bookingConfirmation['reference_code']) ?>">
                                <button type="submit" name="download_receipt" class="btn-download">Download Receipt</button>
                            </form>
                            <button class="btn-confirm" onclick="closeBookingConfirmationModal()">Close</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Booking History Modal -->
    <div id="bookingHistoryModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Your Booking History</h2>
                <span class="close" onclick="closeBookingHistoryModal()">×</span>
            </div>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Reference Code</th>
                            <th>Service Name</th>
                            <th>Service Type</th>
                            <th>Address</th>
                            <th>Appointment Date</th>
                            <th>Appointment Time</th>
                            <th>Structure Type</th>
                            <th>Price Range</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bookingHistory as $booking): ?>
                            <tr>
                                <td><?= htmlspecialchars($booking['reference_code']) ?></td>
                                <td><?= htmlspecialchars($booking['service_name']) ?></td>
                                <td><?= htmlspecialchars($booking['service_type']) ?></td>
                                <td><?= htmlspecialchars($booking['address']) ?></td>
                                <td><?= htmlspecialchars($booking['appointment_date']) ?></td>
                                <td><?= htmlspecialchars($booking['appointment_time']) ?></td>
                                <td><?= htmlspecialchars($booking['structure_type'] ?: 'N/A') ?></td>
                                <td><?= htmlspecialchars(str_replace('PHP', '₱', $booking['price_range'])) ?></td>
                                <td>
                                    <?php
                                    $status = $booking['status'];
                                    $badge_class = $icon = '';
                                    switch ($status) {
                                        case 'Pending': $badge_class = 'bg-yellow-100 text-yellow-800'; $icon = 'fa-clock'; break;
                                        case 'Confirmed': $badge_class = 'bg-green-100 text-green-800'; $icon = 'fa-check-circle'; break;
                                        case 'Cancelled': $badge_class = 'bg-red-100 text-red-800'; $icon = 'fa-times-circle'; break;
                                        case 'Completed': $badge_class = 'bg-blue-100 text-blue-800'; $icon = 'fa-check-double'; break;
                                    }
                                    ?>
                                    <span class="badge <?= $badge_class ?>">
                                        <i class="fas <?= $icon ?>"></i> <?= htmlspecialchars($status) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <?php if ($booking['status'] === 'Pending'): ?>
                                            <form action="" method="post" style="display: inline;">
                                                <input type="hidden" name="reference_code" value="<?= htmlspecialchars($booking['reference_code']) ?>">
                                                <button type="submit" name="cancel_booking" class="btn btn-small btn-danger">Cancel</button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if (in_array($booking['status'], ['Pending', 'Confirmed'])): ?>
                                            <form action="" method="post" style="display: inline;">
                                                <input type="hidden" name="reference_code" value="<?= htmlspecialchars($booking['reference_code']) ?>">
                                                <button type="submit" name="download_receipt" class="btn btn-small btn-success">Receipt</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        // PASS PHP DATA TO JS
        const bookedDates = <?= json_encode($bookedDates) ?>;
        const maxPerDay = <?= $maxBookingsPerDay ?>;

        // GLOBAL CALENDAR STATE
        let currentYear = new Date().getFullYear();
        let currentMonth = new Date().getMonth(); // 0-11

        // RENDER CALENDAR
        function renderCalendar() {
            const calendar = document.getElementById('calendar');
            const selectedIn = document.getElementById('selectedDate');
            const today = new Date();
            today.setHours(0,0,0,0);

            const firstDay = new Date(currentYear, currentMonth, 1).getDay();
            const daysInMonth = new Date(currentYear, currentMonth + 1, 0).getDate();

            // Header
            let html = `
                <div class="calendar-header">
                    <button type="button" onclick="prevMonth()">←</button>
                    <span>${new Date(currentYear, currentMonth).toLocaleString('default', { month: 'long', year: 'numeric' })}</span>
                    <button type="button" onclick="nextMonth()">→</button>
                </div>`;

            // Weekdays
            html += '<div class="calendar-grid">';
            ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].forEach(d => {
                html += `<div class="calendar-day-header">${d}</div>`;
            });

            // Empty cells
            for (let i = 0; i < firstDay; i++) {
                html += `<div class="calendar-day disabled"></div>`;
            }

            // Days
            for (let day = 1; day <= daysInMonth; day++) {
                const dateStr = `${currentYear}-${String(currentMonth+1).padStart(2,'0')}-${String(day).padStart(2,'0')}`;
                const thisDate = new Date(dateStr);
                const isPast = thisDate < today;
                const count = bookedDates[dateStr] || 0;
                const isFull = count >= maxPerDay;
                const isBooked = count > 0;

                let classes = 'calendar-day';
                if (isPast) classes += ' disabled';
                if (isFull) classes += ' disabled';
                if (isBooked && !isFull) classes += ' booked';
                if (selectedIn.value === dateStr) classes += ' selected';

                const onclick = (!isPast && !isFull) ? `selectDate('${dateStr}', this)` : '';

                html += `<div class="${classes}" ${onclick ? `onclick="${onclick}"` : ''}>
                            ${day}
                            ${isBooked ? `<span class="calendar-count">${count}/${maxPerDay}</span>` : ''}
                         </div>`;
            }

            // Fill remaining
            const totalCells = Math.ceil((firstDay + daysInMonth) / 7) * 7;
            for (let i = firstDay + daysInMonth; i < totalCells; i++) {
                html += `<div class="calendar-day disabled"></div>`;
            }

            html += '</div>';
            calendar.innerHTML = html;
        }

        // NAVIGATION
        function prevMonth() {
            currentMonth--;
            if (currentMonth < 0) { currentMonth = 11; currentYear--; }
            renderCalendar();
        }

        function nextMonth() {
            currentMonth++;
            if (currentMonth > 11) { currentMonth = 0; currentYear++; }
            renderCalendar();
        }

        // SELECT DATE
        function selectDate(date, el) {
            document.querySelectorAll('.calendar-day').forEach(d => d.classList.remove('selected'));
            el.classList.add('selected');
            document.getElementById('selectedDate').value = date;
        }

        // INITIALIZE
        window.onload = function() {
            renderCalendar();
            createParticles();
        };

        function createParticles() {
            const container = document.getElementById('particles');
            for (let i = 0; i < 20; i++) {
                const p = document.createElement('div');
                p.className = 'particle';
                p.style.left = Math.random() * 100 + 'vw';
                p.style.animationDelay = Math.random() * 20 + 's';
                p.style.animationDuration = (10 + Math.random() * 10) + 's';
                container.appendChild(p);
            }
        }

        const otherCheckbox = document.getElementById('otherCheckbox');
        const otherInputDiv = document.getElementById('otherInputDiv');
        const structureRadios = document.querySelectorAll('input[name="structure_type"]');
        structureRadios.forEach(radio => {
            radio.addEventListener('change', function() {
                if (this.value === 'Other') {
                    otherInputDiv.style.display = 'block';
                    document.getElementById('structureTypeOther').required = true;
                } else {
                    otherInputDiv.style.display = 'none';
                    document.getElementById('structureTypeOther').required = false;
                }
            });
        });

        function capitalizeAddress(input) {
    input.value = input.value
        .toLowerCase()
        .split(' ')
        .map(word => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ');
}

function submitAddress() {
    const street = document.querySelector('input[name="street"]').value.trim();
    const barangay = document.querySelector('input[name="barangay"]').value.trim();
    const municipality = document.querySelector('input[name="municipality"]').value.trim();
    const province = document.querySelector('input[name="province"]').value.trim();

    if (!street || !barangay || !municipality || !province) {
        alert("Please fill in all address fields!");
        return;
    }

    const fullAddress = `${street}, ${barangay}, ${municipality}, ${province}`;
    alert("Full Address Submitted:\n" + fullAddress);

    // Optionally, you can send this via AJAX or a form submission here
    console.log("Submitted Address:", fullAddress);
}

        function openBookingHistoryModal() {
            document.getElementById('bookingHistoryModal').style.display = 'block';
        }
        function closeBookingHistoryModal() {
            document.getElementById('bookingHistoryModal').style.display = 'none';
        }
        function closeBookingConfirmationModal() {
            document.getElementById('bookingConfirmationModal').style.display = 'none';
            window.location.href = 'booking_form.php';
        }

        async function fetchServiceDetails(serviceName) {
            if (!serviceName) {
                document.getElementById('serviceDetails').innerHTML = '';
                document.getElementById('price_range_select').innerHTML = '<option value="">-- Select Price Range --</option>';
                return;
            }

            const detailsDiv = document.getElementById('serviceDetails');
            const select = document.getElementById('price_range_select');
            const msg = document.getElementById('price_range_message');
            detailsDiv.innerHTML = '<div class="text-center py-4">Loading...</div>';

            try {
                const res = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=get_service_details&service_name=${encodeURIComponent(serviceName)}`
                });
                const data = await res.json();

                if (data.error) {
                    detailsDiv.innerHTML = `<div class="alert alert-error">${data.error}</div>`;
                    select.innerHTML = '<option value="">-- Select Price Range --</option>';
                    return;
                }

                let html = '<div class="card"><h3 class="text-lg font-bold text-gray-800 mb-4">Service Details</h3>';
                html += `<p><strong>Name:</strong> ${data.service_name}</p>`;
                html += `<p><strong>Type:</strong> ${data.service_type}</p>`;
                html += `<p><strong>Details:</strong> ${data.service_details}</p>`;
                if (data.active_ingredients?.length > 0) {
                    html += `<p><strong>Ingredients:</strong> ${data.active_ingredients.join(', ')}</p>`;
                }
                if (data.is_rotated) {
                    html += `<p class="text-green-600 font-semibold">Recommended ingredient included!</p>`;
                }
                html += '</div>';
                detailsDiv.innerHTML = html;

                select.innerHTML = '<option value="">-- Select Price Range --</option>';
                data.price_ranges.forEach(r => {
                    const formatted = `${r.price_range} SQM = ₱${parseInt(r.price).toLocaleString()} PHP`;
                    select.innerHTML += `<option value="${formatted}">${formatted}</option>`;
                });

                msg.innerHTML = data.price_ranges.length === 0 ? '<span class="text-red-500">No price ranges available.</span>' : '';
                select.disabled = data.price_ranges.length === 0;
            } catch (e) {
                detailsDiv.innerHTML = '<div class="alert alert-error">Failed to load service details.</div>';
            }
        }

        window.onclick = function(e) {
            const hist = document.getElementById('bookingHistoryModal');
            const conf = document.getElementById('bookingConfirmationModal');
            if (e.target === hist) closeBookingHistoryModal();
            if (e.target === conf) closeBookingConfirmationModal();

        };
    </script>
</body>
</html>
