<?php
// barcode.php → Instant, big, scannable barcode
header('Content-Type: image/png');
header('Cache-Control: no-cache');

$code = $_GET['text'] ?? 'NO BARCODE';
$code = strtoupper(trim($code));

// Simple Code-128 patterns (only what you need)
$p = ['0'=>'212222','1'=>'222122','2'=>'222221','3'=>'121223','4'=>'121322','5'=>'131222','6'=>'122213','7'=>'122312','8'=>'132212','9'=>'221213',
      'A'=>'221312','B'=>'231212','C'=>'112232','D'=>'122132','E'=>'122231','F'=>'113222','G'=>'123122','H'=>'123221','I'=>'223211','J'=>'221132'];

// Build barcode
$data = '211214';  // Start B
$sum = 104;
for ($i = 0; $i < strlen($code); $i++) {
    $c = $code[$i];
    if (!isset($p[$c])) $c = '0'; // fallback
    $val = array_search($p[$c], $p);
    $sum += $val * ($i + 1);
    $data .= $p[$c];
}
$chk = $sum % 103;
$data .= $p[array_keys($p)[$chk]] . '2331112'; // checksum + stop

// Draw
$w = strlen($data) * 6 + 80;
$h = 200;
$img = imagecreate($w, $h);
$white = imagecolorallocate($img, 255,255,255);
$black = imagecolorallocate($img, 0,0,0);
imagefilledrectangle($img, 0,0,$w,$h, $white);

$x = 40;
foreach (str_split($data) as $d) {
    for ($j=0; $j<$d; $j++) {
        imageline($img, $x, 30, $x, 150, $black);
        $x += 3;
    }
    $x += 3;
}

// Text
imagestring($img, 5, ($w - strlen($code)*10)/2, 160, $code, $black);
imagepng($img);
imagedestroy($img);
?>
