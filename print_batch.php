<?php
$pdo = new PDO("mysql:host=localhost;dbname=inventory;charset=utf8mb4", "root", "", [PDO::ATTR_ERRMODE_EXCEPTION]);
$items = $pdo->query("SELECT inventory_id FROM inventory WHERE barcode IS NOT NULL ORDER BY inventory_id DESC LIMIT 50")->fetchAll();
?>
<!DOCTYPE html>
<html>
<head><title>Batch Print</title><style>@page{margin:2mm}</style></head>
<body onload="window.print()">
<?php foreach($items as $i):?>
<iframe src="print_label.php?id=<?=$i['inventory_id']?>" style="width:80mm;height:50mm;border:none"></iframe>
<?php endforeach;?>
</body>
</html>
