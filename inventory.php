'<?php
session_start();
header('Content-Type: text/html; charset=utf-8');

// Helper for safely escaping output in HTML attributes and content
function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$pdo = new PDO("mysql:host=localhost;dbname=inventory;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

/* ==================== SAVE EDIT ==================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'save')) {
    // use null coalescing to avoid undefined index notices
    $id      = (int)($_POST['id'] ?? 0);
    $stocks  = (float)($_POST['stocks'] ?? 0);
    $expiry  = $_POST['expiry'] ?? null;
    $barcode = trim((string)($_POST['barcode'] ?? '')) ?: null;

    $stmt = $pdo->prepare("UPDATE inventory SET stocks = ?, expiry_date = ?, barcode = ? WHERE inventory_id = ?");
    $stmt->execute([$stocks, $expiry, $barcode, $id]);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true]);
    exit;
}

/* ==================== ROTATION ==================== */
$termite_ingredients   = ['Fipronil', 'Bifenthrin', 'Imidacloprid'];
$pest_control_ingredients = ['Lambda-Cyhalothrin', 'Beta-Cyfluthrin', 'Cypermethrin', 'Deltamethrin'];

$month = date('n');
$rotation_period = floor(($month - 1) / 3);
$quarters = ["Jan - Mar", "Apr - Jun", "Jul - Sep", "Oct - Dec"]; 
$current_period = $quarters[$rotation_period] . " " . date('Y');

/* ==================== INVENTORY ==================== */
$inventory = $pdo->query("\n    SELECT i.inventory_id, s.service_name, a.name AS active_ingredient, i.stocks, i.expiry_date, i.barcode\n    FROM inventory i\n    JOIN services s ON i.service_id = s.service_id\n    LEFT JOIN active_ingredients a ON i.ai_id = a.ai_id\n    ORDER BY a.name, i.expiry_date\n")->fetchAll();

/* ==================== CURRENT CHEMICALS ==================== */
$termite_items = array_filter($inventory, fn($i)=>in_array(trim($i['active_ingredient'] ?? ''), $termite_ingredients));
$pest_items    = array_filter($inventory, fn($i)=>in_array(trim($i['active_ingredient'] ?? ''), $pest_control_ingredients));

$current_termite = !empty($termite_items)
    ? array_values($termite_items)[$rotation_period % count($termite_items)]['active_ingredient']
    : 'None Selected';

$current_pest = !empty($pest_items)
    ? array_values($pest_items)[$rotation_period % count($pest_items)]['active_ingredient']
    : 'None Selected';

/* ==================== STOCK ALERTS ==================== */
$low_stock_items = array_filter($inventory, fn($i) => (float)$i['stocks'] < 10);
$empty_items     = array_filter($inventory, fn($i) => (float)$i['stocks'] <= 0);

$has_low  = !empty($low_stock_items);
$has_empty = !empty($empty_items);
$alert_count = count($low_stock_items);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PEST CONTROL - Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #0d6efd;
            --success: #198754;
            --danger: #dc3545;
            --warning: #ffc107;
            --dark: #212529;
            --light: #f8f9fa;
            --card-bg: rgba(255,255,255,.95);
        }
        body {
            background: linear-gradient(135deg, #0f3b1e 0%, #1a5d38 100%);
            color: #fff;
            min-height: 100vh;
            font-family: 'Segoe UI', sans-serif;
        }
        .glass-card {
            background: var(--card-bg);
            backdrop-filter: blur(12px);
            border-radius: 1.5rem;
            box-shadow: 0 20px 50px rgba(0,0,0,.5);
            border: 1px solid rgba(255,255,255,.15);
            overflow: hidden;
        }
        .header-wave {
            position: relative;
            overflow: hidden;
            border-radius: 1.5rem 1.5rem 0 0;
            background: linear-gradient(120deg, var(--success), #2ecc71);
            padding: 3rem 2rem;
            text-align: center;
        }
        .header-wave::before {
            content: '';\n            position: absolute;
            bottom: -30px;
            left: 0;
            width: 100%;
            height: 80px;
            background: var(--card-bg);
            clip-path: ellipse(70% 50% at 50% 100%);
        }
        .rotate-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: #fff;
            color: var(--success);
            font-weight: 800;
            padding: .5rem 1rem;
            border-radius: 2rem;
            box-shadow: 0 4px 15px rgba(0,0,0,.2);
            animation: pulse 3s infinite;
        }
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        .chemical-card {
            background: rgba(255,255,255,.9);
            border-radius: 1rem;
            overflow: hidden;
            transition: transform .3s, box-shadow .3s;
        }
        .chemical-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(0,0,0,.3);
        }
        .chemical-icon {
            font-size: 3.5rem;
            opacity: .9;
        }
        .stock-chip {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            padding: .4rem .8rem;
            border-radius: 2rem;
            font-size: .85rem;
            font-weight: 600;
        }
        .table {
            background: #fff;
            border-radius: 1rem;
            overflow: hidden;
            box-shadow: 0 8px 25px rgba(0,0,0,.15);
        }
        .table thead {
            background: var(--success);
            color: #fff;
        }
        .back-btn {
            position: fixed;
            top: 1.5rem;
            right: 1.5rem;
            z-index: 1050;
            background: rgba(255,255,255,.2);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,.3);
            padding: .75rem 1.25rem;
            border-radius: 50px;
            transition: all .3s;
        }
        .back-btn:hover {
            background: #fff;
            color: var(--dark);
            transform: translateY(-3px);
        }
        #stockAlertBanner {
            position: fixed;
            top: 0; left: 0; right: 0;
            z-index: 9999;
            background: rgba(220,53,69,.95);
            backdrop-filter: blur(10px);
            border-bottom: 4px solid #fff;
            animation: slideDown 0.7s ease-out;
            box-shadow: 0 10px 30px rgba(0,0,0,.4);
        }
        .alert-icon {
            animation: shake 0.6s infinite;
        }
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }
        .ripple {
            position: absolute;
            border-radius: 50%;
            background: rgba(255,255,255,.5);
            transform: scale(0);
            animation: ripple 0.7s ease-out;
            pointer-events: none;
            inset: 0;
        }
        @keyframes ripple {
            to { transform: scale(5); opacity: 0; }
        }
        .tracking-widest { letter-spacing: 3px; }
        #countdown { font-weight: 800; color: #198754; }
        .neumorphic-form .form-control {
            transition: all .3s ease;
            border: 3px solid transparent !important;
        }
        .neumorphic-form .form-control:focus {
            border-color: #198754 !important;
            box-shadow: 0 0 0 5px rgba(25,135,84,.2), inset 0 5px 15px rgba(0,0,0,.1) !important;
            transform: translateY(-4px);
        }
    </style>
