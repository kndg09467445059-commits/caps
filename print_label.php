<?php
$pdo = new PDO("mysql:host=localhost;dbname=inventory;charset=utf8mb4","root","",[
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$id   = $_GET['id']   ?? 0;
$copy = $_GET['copy'] ?? 1;
if (!$id || !is_numeric($id)) die("Invalid ID");

$stmt = $pdo->prepare("
    SELECT i.*, s.service_name, a.name AS active_ingredient
    FROM inventory i
    JOIN services s ON i.service_id = s.service_id
    LEFT JOIN active_ingredients a ON i.ai_id = a.ai_id
    WHERE i.inventory_id = ?
");
$stmt->execute([$id]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$item) die("Item not found!");

// Unique barcode per copy
$base   = $item['barcode'] ?: 'NOBAR';
$unique = $base . "-" . sprintf('%02d', $copy);

// ==================== BARCODE 128B (Optimized SVG) ====================
function barcode128B($text) {
    $patterns = [
        ' '=>'212222','!'=>'222122','"'=>'222221','#'=>'121223','$'=>'121322','%'=>'131222','&'=>'122213','\''=>'122312',
        '('=>'132212',')'=>'221213','*'=>'221312','+'=>'231212',','=>'112232','-'=>'122132','.'=>'122231','/'=>'113222',
        '0'=>'123122','1'=>'123221','2'=>'223211','3'=>'221132','4'=>'221231','5'=>'213212','6'=>'223112','7'=>'312131',
        '8'=>'311222','9'=>'321122',':'=>'321221',';'=>'312212','<'=>'322112','='=>'322211','>'=>'212123','?'=>'212321',
        '@'=>'232121','A'=>'111323','B'=>'131123','C'=>'131321','D'=>'112313','E'=>'132113','F'=>'132311','G'=>'211313',
        'H'=>'231113','I'=>'231311','J'=>'112133','K'=>'112331','L'=>'132131','M'=>'113123','N'=>'113321','O'=>'133121',
        'P'=>'313121','Q'=>'211331','R'=>'231131','S'=>'213113','T'=>'213311','U'=>'213131','V'=>'311123','W'=>'311321',
        'X'=>'331121','Y'=>'312113','Z'=>'312311','['=>'332111','\\'=>'314111',']'=>'221411','^'=>'431111','_'=>'111224'
    ];

    $start = '211214';
    $stop  = '2331112';
    $code  = $start;
    foreach (str_split($text) as $c) {
        $code .= $patterns[$c] ?? $patterns[' '];
    }
    $code .= $stop;

    $bw = 1.5; // bar width
    $h  = 50;  // barcode height
    $x  = 8;
    $svg = '<svg viewBox="0 0 230 80" xmlns="http://www.w3.org/2000/svg">';
    $svg .= '<rect width="230" height="80" fill="white"/>';

    foreach (str_split($code) as $i => $w) {
        if ($i % 2 == 0) {
            $svg .= "<rect x='$x' y='10' width='" . ($w * $bw) . "' height='$h' fill='black'/>";
        }
        $x += $w * $bw;
    }

    $svg .= "<text x='115' y='72' font-family='monospace' font-size='9' text-anchor='middle' fill='#000'>$text</text>";
    $svg .= '</svg>';
    return $svg;
}

$barcodeSVG = barcode128B($unique);
$ai = strtoupper($item['active_ingredient'] ?: 'CHEMICAL');
$service = strtoupper($item['service_name']);
$year = date('Y');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>LABEL <?= $unique ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        @page {
            size: 80mm 50mm;
            margin: 0;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 0;
            font-family: 'Courier New', monospace;
            background: #fff;
        }
        .label {
            width: 80mm;
            height: 50mm;
            border: 2px solid #000;
            padding: 2mm;
            page-break-after: always;
            position: relative;
            background: white;
        }
        .header {
            background: #000;
            color: #fff;
            text-align: center;
            padding: 1.5mm 0;
            font-weight: bold;
            font-size: 10pt;
            letter-spacing: 1px;
            margin: -2mm -2mm 2mm;
            clip-path: polygon(0 0, 100% 0, 100% 80%, 0 100%);
        }
        .ai {
            font-size: 11pt;
            font-weight: bold;
            text-align: center;
            margin: 1.5mm 0 1mm;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .barcode {
            text-align: center;
            margin: 1mm 0;
        }
        .barcode svg {
            width: 100%;
            height: 48px;
        }
        .info {
            font-size: 8.5pt;
            line-height: 1.3;
            margin-top: 1mm;
        }
        .info div {
            display: flex;
            justify-content: space-between;
            padding: 0.3mm 0;
        }
        .copy {
            position: absolute;
            top: 1mm;
            right: 2mm;
            background: #ffeb3b;
            color: #000;
            padding: 0.8mm 2.5mm;
            border: 1px solid #000;
            font-size: 7pt;
            font-weight: bold;
            border-radius: 3px;
        }
        .footer {
            position: absolute;
            bottom: 1.5mm;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 6.5pt;
            color: #444;
        }
        @media print {
            body { padding: 0 !important; }
            .no-print { display: none; }
        }
    </style>
</head>
<body onload="setTimeout(() => window.print(), 600)">

<div class="label">
    <div class="copy">COPY <?= sprintf('%02d', $copy) ?></div>

    <div class="header"><?= $ai ?></div>

    <div class="ai"><?= $ai ?></div>

    <div class="barcode"><?= $barcodeSVG ?></div>

    <div class="info">
        <div><b>SERVICE:</b> <span><?= $service ?></span></div>
        <div><b>STOCK:</b> <span><?= number_format($item['stocks'], 1) ?> bottle<?= $item['stocks'] == 1 ? '' : 's' ?></span></div>
        <?php if (!empty($item['expiry_date'])): ?>
        <div><b>EXP:</b> <span><?= date('M Y', strtotime($item['expiry_date'])) ?></span></div>
        <?php endif; ?>
    </div>

    <div class="footer">
        <?= $base ?> • <?= $year ?> • SCAN TO VERIFY
    </div>
</div>

<!-- Print multiple copies if needed -->
<?php for ($i = 2; $i <= $copy; $i++): ?>
    <?php $uniqueCopy = $base . "-" . sprintf('%02d', $i); ?>
    <div class="label">
        <div class="copy">COPY <?= sprintf('%02d', $i) ?></div>
        <div class="header"><?= $ai ?></div>
        <div class="ai"><?= $ai ?></div>
        <div class="barcode"><?= barcode128B($uniqueCopy) ?></div>
        <div class="info">
            <div><b>SERVICE:</b> <span><?= $service ?></span></div>
            <div><b>STOCK:</b> <span><?= number_format($item['stocks'], 1) ?> bottle<?= $item['stocks'] == 1 ? '' : 's' ?></span></div>
            <?php if (!empty($item['expiry_date'])): ?>
            <div><b>EXP:</b> <span><?= date('M Y', strtotime($item['expiry_date'])) ?></span></div>
            <?php endif; ?>
        </div>
        <div class="footer"><?= $base ?> • <?= $year ?> • SCAN TO VERIFY</div>
    </div>
<?php endfor; ?>

</body>
</html>
