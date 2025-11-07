<?php
session_start();

// Database connection
$host = 'localhost';
$dbname = 'inventory';
$username = 'root';
$password = '';
$conn = new mysqli($host, $username, $password, $dbname);
if ($conn->connect_error) {
    die('Connection failed: ' . $conn->connect_error);
}

// Handle AJAX ingredient request (for datalist)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'ingredients') {
    $service_id = isset($_GET['service_id']) ? (int)$_GET['service_id'] : 0;
    if ($service_id > 0) {
        $stmt = $conn->prepare("SELECT DISTINCT active_ingredient
                                 FROM inventory
                                 WHERE service_id = ?
                                 ORDER BY active_ingredient ASC");
        $stmt->bind_param("i", $service_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            echo '<option value="' . htmlspecialchars($row['active_ingredient']) . '">';
        }
        $stmt->close();
    }
    $conn->close();
    exit; // stop page rendering
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    $_SESSION['error'] = "Invalid inventory ID.";
    header('Location: inventory.php');
    exit;
}

// Fetch current inventory item
$stmt = $conn->prepare("SELECT * FROM inventory WHERE inventory_id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$item = $result->fetch_assoc();
$stmt->close();

if (!$item) {
    $_SESSION['error'] = "Inventory item not found.";
    header("Location: inventory.php");
    exit;
}

// Handle POST (update logic)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $service_id = (int)$_POST['service_id'];
    $active_ingredient = trim($_POST['active_ingredient']);
    $stocks = (int)$_POST['stocks'];
    $expiry_date = $_POST['expiry_date'];

    // Auto-insert new ingredient for that service if not exists
    $check_stmt = $conn->prepare("SELECT COUNT(*) FROM inventory WHERE service_id=? AND active_ingredient=?");
    $check_stmt->bind_param("is", $service_id, $active_ingredient);
    $check_stmt->execute();
    $check_stmt->bind_result($exists);
    $check_stmt->fetch();
    $check_stmt->close();

    if ($exists == 0) {
        // Insert a dummy record just to register new ingredient (0 stock, no expiry yet)
        $dummy_stmt = $conn->prepare("INSERT INTO inventory (service_id, active_ingredient, stocks, expiry_date) VALUES (?, ?, 0, NULL)");
        $dummy_stmt->bind_param("is", $service_id, $active_ingredient);
        $dummy_stmt->execute();
        $dummy_stmt->close();
    }

    // Update current inventory record
    $stmt = $conn->prepare("UPDATE inventory
                            SET service_id=?, active_ingredient=?, stocks=?, expiry_date=?
                            WHERE inventory_id=?");
    $stmt->bind_param("isisi", $service_id, $active_ingredient, $stocks, $expiry_date, $id);

    if ($stmt->execute()) {
        $_SESSION['success'] = "Inventory item updated successfully!";
        header("Location: inventory.php");
        exit;
    } else {
        $_SESSION['error'] = "Database error: " . $stmt->error;
        header("Location: edit_inventory.php?id=$id");
        exit;
    }
    $stmt->close();
}

// Fetch all services for dropdown
$services = [];
$service_query = $conn->query("SELECT service_id, service_name FROM services");
while ($row = $service_query->fetch_assoc()) {
    $services[] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Inventory Item</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, #f0f4f8 0%, #dfe4ea 100%);
            font-family: 'Poppins', sans-serif;
            min-height: 100vh;
            color: #2d3748;
        }

        .dashboard-container {
            padding: 2rem;
            max-width: 1200px;
            margin: 0 auto;
        }

        .header-card {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.3);
            position: relative;
            overflow: hidden;
        }

        .header-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #4b6cb7 0%, #6b7280 100%);
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .header-title {
            color: #2d3748;
            font-size: 2.25rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .header-subtitle {
            color: #6b7280;
            font-size: 1rem;
            font-weight: 400;
        }

        .form-card {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.3);
            position: relative;
            overflow: hidden;
        }

        .form-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #4b6cb7 0%, #6b7280 100%);
        }

        .form-label {
            font-weight: 500;
            color: #2d3748;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }

        .form-control, .form-select {
            border-radius: 10px;
            border: 1px solid rgba(107, 114, 128, 0.3);
            padding: 0.75rem 1rem;
            font-size: 0.9rem;
            background: rgba(255, 255, 255, 0.95);
            transition: all 0.2s ease;
        }

        .form-control:focus, .form-select:focus {
            border-color: #4b6cb7;
            box-shadow: 0 0 0 2px rgba(75, 108, 183, 0.2);
            background: white;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 10px;
            font-weight: 500;
            font-size: 0.9rem;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, #4b6cb7 0%, #6b7280 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(75, 108, 183, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(75, 108, 183, 0.4);
            color: white;
        }

        .btn-secondary {
            background: linear-gradient(135deg, #d1d5db 0%, #9ca3af 100%);
            color: #2d3748;
            box-shadow: 0 4px 12px rgba(107, 114, 128, 0.3);
        }

        .btn-secondary:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(107, 114, 128, 0.4);
            color: #2d3748;
        }

        .alert {
            border-radius: 12px;
            border: none;
            padding: 1.25rem;
            font-weight: 500;
        }

        .alert-success {
            background: linear-gradient(135deg, #68d391 0%, #4a5568 100%);
            color: white;
        }

        .alert-danger {
            background: linear-gradient(135deg, #e57373 0%, #b91c1c 100%);
            color: white;
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        @media (max-width: 768px) {
            .dashboard-container {
                padding: 1rem;
            }

            .header-content {
                flex-direction: column;
                align-items: stretch;
            }

            .form-control, .form-select, .btn {
                font-size: 0.8rem;
            }

            .header-title {
                font-size: 1.75rem;
            }
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <!-- Header Card -->
    <div class="header-card">
        <div class="header-content">
            <div>
                <h1 class="header-title">Edit Inventory Item</h1>
                <p class="header-subtitle">Update details for the selected inventory item</p>
            </div>
        </div>
    </div>

    <!-- Form Card -->
    <div class="form-card">
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle"></i> <?= htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-circle"></i> <?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="edit_inventory.php?id=<?= $id ?>">
            <!-- Service Dropdown -->
            <div class="form-group">
                <label for="service_id" class="form-label">Service Name</label>
                <select class="form-select" id="service_id" name="service_id" required>
                    <option value="" disabled>-- Select Service --</option>
                    <?php foreach ($services as $service): ?>
                        <option value="<?= $service['service_id'] ?>"
                            <?= $service['service_id'] == $item['service_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($service['service_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Active Ingredient with datalist -->
            <div class="form-group">
                <label for="active_ingredient" class="form-label">Active Ingredient</label>
                <input list="ingredient_list" class="form-control" id="active_ingredient"
                       name="active_ingredient" value="<?= htmlspecialchars($item['active_ingredient']) ?>" required>
                <datalist id="ingredient_list"></datalist>
            </div>

            <!-- Stocks -->
            <div class="form-group">
                <label for="stocks" class="form-label">Stocks</label>
                <input type="number" class="form-control" id="stocks" name="stocks"
                       value="<?= htmlspecialchars($item['stocks']) ?>" required>
            </div>

            <!-- Expiry Date -->
            <div class="form-group">
                <label for="expiry_date" class="form-label">Expiry Date</label>
                <input type="date" class="form-control" id="expiry_date" name="expiry_date"
                       value="<?= htmlspecialchars($item['expiry_date']) ?>" required>
            </div>

            <div class="d-flex gap-2">
                <a href="inventory.php" class="btn btn-secondary flex-grow-1">
                    <i class="bi bi-arrow-left"></i> Back to Inventory
                </a>
                <button type="submit" class="btn btn-primary flex-grow-1">
                    <i class="bi bi-save"></i> Update Product
                </button>
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function() {
    // Load ingredients when service is selected
    $('#service_id').change(function() {
        let serviceId = $(this).val();
        if (serviceId) {
            $.get('edit_inventory.php', { ajax: 'ingredients', service_id: serviceId }, function(data) {
                $('#ingredient_list').html(data);
            });
        } else {
            $('#ingredient_list').html('');
        }
    });

    // Trigger once on page load (for edit mode)
    let currentService = $('#service_id').val();
    if (currentService) {
        $.get('edit_inventory.php', { ajax: 'ingredients', service_id: currentService }, function(data) {
            $('#ingredient_list').html(data);
        });
    }
});
</script>
</body>
</html>

<?php $conn->close(); ?>