</head>
<body class="pb-5">

<!-- ===================== STOCK ALERT BANNER ===================== -->
<?php if ($has_low || $has_empty): ?>
<div id="stockAlertBanner" class="py-3">
    <div class="container d-flex align-items-center justify-content-center gap-4 flex-wrap text-white">
        <i class="bi bi-exclamation-triangle-fill fs-2 alert-icon"></i>
        <div class="fs-5 fw-bold">
            <?= $has_empty ? count($empty_items) : 0 ?> <span class="text-danger">EMPTY</span>
            <?= $has_empty && $has_low ? ' | ' : '' ?>
            <?= $has_low ? ($alert_count - count($empty_items)) : 0 ?> <span class="text-warning">LOW STOCK</span>
        </div>
        <span class="opacity-75">— Scroll down for details</span>
        <button class="btn btn-outline-light btn-sm rounded-pill px-3" onclick="document.getElementById('stockAlertBanner').remove()">
            <i class="bi bi-x-lg"></i> Dismiss
        </button>
    </div>
</div>
<script>
    const beep = new Audio('data:audio/wav;base64,UklGRiQAAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQAAAAA=');
    window.addEventListener('load', () => beep.play().catch(()=>{}));
</script>
<?php endif; ?>

<div class="container py-5 position-relative">

    <!-- ===================== BACK BUTTON ===================== -->
    <a href="dashboard.php" class="back-btn shadow-lg d-flex align-items-center gap-2 text-white">
        <i class="bi bi-house-door-fill fs-4"></i> Back to Dashboard
    </a>

    <!-- ===================== MAIN CARD ===================== -->
    <div class="glass-card mt-5">

        <!-- Header Wave -->
        <div class="header-wave">
            <h1 class="display-3 fw-black text-white mb-0">
                <i class="bi bi-shield-bug"></i> PEST CONTROL
            </h1>
            <p class="lead mb-0 mt-2 opacity-90">Smart Rotation Dashboard</p>
            <div class="rotate-badge">Q<?= (int)$rotation_period + 1 ?> • <?= h($current_period) ?></div>
        </div>

        <div class="p-5">

            <!-- Low Stock Grid -->
            <?php if ($has_low): ?>
            <div class="bg-gradient bg-warning bg-opacity-10 rounded-4 p-4 mb-5 border border-warning border-3">
                <h4 class="text-warning mb-3"><i class="bi bi-bell-fill"></i> Critical Stock Alert (<?= $alert_count ?>)</h4>
                <div class="row g-3">
                    <?php foreach ($low_stock_items as $item):
                        $stock = (float)$item['stocks'];
                        $icon = $stock <= 0 ? 'bi-x-octagon-fill' : 'bi-exclamation-triangle-fill';
                        $color = $stock <= 0 ? 'danger' : 'warning';
                    ?>
                    <div class="col-lg-3 col-md-4 col-6">
                        <div class="d-flex align-items-center gap-3 p-3 bg-white rounded-3 shadow-sm">
                            <i class="bi <?= h($icon) ?> text-<?= h($color) ?> fs-3"></i>
                            <div>
                                <div class="fw-bold small text-dark"><?= h($item['active_ingredient'] ?: $item['service_name']) ?></div>
                                <div class="text-muted small"><?= number_format($stock,1) ?> bottle<?= $stock==1?'':'s' ?></div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Current Chemicals -->
            <div class="row g-4 mb-5">
                <div class="col-md-6">
                    <div class="chemical-card text-center p-5 border-4 border-success border-start">
                        <i class="bi bi-bug chemical-icon text-success mb-3"></i>
                        <h4 class="text-success">TERMITE CONTROL</h4>
                        <h2 class="fw-black text-dark"><?= h(ucwords(strtolower($current_termite))) ?></h2>
                        <span class="stock-chip bg-success text-white mt-3">
                            <i class="bi bi-check2-circle"></i> ACTIVE THIS QUARTER
                        </span>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="chemical-card text-center p-5 border-4 border-primary border-start">
                        <i class="bi bi-flower2 chemical-icon text-primary mb-3"></i>
                        <h4 class="text-primary">GENERAL PEST</h4>
                        <h2 class="fw-black text-dark"><?= h(ucwords(strtolower($current_pest))) ?></h2>
                        <span class="stock-chip bg-primary text-white mt-3">
                            <i class="bi bi-check2-circle"></i> ACTIVE THIS QUARTER
                        </span>
                    </div>
                </div>
            </div>

            <!-- Inventory Section -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3 class="text-success mb-0 d-flex align-items-center gap-3">
                    <i class="bi bi-box-seam fs-2"></i> Full Inventory
                </h3>
                <a href="add_inventory.php" class="btn btn-success rounded-pill px-4 shadow">
                    <i class="bi bi-plus-circle-fill"></i> Add New
                </a>
            </div>

            <div class="table-responsive">
            <table class="table table-hover mb-0">
            <thead>
            <tr>
                <th>Service</th>
                <th>Ingredient</th>
                <th class="text-center">Bottles</th>
                <th class="text-center">Expiry</th>
                <th>Barcode</th>
                <th class="text-center">Status</th>
                <th class="text-center">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($inventory as $i):
                $stock = (float)$i['stocks'];
                $is_expired = !empty($i['expiry_date']) && strtotime($i['expiry_date']) < time();
                $near_exp = !empty($i['expiry_date']) && strtotime($i['expiry_date']) < strtotime('+1 month') && !$is_expired;
                $exp_display = !empty($i['expiry_date']) ? date('Y-m-d', strtotime($i['expiry_date'])) : '';
                $ingredient = ucwords(strtolower($i['active_ingredient'] ?? ''));
            ?>
            <tr class="<?= $is_expired || $stock<=0 ? 'table-danger' : ($stock<10 ? 'table-warning' : '') ?>">
                <td class="fw-semibold"><?= h($i['service_name']) ?></td>
                <td><?= h($ingredient ?: '—') ?></td>
                <td class="text-center">
                    <?= number_format($stock,1) ?>
                    <?php if($stock<=0): ?>
                        <span class="badge bg-danger ms-2">EMPTY</span>
                    <?php elseif($stock<10): ?>
                        <span class="badge bg-warning text-dark ms-2">LOW</span>
                    <?php else: ?>
                        <span class="badge bg-success ms-2">OK</span>
                    <?php endif; ?>
                </td>
                <td class="text-center">
                    <?= h($exp_display ?: '—') ?>
                    <?php if($is_expired): ?>
                        <span class="badge bg-danger ms-2">EXPIRED</span>
                    <?php elseif($near_exp): ?>
                        <span class="badge bg-warning text-dark ms-2">NEAR EXP</span>
                    <?php endif; ?>
                </td>
                <td><code><?= h($i['barcode'] ?? '—') ?></code></td>
                <td class="text-center">
                    <?php if($is_expired || $stock<=0): ?>
                        <i class="bi bi-x-circle-fill text-danger fs-4"></i>
                    <?php elseif($stock<10): ?>
                        <i class="bi bi-exclamation-triangle-fill text-warning fs-4"></i>
                    <?php else: ?>
                        <i class="bi bi-check-circle-fill text-success fs-4"></i>
                    <?php endif; ?>
                </td>
                <td class="text-center">
                    <div class="btn-group" role="group">
                        <button class="btn btn-outline-success btn-sm edit-btn"
                            data-id="<?= (int)$i['inventory_id'] ?>"
                            data-service="<?= h($i['service_name']) ?>"
                            data-ingredient="<?= h($ingredient) ?>"
                            data-stocks="<?= h($stock) ?>"
                            data-expiry="<?= h($exp_display) ?>"
                            data-barcode="<?= h($i['barcode'] ?? '') ?>">
                            <i class="bi bi-pencil-square"></i>
                        </button>
                        <?php if (!empty($i['barcode'])): ?>
                        <button class="btn btn-outline-primary btn-sm print-btn"
                            onclick="window.open('print_label.php?id=<?= (int)$i['inventory_id'] ?>&copy=1','_blank')">
                            <i class="bi bi-printer"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($inventory)): ?>
            <tr><td colspan="7" class="text-center py-6 text-muted fs-4">No inventory items yet.</td></tr>
            <?php endif; ?>
            </tbody>
            </table>
            </div>

        </div>
    </div>
