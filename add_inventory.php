<?php
session_start();
$pdo = new PDO("mysql:host=localhost;dbname=inventory;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

// === AJAX: Search Active Ingredients ===
if (isset($_GET['search_chem'])) {
    header('Content-Type: application/json');
    $q = '%' . strtolower(trim($_GET['search_chem'] ?? '')) . '%';
    $stmt = $pdo->prepare("SELECT ai_id, name FROM active_ingredients WHERE LOWER(name) LIKE ? ORDER BY name LIMIT 15");
    $stmt->execute([$q]);
    echo json_encode($stmt->fetchAll());
    exit;
}

// === AJAX: Add New Active Ingredient ===
if (isset($_GET['add_chem'])) {
    header('Content-Type: application/json');
    $name = trim($_GET['add_chem']);
    if (strlen($name) < 2) {
        echo json_encode(['error' => 'Too short']);
        exit;
    }

    $formatted = ucfirst(strtolower($name));
    $stmt = $pdo->prepare("SELECT ai_id FROM active_ingredients WHERE LOWER(name) = LOWER(?) LIMIT 1");
    $stmt->execute([$formatted]);
    $existing = $stmt->fetch();

    if ($existing) {
        echo json_encode(['ai_id' => $existing['ai_id'], 'name' => $formatted]);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO active_ingredients (name) VALUES (?)");
    $stmt->execute([$formatted]);
    echo json_encode(['ai_id' => $pdo->lastInsertId(), 'name' => $formatted]);
    exit;
}

// === HANDLE FORM SUBMISSION ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $service_id  = (int)$_POST['service_id'];
    $ai_id       = (int)$_POST['ai_id'];
    $stocks      = (float)$_POST['stocks'];
    $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
    $barcode     = trim($_POST['barcode']) ?: null;

    if ($ai_id <= 0) {
        $_SESSION['error'] = "Please select or add an active ingredient.";
        header("Location: add_inventory.php");
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO inventory (service_id, ai_id, stocks, expiry_date, barcode)
                           VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$service_id, $ai_id, $stocks, $expiry_date, $barcode]);

    $aiName = $pdo->query("SELECT name FROM active_ingredients WHERE ai_id = $ai_id")->fetchColumn();
    $_SESSION['success'] = "✅ Bottle added successfully for <strong>$aiName</strong>!";
    header("Location: add_inventory.php");
    exit;
}

