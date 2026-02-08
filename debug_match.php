<?php
require 'php_api_common.php';

function super_clean($s) {
    echo "  [Clean] Original: '$s'\n";
    $s = preg_replace('/[\(（].*?[\)）]/u', '', $s);
    $s = preg_replace('/\b(20\d{2})\b/u', '', $s);
    $s = str_replace(['·', ' ', '-', '_'], '', $s);
    $res = mb_strtolower(trim($s), 'UTF-8');
    echo "  [Clean] Result:   '$res'\n";
    return $res;
}

$qTitleRaw = "晚点告白";
$qArtistRaw = "葛雨晴";

echo "=== Debug Matching for: $qTitleRaw - $qArtistRaw ===\n";

$qTitle = super_clean($qTitleRaw);
$qArtist = super_clean($qArtistRaw);

foreach (['wyy', 'qq'] as $plat) {
    echo "\n--- Testing Platform: $plat ---\n";
    $keyword = "$qTitleRaw $qArtistRaw";
    $url = "http://127.0.0.1:8002/search?keyword=" . urlencode($keyword) . "&limit=5&platform=$plat";
    echo "Requesting: $url\n";
    
    $resp = file_get_contents($url);
    $results = json_decode($resp, true);
    
    if (!is_array($results)) {
        echo "Error: Search API returned non-array: $resp\n";
        continue;
    }

    foreach ($results as $i => $res) {
        $rTitleRaw = $res['name'];
        $rArtistRaw = $res['artist'];
        
        echo "Result [$i]: $rTitleRaw - $rArtistRaw\n";
        
        $rTitle = super_clean($rTitleRaw);
        $rArtist = super_clean($rArtistRaw);
        
        $titleMatch = ($qTitle !== '' && (strpos($rTitle, $qTitle) !== false || strpos($qTitle, $rTitle) !== false));
        $artistMatch = ($qArtist !== '' && (strpos($rArtist, $qArtist) !== false || strpos($qArtist, $rArtist) !== false));
        
        echo "  Check Title: " . ($titleMatch ? "YES" : "NO") . " ('$rTitle' vs '$qTitle')\n";
        echo "  Check Artist: " . ($artistMatch ? "YES" : "NO") . " ('$rArtist' vs '$qArtist')\n";
        
        if ($titleMatch && $artistMatch) {
            echo "  >>> PERFECT MATCH FOUND! <<<
";
            break;
        }
    }
}
