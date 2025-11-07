<?php
function generateBarcode($code, $file) {
    // Code 128 B table (value => pattern)
    $table = [
        32=>212222, 33=>222122, 34=>222221, 35=>121223, 36=>121322, 37=>131222, 38=>122213, 39=>122312,
        40=>132212, 41=>221213, 42=>221312, 43=>231212, 44=>112232, 45=>122132, 46=>122231, 47=>112322,
        48=>132122, 49=>132221, 50=>122123, 51=>122321, 52=>126203, 53=>232121, 54=>212123, 55=>212321,
        56=>232123, 57=>123222, 58=>123321, 59=>133221, 60=>123123, 61=>123222, 62=>223222, 63=>112223,
        64=>112322, 65=>114212, 66=>124112, 67=>124211, 68=>411212, 69=>421112, 70=>421211, 71=>212141,
        72=>214121, 73=>412121, 74=>214211, 75=>411221, 76=>421121, 77=>211214, 78=>211412, 79=>421211,
        80=>211241, 81=>214211, 82=>411212, 83=>421112, 84=>421211, 85=>212114, 86=>214112, 87=>412112,
        88=>214211, 89=>411211, 90=>421111, 91=>211212, 92=>211214, 93=>211232, 94=>2331112, 95=>112412,
        96=>112421, 97=>112214, 98=>112412, 99=>112421,100=>114112,101=>114211,102=>411211,103=>421111,
        104=>212112,105=>212211,106=>211211,107=>211212,108=>211214,109=>211232,110=>2331112
    ];

    // Start B (104) + data
    $sum = 104;
    $pattern = '211214'; // START B

    for ($i = 0; $i < strlen($code); $i++) {
        $ord = ord($code[$i]);
        $value = $ord >= 32 && $ord <= 126 ? $ord : 32; // fallback space
        $sum += $value * ($i + 1);
        $pattern .= str_pad($table[$value], 6, '0');
    }

    // Checksum
    $checksum = $table[$sum % 103];
    $pattern .= str_pad($checksum, 6, '0') . '2331112'; // STOP

    // Build SVG
    $barWidth = 2;
    $height = 100;
    $width = (strlen($pattern) * $barWidth) + 40;
    $svg = '<?xml version="1.0" encoding="UTF-8"?>';
    $svg .= '<svg width="'.$width.'" height="'.($height+30).'" xmlns="http://www.w3.org/2000/svg">';
    $svg .= '<rect width="100%" height="100%" fill="white"/>';

    $x = 20;
    foreach (str_split($pattern) as $digit) {
        $thick = (int)$digit;
        if ($thick > 0) {
            $svg .= '<rect x="'.$x.'" y="10" width="'.($thick*$barWidth).'" height="'.$height.'" fill="black"/>';
        }
        $x += 6 * $barWidth;
    }

    // Text below
    $svg .= '<text x="'.($width/2).'" y="'.($height+25).'" font-family="Arial" font-size="16" text-anchor="middle">'.$code.'</text>';
    $svg .= '</svg>';

    file_put_contents($file, $svg);
}
?>