// === FETCH SERVICES ===
$services = $pdo->query("SELECT service_id, service_name FROM services ORDER BY service_name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Add Bottle - Pest Control</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
:root { --green: #28a745; --dark: #0d3b1e; }
body { background: linear-gradient(135deg, var(--dark), #1a5c38); color: #fff; min-height: 100vh; }
.card { background: rgba(255,255,255,.98); border-radius: 1.5rem; box-shadow: 0 20px 50px rgba(0,0,0,.3); }
.form-label { font-weight: 700; color: var(--green); }
.live-search { position: relative; }
#chemResults { position: absolute; top: 100%; left: 0; right: 0; z-index: 1050; background: #fff; border: 1px solid #ccc; border-top: none; border-radius: 0 0 1rem 1rem; max-height: 200px; overflow-y: auto; }
.result-item { padding: .75rem 1rem; cursor: pointer; }
.result-item:hover { background: #e9f7ef; }
.add-new { color: var(--green); font-weight: 600; text-align: center; }
.btn-scan { background: var(--green); border: none; padding: 1rem 2rem; border-radius: 50px; font-size: 1.2rem; }
</style>
</head>
<body class="pb-5">
<div class="container py-5">
    <div class="card p-4 p-md-5">
        <div class="text-center mb-5">
            <h1 class="display-5 fw-bold text-success"><i class="bi bi-plus-circle-fill"></i> Add New Bottle</h1>
            <a href="inventory.php" class="btn btn-outline-secondary btn-lg"><i class="bi bi-arrow-left"></i> Back</a>
        </div>

        <?php if (!empty($_SESSION['success'])): ?>
            <div class="alert alert-success text-center"><?= $_SESSION['success']; unset($_SESSION['success']); ?></div>
        <?php elseif (!empty($_SESSION['error'])): ?>
            <div class="alert alert-danger text-center"><?= $_SESSION['error']; unset($_SESSION['error']); ?></div>
        <?php endif; ?>

        <form method="POST" id="addForm" class="row g-4">
            <div class="col-md-6">
                <label class="form-label"><i class="bi bi-briefcase"></i> Service Type</label>
                <select name="service_id" class="form-select form-select-lg" required>
                    <option value="">Choose service...</option>
                    <?php foreach ($services as $s): ?>
                        <option value="<?= $s['service_id'] ?>">
                            <?= htmlspecialchars(ucwords(strtolower($s['service_name']))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6">
                <label class="form-label"><i class="bi bi-droplet-fill"></i> Active Ingredient</label>
                <div class="live-search">
                    <input type="text" id="chemInput" class="form-control form-control-lg"
                           placeholder="Type to search or add..." autocomplete="off" required>
                    <div id="chemResults" class="d-none"></div>
                </div>
                <input type="hidden" name="ai_id" id="ai_id" required>
            </div>

            <div class="col-md-4">
                <label class="form-label"><i class="bi bi-box-seam"></i> Stock (bottles)</label>
                <input type="number" name="stocks" class="form-control form-control-lg" placeholder="10" required>
            </div>

            <div class="col-md-4">
                <label class="form-label"><i class="bi bi-calendar-x"></i> Expiry Date</label>
                <input type="date" name="expiry_date" class="form-control form-control-lg">
            </div>

            <div class="col-md-4">
                <label class="form-label"><i class="bi bi-upc-scan"></i> Barcode</label>
                <input type="text" name="barcode" class="form-control form-control-lg" placeholder="Scan or type">
            </div>

            <div class="col-12 text-center mt-5">
                <button type="submit" class="btn btn-scan shadow"><i class="bi bi-plus-circle"></i> Add Bottle</button>
            </div>
        </form>
    </div>
</div>

<script>
const chemInput = document.getElementById('chemInput');
const chemResults = document.getElementById('chemResults');
const ai_id = document.getElementById('ai_id');
let timeout;

function formatName(name) {
    name = name.trim().toLowerCase();
    return name.charAt(0).toUpperCase() + name.slice(1);
}

// Live search for active ingredients
chemInput.addEventListener('input', () => {
    clearTimeout(timeout);
    const q = chemInput.value.trim();
    if (!q) return chemResults.classList.add('d-none');

    timeout = setTimeout(() => {
        fetch(`?search_chem=${encodeURIComponent(q)}`)
            .then(r => r.json())
            .then(data => {
                chemResults.innerHTML = '';
                if (data.length === 0) {
                    chemResults.innerHTML = `<div class="result-item add-new" onclick="addNewChem('${q}')">
                        <i class='bi bi-plus-circle'></i> Add "<strong>${formatName(q)}</strong>" as new
                    </div>`;
                } else {
                    data.forEach(item => {
                        const div = document.createElement('div');
                        div.className = 'result-item';
                        div.textContent = formatName(item.name);
                        div.onclick = () => selectChem(item.ai_id, formatName(item.name));
                        chemResults.appendChild(div);
                    });
                    const add = document.createElement('div');
                    add.className = 'result-item add-new';
                    add.innerHTML = `<i class='bi bi-plus-circle'></i> Add "<strong>${formatName(q)}</strong>" as new`;
                    add.onclick = () => addNewChem(q);
                    chemResults.appendChild(add);
                }
                chemResults.classList.remove('d-none');
            });
    }, 300);
});

function selectChem(id, name) {
    chemInput.value = formatName(name);
    ai_id.value = id;
    chemResults.classList.add('d-none');
}

function addNewChem(name) {
    name = formatName(name);
    fetch(`?add_chem=${encodeURIComponent(name)}`)
        .then(r => r.json())
        .then(data => {
            if (data.error) return alert(data.error);
            selectChem(data.ai_id, data.name);
            alert(`Added "${data.name}" successfully!`);
        });
}

document.addEventListener('click', e => {
    if (!chemInput.contains(e.target) && !chemResults.contains(e.target))
        chemResults.classList.add('d-none');
});
</script>
</body>
</html>
