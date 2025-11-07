<?php
session_start();
$conn = new mysqli('localhost', 'root', '', 'inventory');
if ($conn->connect_error) die("DB failed: " . $conn->connect_error);

$search_service = isset($_GET['service']) ? trim($_GET['service']) : '';
$search_ingredient = isset($_GET['ingredient']) ? trim($_GET['ingredient']) : '';

// Fetch services for dropdown filter
$services_result = $conn->query("SELECT service_id, service_name FROM services ORDER BY service_name");
$services_list = [];
while ($s = $services_result->fetch_assoc()) {
    $services_list[] = $s;
}

// Build SQL query with filters
$sql = "SELECT i.active_ingredient, s.service_name, i.stocks
        FROM inventory i
        LEFT JOIN services s ON i.service_id = s.service_id
        WHERE 1";

$params = [];
$types = '';

if ($search_service) {
    $sql .= " AND s.service_id = ?";
    $types .= 'i'; // integer type
    $params[] = $search_service;
}

if ($search_ingredient) {
    $sql .= " AND i.active_ingredient LIKE ?";
    $types .= 's'; // string type
    $params[] = "%$search_ingredient%";
}

$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$current_labels = [];
$current_stocks = [];
$ingredients = [];

while ($row = $result->fetch_assoc()) {
    $ingredient = $row['active_ingredient'] ?: '(No ingredient)';
    $service = $row['service_name'] ?: '(No service)';

    $current_labels[] = $ingredient . " (" . $service . ")";
    $current_stocks[] = $row['stocks'] ? (int)$row['stocks'] : 0;
    $ingredients[] = [
        'name' => $ingredient,
        'service' => $service,
        'stocks' => $row['stocks'] ? (int)$row['stocks'] : 0
    ];
}
$stmt->close();

// Rotation schedule: dynamic 3-month periods
$rotation_periods = [];
$period_count = 5;
$month_names = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

$start_month = (int)date('n'); // current month (1-12)
$start_year = (int)date('Y');

for ($i = 0; $i < $period_count; $i++) {
    $month1 = ($start_month + $i*3 - 1) % 12;
    $year1 = $start_year + floor(($start_month + $i*3 - 1)/12);
    $month2 = ($month1 + 2) % 12;
    $year2 = $year1 + floor(($month1 + 2)/12);

    $rotation_periods[] = $month_names[$month1] . " " . $year1 . " - " . $month_names[$month2] . " " . $year2;
}

// Prepare rotation stocks and ingredients
$rotation_stocks = [];
$rotation_ingredients = [];
$ingredient_count = count($ingredients);

for ($i = 0; $i < count($rotation_periods); $i++) {
    if ($ingredient_count === 0) {
        $rotation_stocks[] = 0;
        $rotation_ingredients[] = ['(No ingredient available)'];
        continue;
    }
    $index = $i % $ingredient_count; // cycle through ingredients
    $rotation_stocks[] = $ingredients[$index]['stocks'];
    $rotation_ingredients[] = [$ingredients[$index]['name'] . " (" . $ingredients[$index]['service'] . ")"];
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Active Ingredient Rotation Dashboard</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 0; background-color: #f0f2f5; color: #333; }
        header { background-color: #2196F3; color: #fff; padding: 20px; text-align: center; box-shadow: 0 2px 6px rgba(0,0,0,0.2); }
        header h1 { margin: 0; font-size: 28px; }
        .container { display: flex; flex-direction: column; gap: 20px; padding: 20px; }
        .card { background-color: #fff; padding: 20px; border-radius: 12px; box-shadow: 0 2px 6px rgba(0,0,0,0.1); }
        .card h2 { margin-top: 0; font-size: 22px; margin-bottom: 20px; }
        .btn { display: inline-block; padding: 10px 20px; margin-bottom: 20px; font-size: 16px; color: #fff; background-color: #2196F3; border-radius: 6px; text-decoration: none; transition: 0.3s; }
        .btn:hover { background-color: #1976D2; }
        canvas { max-width: 100%; }
        form { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 20px; }
        select, input[type=text], button { padding: 8px; border-radius: 6px; border: 1px solid #ccc; font-size: 16px; }
        button { background-color: #2196F3; color: #fff; border: none; cursor: pointer; }
        button:hover { background-color: #1976D2; }
    </style>
</head>
<body>
    <header>
        <h1>Active Ingredient Rotation Dashboard</h1>
    </header>

    <div class="container">
        <a href="dashboard.php" class="btn">Back to Dashboard</a>

        <!-- Filter Form -->
        <div class="card">
            <h2>Filter Ingredients</h2>
            <form method="GET">
                <select name="service">
                    <option value="">All Services</option>
                    <?php foreach ($services_list as $s): ?>
                        <option value="<?= $s['service_id'] ?>" <?= $search_service == $s['service_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($s['service_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="ingredient" placeholder="Search Ingredient" value="<?= htmlspecialchars($search_ingredient) ?>">
                <button type="submit">Filter</button>
            </form>
        </div>

        <div class="card">
            <h2>Current Ingredients with Stocks</h2>
            <canvas id="currentChart"></canvas>
        </div>

        <div class="card">
            <h2>Active Ingredient Rotation (Every 3 Months)</h2>
            <canvas id="rotationChart"></canvas>
        </div>
    </div>

    <script>
        const currentData = {
            labels: <?= json_encode($current_labels) ?>,
            datasets: [{
                label: 'Stocks',
                data: <?= json_encode($current_stocks) ?>,
                backgroundColor: 'rgba(33, 150, 243, 0.6)',
                borderColor: 'rgba(33, 150, 243, 1)',
                borderWidth: 1
            }]
        };
        new Chart(document.getElementById('currentChart'), {
            type: 'bar',
            data: currentData,
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true } }
            }
        });

        const rotationData = {
            labels: <?= json_encode($rotation_periods) ?>,
            datasets: [{
                label: 'Stock Levels',
                data: <?= json_encode($rotation_stocks) ?>,
                backgroundColor: 'rgba(76, 175, 80, 0.6)',
                borderColor: 'rgba(76, 175, 80, 1)',
                borderWidth: 1,
                ingredients: <?= json_encode($rotation_ingredients) ?>
            }]
        };
        new Chart(document.getElementById('rotationChart'), {
            type: 'bar',
            data: rotationData,
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) label += ': ';
                                label += context.parsed.y;
                                return label;
                            },
                            afterLabel: function(context) {
                                const index = context.dataIndex;
                                const ingredients = context.dataset.ingredients[index];
                                return ingredients.length > 0 ? '\nSuggested Ingredient:\n' + ingredients[0] : '\nNo ingredient';
                            }
                        }
                    }
                },
                scales: { y: { beginAtZero: true } }
            }
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>
