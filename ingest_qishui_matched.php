<?php
require 'php_api_common.php';

if (PHP_SAPI !== 'cli') die("CLI only");

echo "=== Qishui Matching Engine V8 (Deduplication & Detailed Logging) ===\n";

$pdo = pdo_connect_from_env();

function super_clean($s) {
    if (!$s) return '';
    $s = preg_replace('/[\(（].*?[\)）]/u', '', (string)$s);
    $s = str_replace(['·', ' ', '-', '_'], '', $s);
    return mb_strtolower(trim($s), 'UTF-8');
}

$count = isset($argv[1]) ? (int)$argv[1] : 20;
$qishuiUrl = "http://127.0.0.1:8372/feed?count=$count";
echo "Fetching $count tracks from Qishui...\n";
$resp = @file_get_contents($qishuiUrl);
if (!$resp) die("Error: Qishui API unreachable.\n");

$feedJson = json_decode($resp, true);
$data = $feedJson['data'] ?? [];

foreach ($data as $item) {
    $rawTitle = trim((string)$item['name']);
    $rawArtist = trim((string)$item['artist']);
    if (!$rawTitle) continue;

    $qTitle = super_clean($rawTitle);
    $qArtist = super_clean($rawArtist);
    
    echo "Processing: $rawTitle - $rawArtist ... ";

    $match = null;
    $keywords = ["$rawTitle $rawArtist", $rawTitle];

    foreach ($keywords as $idx => $kw) {
        if ($match) break;
        foreach (['qq', 'wyy'] as $plat) {
            $searchUrl = "http://127.0.0.1:8002/search?keyword=" . urlencode($kw) . "&limit=10&platform=$plat";
            $sResp = @file_get_contents($searchUrl);
            if (!$sResp) continue;
            
            $searchJson = json_decode($sResp, true);
            $list = $searchJson['data']['list'] ?? [];
            if (!is_array($list)) continue;

            foreach ($list as $res) {
                $rName = (string)($res['name'] ?? $res['songname'] ?? $res['title'] ?? '');
                $rArtist = (string)($res['artist'] ?? $res['singer'] ?? $res['subtitle'] ?? '');
                $cName = super_clean($rName);
                $cArtist = super_clean($rArtist);

                if ($cName === $qTitle || strpos($cName, $qTitle) !== false || strpos($qTitle, $cName) !== false) {
                    $match = [
                        'source' => $plat,
                        'id' => (string)($res['mid'] ?? $res['id'] ?? $res['songmid'] ?? ''),
                        'name' => $rName,
                        'artist' => $rArtist,
                        'share_url' => (string)($res['share_url'] ?? $res['url'] ?? ''),
                        'cover_url' => (string)($res['cover'] ?? $res['cover_url'] ?? $res['pic'] ?? '')
                    ];
                    if ($match['id'] !== '') break 3; 
                }
            }
        }
    }

    if ($match && $match['id'] !== '') {
        echo "MATCHED on {$match['source']}\n";
        
        // 我们巧妙地把汽水原始信息存入 subtitle，格式为：匹配歌手 |QS| 原始标题 - 原始歌手
        $combinedSubtitle = $match['artist'] . " |QS| " . $rawTitle . " - " . $rawArtist;

        $sql = "INSERT INTO music_home_items (source, section, item_type, item_id, title, subtitle, original_share_url, original_cover_url) 
                VALUES (?, 'matchedFeed', 'song', ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE title=VALUES(title), subtitle=VALUES(subtitle)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $match['source'],
            $match['id'],
            $match['name'],
            $combinedSubtitle,
            $match['share_url'],
            $match['cover_url']
        ]);
    } else {
        echo "NOT FOUND.\n";
    }
}
echo "Done.\n";
