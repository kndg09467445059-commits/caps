<?php
session_start();

// Initialize service records if not present
if (!isset($_SESSION['service_records'])) {
    $_SESSION['service_records'] = [];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $client = htmlspecialchars(trim($_POST['client']));
    $type = htmlspecialchars(trim($_POST['type']));
    $date = $_POST['date'];
    $status = $_POST['status'];

    if ($client && $type && $date && in_array($status, ['Scheduled', 'Pending', 'Completed'])) {
        $_SESSION['service_records'][] = [
            'client' => $client,
            'type' => $type,
            'date' => $date,
            'status' => $status
        ];

        header("Location: service_records.php?view=all");
        exit();
    } else {
        $error = "Please fill out all fields correctly.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add Service Record</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container py-5">
    <h1 class="mb-4">Add New Service Record</h1>
    <a href="service_records.php?view=all" class="btn btn-secondary mb-4">Back to Service Records</a>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?= $error ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="mb-3">
            <label for="client" class="form-label">Client Name</label>
            <input type="text" class="form-control" id="client" name="client" required>
        </div>
        <div class="mb-3">
            <label for="type" class="form-label">Service Type</label>
            <input type="text" class="form-control" id="type" name="type" required>
        </div>
        <div class="mb-3">
            <label for="date" class="form-label">Service Date</label>
            <input type="date" class="form-control" id="date" name="date" required>
        </div>
        <div class="mb-3">
            <label for="status" class="form-label">Status</label>
            <select class="form-select" id="status" name="status" required>
                <option value="">-- Select Status --</option>
                <option value="Scheduled">Scheduled</option>
                <option value="Pending">Pending</option>
                <option value="Completed">Completed</option>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Save Record</button>
    </form>
</div>
</body>
</html>