</div>

<!-- =============== 2025 PRO EDIT MODAL – GLASS + NEUMORPHIC =============== -->
<div class="modal fade" id="editModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content overflow-hidden border-0" style="border-radius: 2rem; box-shadow: 0 30px 80px rgba(0,0,0,.7);">

      <!-- Floating Wave Header -->
      <div class="position-relative">
        <div class="text-white text-center pt-5 pb-7 px-4" style="
          background: linear-gradient(135deg, #16a085, #2ecc71);
          border-radius: 2rem 2rem 0 0;
          clip-path: polygon(0 0, 100% 0, 100% 85%, 0 100%);
        ">
          <div class="d-inline-flex align-items-center justify-content-center bg-white text-success rounded-circle mb-3"
               style="width: 80px; height: 80px; box-shadow: 0 10px 30px rgba(0,0,0,.3);">
            <i class="bi bi-shield-fill-exclamation fs-1"></i>
          </div>
          <h3 class="fw-black mb-1">EDIT BOTTLE</h3>
          <p class="lead mb-0 opacity-90">Instant sync • Real-time stock update</p>
        </div>
        <button type="button" class="btn-close btn-close-white position-absolute top-0 end-0 m-4" data-bs-dismiss="modal"
                style="filter: drop-shadow(0 2px 8px rgba(0,0,0,.4)); font-size: 1.4rem;"></button>
      </div>

      <!-- Body -->
      <div class="p-5 pt-1" style="margin-top: -5rem;">
        <form id="editForm" class="neumorphic-form">

          <input type="hidden" name="id" id="edit_id">

          <!-- Preview Cards -->
          <div class="row g-4 mb-5">
            <div class="col-md-6">
              <div class="text-center p-4 rounded-4 text-white"
                   style="background: linear-gradient(135deg, #198754, #20c997); box-shadow: 0 10px 25px rgba(25,135,84,.4);">
                <small class="fw-bold text-uppercase tracking-widest d-block mb-2 opacity-90">Service</small>
                <p class="fs-4 fw-black mb-0" id="modal_service">—</p>
              </div>
            </div>
            <div class="col-md-6">
              <div class="text-center p-4 rounded-4 text-white"
                   style="background: linear-gradient(135deg, #0d6efd, #6c757d); box-shadow: 0 10px 25px rgba(13,110,253,.4);">
                <small class="fw-bold text-uppercase tracking-widest d-block mb-2 opacity-90">Active Ingredient</small>
                <p class="fs-4 fw-black mb-0" id="modal_ingredient">—</p>
              </div>
            </div>
          </div>

          <!-- Inputs -->
          <div class="row g-4">
            <div class="col-md-4">
              <div class="form-floating position-relative">
                <input type="number" step="0.1" min="0" name="stocks" id="edit_stocks"
                       class="form-control form-control-lg rounded-4 border-0 shadow-lg"
                       style="background: rgba(255,255,255,.97); backdrop-filter: blur(12px); height: 68px;" required>
                <label class="text-success fw-bold">
                  <i class="bi bi-droplet-fill"></i> Bottles
                </label>
              </div>
            </div>
            <div class="col-md-4">
              <div class="form-floating position-relative">
                <input type="date" name="expiry" id="edit_expiry"
                       class="form-control form-control-lg rounded-4 border-0 shadow-lg"
                       style="background: rgba(255,255,255,.97); backdrop-filter: blur(12px); height: 68px;">
                <label class="text-warning fw-bold">
                  <i class="bi bi-calendar-x"></i> Expiry
                </label>
              </div>
            </div>
            <div class="col-md-4">
              <div class="form-floating position-relative">
                <input type="text" name="barcode" id="edit_barcode"
                       class="form-control form-control-lg rounded-4 border-0 shadow-lg"
                       style="background: rgba(255,255,255,.97); backdrop-filter: blur(12px); height: 68px;"
                       placeholder="Scan or type">
                <label class="text-primary fw-bold">
                  <i class="bi bi-upc-scan"></i> Barcode
                </label>
              </div>
            </div>
          </div>

          <!-- Save Button -->
          <div class="text-center mt-5">
            <button type="submit" class="btn btn-success btn-lg px-5 py-4 rounded-pill shadow-lg position-relative overflow-hidden
                                          d-inline-flex align-items-center gap-3 fw-black"
                    style="font-size: 1.3rem; min-width: 280px;">
              <span class="position-relative z-3">
                <i class="bi bi-cloud-check-fill fs-4"></i>
                SAVE & SYNC
              </span>
              <div class="ripple"></div>
            </button>
            <p class="text-muted small mt-3 mb-0">
              <i class="bi bi-arrow-repeat"></i> Dashboard refreshes in <span id="countdown">0.8</span>s
            </p>
          </div>

        </form>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // Ripple + Countdown
  document.querySelector('#editForm button[type="submit"]')?.addEventListener('click', function(e) {
    const btn = e.currentTarget;
    const ripple = document.createElement('div');
    ripple.classList.add('ripple');
    btn.appendChild(ripple);
    setTimeout(() => ripple.remove(), 700);

    let time = 0.8;
    const cd = document.getElementById('countdown');
    const interval = setInterval(() => {
      time -= 0.1;
      cd.textContent = time.toFixed(1);
      if (time <= 0) clearInterval(interval);
    }, 100);
  });

  // Open Modal
  document.querySelectorAll('.edit-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const m = document.getElementById('editModal');
      ['id', 'stocks', 'expiry', 'barcode'].forEach(field => {
        m.querySelector(`#edit_${field}`).value = btn.dataset[field] || '';
      });
      m.querySelector('#modal_service').textContent = btn.dataset.service;
      m.querySelector('#modal_ingredient').textContent = btn.dataset.ingredient || '—';
      new bootstrap.Modal(m, { backdrop: 'static' }).show();
    });
  });

  // Save
  document.getElementById('editForm').addEventListener('submit', async e => {
    e.preventDefault();
    const btn = e.target.querySelector('button');
    const orig = btn.innerHTML;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> SYNCING...';
    btn.disabled = true;

    const f = new FormData(e.target);
    f.append('action', 'save');

    try {
      const r = await fetch('', { method: 'POST', body: f });
      if ((await r.json()).success) {
        btn.innerHTML = '<i class="bi bi-check2-all"></i> SYNCED!';
        setTimeout(() => location.reload(), 800);
      }
    } catch {
      btn.innerHTML = '<i class="bi bi-x-circle"></i> FAILED';
      setTimeout(() => { btn.innerHTML = orig; btn.disabled = false; }, 2000);
    }
  });

  // Auto-refresh every 5 mins
  setTimeout(() => location.reload(), 5*60*1000);
</script>
</body>
</html>'
