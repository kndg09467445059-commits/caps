<?php
session_start();

$host = 'localhost';
$dbname = 'inventory';
$username = 'root';
$password = '';

$conn = new mysqli($host, $username, $password, $dbname);
if ($conn->connect_error) {
    die('Connection failed: ' . $conn->connect_error);
}

// ✅ Fetch active ingredients from inventory
$inventory_query = "SELECT DISTINCT active_ingredient FROM inventory ORDER BY active_ingredient ASC";
$inventory_result = $conn->query($inventory_query);
$active_ingredients = $inventory_result ? $inventory_result->fetch_all(MYSQLI_ASSOC) : [];

// ✅ Handle Add Service
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_service'])) {
    $service_name = trim($_POST['service_name']);
    $active_ingredient = trim($_POST['active_ingredient']);

    if (!empty($service_name) && !empty($active_ingredient)) {
        // Insert into services
        $stmt = $conn->prepare("INSERT INTO services (service_name, active_ingredient) VALUES (?, ?)");
        $stmt->bind_param("ss", $service_name, $active_ingredient);
        if ($stmt->execute()) {
            // Also add to service_inventory with default stocks
            $stmt2 = $conn->prepare("INSERT INTO service_inventory (service_name, active_ingredient, stocks, expiry_date) VALUES (?, ?, 0, NULL)");
            $stmt2->bind_param("ss", $service_name, $active_ingredient);
            $stmt2->execute();
            $stmt2->close();

            $_SESSION['success'] = "Service added successfully.";
        } else {
            $_SESSION['error'] = "Error adding service: " . $stmt->error;
        }
        $stmt->close();
    } else {
        $_SESSION['error'] = "All fields are required.";
    }
    header("Location: maintenance.php");
    exit;
}

// ✅ Handle Delete
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];

    // Get service name
    $stmt = $conn->prepare("SELECT service_name FROM services WHERE service_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->bind_result($service_name);
    $stmt->fetch();
    $stmt->close();

    if (!empty($service_name)) {
        // Delete from service_inventory
        $stmt2 = $conn->prepare("DELETE FROM service_inventory WHERE service_name = ?");
        $stmt2->bind_param("s", $service_name);
        $stmt2->execute();
        $stmt2->close();

        // Delete from services
        $stmt3 = $conn->prepare("DELETE FROM services WHERE service_id = ?");
        $stmt3->bind_param("i", $id);
        $stmt3->execute();
        $stmt3->close();

        $_SESSION['success'] = "Service deleted successfully.";
    } else {
        $_SESSION['error'] = "Service not found.";
    }

    header("Location: maintenance.php");
    exit;
}

// ✅ Fetch services
$query = "SELECT service_id, service_name, active_ingredient FROM services ORDER BY service_id ASC";
$result = $conn->query($query);
$services = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Service Maintenance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="container py-5">

<h1>Service Maintenance</h1>
<a href="dashboard.php" class="btn btn-secondary mb-3">← Back</a>

<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success"><?= $_SESSION['success']; unset($_SESSION['success']); ?></div>
<?php endif; ?>
<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger"><?= $_SESSION['error']; unset($_SESSION['error']); ?></div>
<?php endif; ?>

<!-- Add Service Form -->
<div class="card mb-4">
    <div class="card-header">Add New Service</div>
    <div class="card-body">
        <form method="POST">
            <div class="mb-3">
                <label>Service Name</label>
                <input type="text" name="service_name" class="form-control" required>
            </div>
            <div class="mb-3">
                <label>Active Ingredient</label>
                <select name="active_ingredient" class="form-control" required>
                    <option value="">-- Select Active Ingredient --</option>
                    <?php foreach ($active_ingredients as $row): ?>
                        <option value="<?= htmlspecialchars($row['active_ingredient']) ?>">
                            <?= htmlspecialchars($row['active_ingredient']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" name="add_service" class="btn btn-success">Add Service</button>
        </form>
    </div>
</div>

<!-- Services Table -->
<table class="table table-bordered">
    <thead>
    <tr>
        <th>Service ID</th>
        <th>Service Name</th>
        <th>Active Ingredient</th>
        <th>Actions</th>
    </tr>
    </thead>
    <tbody>
    <?php if (empty($services)): ?>
        <tr><td colspan="4" class="text-center">No services found.</td></tr>
    <?php else: ?>
        <?php foreach ($services as $service): ?>
            <tr>
                <td><?= $service['service_id'] ?></td>
                <td><?= htmlspecialchars($service['service_name']) ?></td>
                <td><?= htmlspecialchars($service['active_ingredient']) ?></td>
                <td>
                    <a href="edit_service.php?id=<?= $service['service_id'] ?>" class="btn btn-sm btn-primary">Edit</a>
                    <a href="maintenance.php?delete=<?= $service['service_id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this service?');">Delete</a>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>
</body>
</html>
<?php $conn->close(); ?>
