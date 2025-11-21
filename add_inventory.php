<?php
session_start();

try {
    $pdo = new PDO("mysql:host=localhost;dbname=inventory;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (Exception $e) {
    // If DB connection fails, show a simple message and stop
    http_response_code(500);
    echo "Database connection error.";
    exit;
}

// === AJAX: Search Active Ingredients ===
if (isset($_GET['search_chem'])) {
    header('Content-Type: application/json; charset=utf-8');
    $raw = trim((string)($_GET['search_chem'] ?? ''));
    $q = '%' . mb_strtolower($raw, 'UTF-8') . '%';
    $stmt = $pdo->prepare("SELECT ai_id, name FROM active_ingredients WHERE LOWER(name) LIKE ? ORDER BY name LIMIT 15");
    $stmt->execute([$q]);
    echo json_encode($stmt->fetchAll());
    exit;
}

// === AJAX: Add New Active Ingredient ===
if (isset($_GET['add_chem'])) {
    header('Content-Type: application/json; charset=utf-8');
    $name = trim((string)$_GET['add_chem']);
    if (mb_strlen($name, 'UTF-8') < 2) {
        echo json_encode(['error' => 'Name is too short']);
        exit;
    }

    // Normalize for storage/display: ucfirst of lowercase (but store as provided)
    $formatted = mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');

    // Check for existing (case-insensitive)
    $stmt = $pdo->prepare("SELECT ai_id, name FROM active_ingredients WHERE LOWER(name) = LOWER(?) LIMIT 1");
    $stmt->execute([$name]);
    $existing = $stmt->fetch();

    if ($existing) {
        echo json_encode(['ai_id' => $existing['ai_id'], 'name' => $existing['name']]);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO active_ingredients (name) VALUES (?)");
    $stmt->execute([$formatted]);

    echo json_encode(['ai_id' => $pdo->lastInsertId(), 'name' => $formatted]);
    exit;
}

// === HANDLE FORM SUBMISSION ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Use filter_input to avoid undefined index warnings
    $service_id  = (int)($_POST['service_id'] ?? 0);
    $ai_id       = (int)($_POST['ai_id'] ?? 0);
    $stocks      = isset($_POST['stocks']) ? (float)$_POST['stocks'] : 0;
    $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
    $barcode     = trim((string)($_POST['barcode'] ?? '')) ?: null;

    // Server-side validation
    if ($service_id <= 0) {
        $_SESSION['error'] = "Please choose a service type.";
        header("Location: add_inventory.php");
        exit;
    }

    if ($ai_id <= 0) {
        $_SESSION['error'] = "Please select or add an active ingredient.";
        header("Location: add_inventory.php");
        exit;
    }

    if ($stocks <= 0) {
        $_SESSION['error'] = "Please enter a valid stock quantity (greater than 0).";
        header("Location: add_inventory.php");
        exit;
    }

    // Insert with prepared statement
    $stmt = $pdo->prepare("INSERT INTO inventory (service_id, ai_id, stocks, expiry_date, barcode)
                           VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$service_id, $ai_id, $stocks, $expiry_date, $barcode]);

    // Fetch the ai name safely
    $stmt2 = $pdo->prepare("SELECT name FROM active_ingredients WHERE ai_id = ? LIMIT 1");
    $stmt2->execute([$ai_id]);
    $aiName = $stmt2->fetchColumn();
    $aiNameSafe = htmlspecialchars($aiName ?: 'Unknown', ENT_QUOTES, 'UTF-8');

    $_SESSION['success'] = "✅ Bottle added successfully for <strong>{$aiNameSafe}</strong>!";
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
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
:root { --green: #28a745; --dark: #0d3b1e; }
body { background: linear-gradient(135deg, var(--dark), #1a5c38); color: #fff; min-height: 100vh; }
.card { background: rgba(255,255,255,.98); border-radius: 1.5rem; box-shadow: 0 20px 50px rgba(0,0,0,.3); color: #222; }
.form-label { font-weight: 700; color: var(--green); }
.live-search { position: relative; }
#chemResults { position: absolute; top: calc(100% + 0.25rem); left: 0; right: 0; z-index: 1050; background: #fff; border: 1px solid #ccc; border-radius: 0 0 0.75rem 0.75rem; max-height: 200px; overflow-y: auto; box-shadow: 0 6px 18px rgba(0,0,0,0.08); }
.result-item { padding: .5rem .75rem; cursor: pointer; color: #0b2f1a; }
.result-item:hover { background: #e9f7ef; }
.add-new { color: var(--green); font-weight: 600; text-align: center; }
.btn-scan { background: var(--green); border: none; padding: 0.75rem 1.5rem; border-radius: 50px; font-size: 1.05rem; color: #fff; }
.small-muted { color: #6c757d; font-size: 0.9rem; }
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
            <div class="alert alert-danger text-center"><?= htmlspecialchars($_SESSION['error'], ENT_QUOTES, 'UTF-8'); unset($_SESSION['error']); ?></div>
        <?php endif; ?>

        <form method="POST" id="addForm" class="row g-4" novalidate>
            <div class="col-md-6">
                <label class="form-label"><i class="bi bi-briefcase"></i> Service Type</label>
                <select name="service_id" class="form-select form-select-lg" required>
                    <option value="">Choose service...</option>
                    <?php foreach ($services as $s): ?>
                        <option value="<?= (int)$s['service_id'] ?>">
                            <?= htmlspecialchars(ucwords(mb_strtolower($s['service_name'], 'UTF-8')), ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6">
                <label class="form-label"><i class="bi bi-droplet-fill"></i> Active Ingredient</label>
                <div class="live-search">
                    <input type="text" id="chemInput" name="chem_name" class="form-control form-control-lg"
                           placeholder="Type to search or add..." autocomplete="off" required>
                    <div id="chemResults" class="d-none" aria-expanded="false"></div>
                </div>
                <!-- hidden input for selected ai_id. Do not make hidden required; server will validate -->
                <input type="hidden" name="ai_id" id="ai_id">
                <div class="small-muted mt-1">Start typing to search existing ingredients or add a new one.</div>
            </div>

            <div class="col-md-4">
                <label class="form-label"><i class="bi bi-box-seam"></i> Stock (bottles)</label>
                <input type="number" name="stocks" class="form-control form-control-lg" placeholder="10" min="1" step="1" required>
            </div>

            <div class="col-md-4">
                <label class="form-label"><i class="bi bi-calendar-x"></i> Expiry Date</label>
                <input type="date" name="expiry_date" class="form-control form-control-lg">
            </div>

            <div class="col-md-4">
                <label class="form-label"><i class="bi bi-upc-scan"></i> Barcode</label>
                <input type="text" name="barcode" class="form-control form-control-lg" placeholder="Scan or type">
            </div>

            <div class="col-12 text-center mt-4">
                <button type="submit" class="btn btn-scan shadow"><i class="bi bi-plus-circle"></i> Add Bottle</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    const chemInput = document.getElementById('chemInput');
    const chemResults = document.getElementById('chemResults');
    const ai_id = document.getElementById('ai_id');
    const form = document.getElementById('addForm');
    let timeout = null;

    function formatName(name) {
        name = (name || '').trim().toLowerCase();
        if (!name) return '';
        return name.charAt(0).toUpperCase() + name.slice(1);
    }

    // Hide results
    function hideResults() {
        chemResults.classList.add('d-none');
        chemResults.innerHTML = '';
        chemResults.setAttribute('aria-expanded', 'false');
    }

    // Show results container
    function showResults() {
        chemResults.classList.remove('d-none');
        chemResults.setAttribute('aria-expanded', 'true');
    }

    // Select a chemical from results
    function selectChem(id, name) {
        chemInput.value = formatName(name);
        ai_id.value = id;
        hideResults();
    }

    // Add a new chemical via AJAX
    function addNewChem(name) {
        const toSend = formatName(name);
        if (!toSend || toSend.length < 2) {
            alert('Name too short');
            return;
        }
        fetch('?add_chem=' + encodeURIComponent(toSend))
            .then(r => r.json())
            .then(data => {
                if (data && data.error) {
                    alert(data.error);
                    return;
                }
                selectChem(data.ai_id, data.name);
                // slight UX: focus on stocks after adding
                alert('Added "' + data.name + '" successfully!');
            })
            .catch(() => alert('Network error while adding ingredient'));
    }

    // Live search for active ingredients
    chemInput.addEventListener('input', function () {
        clearTimeout(timeout);
        ai_id.value = ''; // reset selected id when user types
        const q = chemInput.value.trim();
        if (!q) return hideResults();

        timeout = setTimeout(() => {
            fetch('?search_chem=' + encodeURIComponent(q))
                .then(r => r.json())
                .then(data => {
                    chemResults.innerHTML = '';
                    if (!Array.isArray(data) || data.length === 0) {
                        const div = document.createElement('div');
                        div.className = 'result-item add-new';
                        div.innerHTML = "<i class='bi bi-plus-circle'></i> Add \"<strong>" + formatName(q) + "</strong>\" as new";
                        div.onclick = () => addNewChem(q);
                        chemResults.appendChild(div);
                    } else {
                        data.forEach(item => {
                            const div = document.createElement('div');
                            div.className = 'result-item';
                            div.textContent = formatName(item.name);
                            div.onclick = () => selectChem(item.ai_id, item.name);
                            chemResults.appendChild(div);
                        });
                        const add = document.createElement('div');
                        add.className = 'result-item add-new';
                        add.innerHTML = "<i class='bi bi-plus-circle'></i> Add \"<strong>" + formatName(q) + "</strong>\" as new";
                        add.onclick = () => addNewChem(q);
                        chemResults.appendChild(add);
                    }
                    showResults();
                })
                .catch(() => {
                    chemResults.innerHTML = '<div class="result-item small-muted">Search failed. Try again.</div>';
                    showResults();
                });
        }, 250);
    });

    // Click outside to close results
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.live-search')) {
            hideResults();
        }
    });

    // Prevent form submission if ai_id is not set
    form.addEventListener('submit', function (e) {
        if (!ai_id.value || parseInt(ai_id.value, 10) <= 0) {
            e.preventDefault();
            alert('Please select or add an active ingredient from the list.');
            chemInput.focus();
        }
    });

    // Expose helper globally for inline click used in server-rendered HTML (if any)
    window.addNewChem = addNewChem;
    window.selectChem = selectChem;
})();
</script>
</body>
</html>
