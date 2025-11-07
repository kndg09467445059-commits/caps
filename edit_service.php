<?php
session_start();
$conn = new mysqli('localhost', 'root', '', 'inventory');
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$id = (int)$_GET['id'];

// ✅ Fetch service with ingredient (from service_inventory)
$query = "
    SELECT s.service_id, s.service_name, COALESCE(si.active_ingredient, '') AS active_ingredient
    FROM services s
    LEFT JOIN service_inventory si ON s.service_name = si.service_name
    WHERE s.service_id = ?
";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $id);
$stmt->execute();
$service = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ✅ Fetch all active ingredients from inventory
$ingredients = [];
$result = $conn->query("SELECT DISTINCT active_ingredient FROM inventory ORDER BY active_ingredient ASC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $ingredients[] = $row['active_ingredient'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $service_name = trim($_POST['service_name']);
    $active_ingredient = trim($_POST['active_ingredient']);

    // Update service name
    $stmt = $conn->prepare("UPDATE services SET service_name=? WHERE service_id=?");
    $stmt->bind_param("si", $service_name, $id);
    $stmt->execute();
    $stmt->close();

    // Update ingredient in service_inventory
    $stmt2 = $conn->prepare("UPDATE service_inventory SET active_ingredient=? WHERE service_name=?");
    $stmt2->bind_param("ss", $active_ingredient, $service_name);
    $stmt2->execute();
    $stmt2->close();

    $_SESSION['success'] = "Service updated successfully.";
    header("Location: maintenance.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Service</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="container py-5">
<h1>Edit Service</h1>
<a href="maintenance.php" class="btn btn-secondary mb-3">← Back</a>

<form method="POST">
    <div class="mb-3">
        <label>Service Name</label>
        <input type="text" name="service_name" class="form-control"
               value="<?= htmlspecialchars($service['service_name']) ?>" required>
    </div>
    <div class="mb-3">
        <label>Active Ingredient</label>
        <select name="active_ingredient" class="form-control" required>
            <option value="">-- Select Ingredient --</option>
            <?php foreach ($ingredients as $ingredient): ?>
                <option value="<?= htmlspecialchars($ingredient) ?>"
                    <?= ($ingredient == $service['active_ingredient']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($ingredient) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn btn-primary">Update</button>
</form>
</body>
</html>
<?php $conn->close(); ?>
