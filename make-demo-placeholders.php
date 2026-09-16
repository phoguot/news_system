<?php
/**
 * Script MỘT LẦN — sinh ảnh placeholder khớp mọi đường dẫn media trong
 * data/schema/2026-09-13-seed-demo.sql (path chính + variants thumb/medium/large).
 * Ảnh: gradient 2 màu + chữ ASCII (font GD内置 không render được tiếng Việt có dấu).
 * Chạy: php make-demo-placeholders.php  (sau đó có thể xoá file này)
 */
$sql = file_get_contents(__DIR__ . '/data/schema/2026-09-13-seed-demo.sql');
preg_match_all("/'((?:2026|post\/2026)\/[^']+\.(?:jpg|png))'/", $sql, $m);
$paths = array_values(array_unique($m[1]));
$uploads = __DIR__ . '/public/uploads';
$palette = [[46,110,90],[120,90,60],[70,100,140],[140,80,80],[90,120,70],[110,80,130]];
foreach ($paths as $i => $p) {
    $full = $uploads . '/' . $p;
    @mkdir(dirname($full), 0777, true);
    $ext = pathinfo($p, PATHINFO_EXTENSION);
    [$baseW, $baseH] = match (true) {
        str_contains($p, 'thumb')  => [320, 200],
        str_contains($p, 'medium') => [640, 400],
        str_contains($p, 'large')  => [1280, 720],
        str_contains($p, 'avatar') => [400, 400],
        str_contains($p, 'logo')   => [320, 96],
        default                    => [800, 500],
    };
    [$c1, $c2] = [$palette[$i % count($palette)], $palette[($i + 3) % count($palette)]];
    $im = imagecreatetruecolor($baseW, $baseH);
    if ($ext === 'png') { imagesavealpha($im, true); }
    for ($y = 0; $y < $baseH; $y++) {
        $t = $baseH > 1 ? $y / ($baseH - 1) : 0;
        $col = imagecolorallocate($im,
            (int)($c1[0] + ($c2[0] - $c1[0]) * $t),
            (int)($c1[1] + ($c2[1] - $c1[1]) * $t),
            (int)($c1[2] + ($c2[2] - $c1[2]) * $t));
        imageline($im, 0, $y, $baseW, $y, $col);
    }
    $white = imagecolorallocate($im, 255, 255, 255);
    imagestring($im, 4, 12, (int)($baseH / 2) - 8, 'VAN LANG DEMO', $white);
    $label = basename($p);
    imagestring($im, 3, 12, (int)($baseH / 2) + 6, $label, $white);
    if ($ext === 'png') imagepng($im, $full); else imagejpeg($im, $full, 82);
    imagedestroy($im);
}
echo 'Generated ' . count($paths) . " placeholder files under public/uploads/\n";
