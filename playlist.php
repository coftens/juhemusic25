<?php
declare(strict_types=1);
error_reporting(0);
ini_set('display_errors', '0');

require __DIR__ . '/php_api_common.php';

function get_param_pl(string $k) { return isset($_GET[$k]) ? $_GET[$k] : ''; }

function http_get_pl($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: PHP-Proxy']); 
    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}

function http_get_qq_pl($url, $cookie='') {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        'Referer: https://y.qq.com/',
    ]);
    if ($cookie) {
        curl_setopt($ch, CURLOPT_COOKIE, $cookie);
    }
    $res = curl_exec($ch);
    curl_close($ch);
    return (string)$res;
}

try {
    $source = get_param_pl('source');
    $id = get_param_pl('id');

    if (!$id) {
        header('Content-Type: application/json');
        echo json_encode(['code'=>400, 'msg'=>'missing id']);
        exit;
    }

    // --- DAILY RECOMMEND LOGIC ---
    if ($id === 'daily_recommend') {
        // ... (existing WYY daily logic)
        try {
            $pdo = pdo_connect_from_env();
            $stmt = $pdo->prepare("SELECT item_id, title, subtitle, original_share_url, original_cover_url FROM music_home_items WHERE source='wyy' AND section='newSonglist' ORDER BY id ASC");
            $stmt->execute();
            $rows = $stmt->fetchAll();
            
            $list = [];
            foreach ($rows as $r) {
                $list[] = [
                    'id' => $r['item_id'],
                    'title' => $r['title'],
                    'artist' => $r['subtitle'],
                    'share_url' => $r['original_share_url'],
                    'cover_url' => $r['original_cover_url'],
                ];
            }
            
            $data = [
                'source' => 'wyy',
                'id' => 'daily_recommend',
                'title' => '网易云每日推荐',
                'cover_url' => $list[0]['cover_url'] ?? '',
                'list' => $list
            ];
            
            header('Content-Type: application/json');
            echo json_encode(['code'=>200, 'msg'=>'ok', 'data'=>$data]);
            exit;
        } catch (Throwable $e) {
            header('Content-Type: application/json');
            echo json_encode(['code'=>500, 'msg'=>'Daily songs DB error: ' . $e->getMessage()]);
            exit;
        }
    }

    if ($id === 'qishui_daily') {
        try {
            $pdo = pdo_connect_from_env();
            $stmt = $pdo->prepare("SELECT item_id, title, subtitle, original_share_url, original_cover_url FROM music_home_items WHERE source='qishui' AND section='qishuiDaily' ORDER BY id ASC");
            $stmt->execute();
            $rows = $stmt->fetchAll();
            
            $list = [];
            foreach ($rows as $r) {
                $list[] = [
                    'id' => $r['item_id'],
                    'title' => $r['title'],
                    'artist' => $r['subtitle'],
                    'share_url' => $r['original_share_url'],
                    'cover_url' => $r['original_cover_url'],
                ];
            }
            
            $data = [
                'source' => 'qishui',
                'id' => 'qishui_daily',
                'title' => '汽水每日推荐',
                'cover_url' => $list[0]['cover_url'] ?? '',
                'list' => $list
            ];
            
            header('Content-Type: application/json');
            echo json_encode(['code'=>200, 'msg'=>'ok', 'data'=>$data]);
            exit;
        } catch (Throwable $e) {
            header('Content-Type: application/json');
            echo json_encode(['code'=>500, 'msg'=>'Qishui daily DB error: ' . $e->getMessage()]);
            exit;
        }
    }
    // --- END DAILY RECOMMEND ---

    // --- SMART FIX via DB ---
    // If source is suspect (e.g. qq but looks numeric), try to look it up in the database to be sure.
    // This handles cases where APP cache has wrong source but DB has correct data.
    if ($source === 'qq') {
        try {
            $pdo = pdo_connect_from_env();
            $stmt = $pdo->prepare('SELECT source, original_share_url FROM music_home_items WHERE item_id=? LIMIT 1');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if ($row) {
                $dbSource = $row['source'] ?? '';
                $url = $row['original_share_url'] ?? '';
                if ($dbSource === 'wyy' || strpos($url, 'music.163.com') !== false) {
                    $source = 'wyy';
                }
            }
        } catch (Throwable $e) {
            // DB error? Ignore.
        }
    }
    // --- SMART FIX END ---

    // WYY Logic
    if ($source === 'wyy') {
        $pyUrl = 'http://172.21.28.219:8002/playlist?source=wyy&id=' . rawurlencode($id);
        $resp = http_get_pl($pyUrl);
        $json = json_decode($resp, true);
        
        if ($json && isset($json['code']) && $json['code'] === 200) {
            header('Content-Type: application/json');
            echo json_encode(['code'=>200, 'msg'=>'ok', 'data'=>$json['data']]);
            exit;
        }
        
        header('Content-Type: application/json');
        echo json_encode([
            'code'=>500, 
            'msg'=>'Python WYY error: ' . ($json['msg'] ?? 'invalid json'),
            'debug_resp' => substr($resp, 0, 100)
        ]);
        exit;
    }

    // QQ Logic (Legacy)
    if ($source === 'qq') {
        // Basic QQ playlist parsing
        $cookiePath = __DIR__ . '/qq/cookie';
        $cookie = is_file($cookiePath) ? trim(file_get_contents($cookiePath)) : '';
        
        $url = 'https://c.y.qq.com/qzone/fcg-bin/fcg_ucc_getcdinfo_byids_cp.fcg?type=1&json=1&utf8=1&onlysong=0&format=json&disstid=' . rawurlencode($id);
        $raw = http_get_qq_pl($url, $cookie);
        
        // Safer JSONP parsing
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            // Try strip JSONP
            $l = strpos($raw, '(');
            $r = strrpos($raw, ')');
            if ($l !== false && $r !== false && $r > $l) {
                $inner = substr($raw, $l + 1, $r - $l - 1);
                $json = json_decode($inner, true);
            }
        }

        if (!is_array($json)) {
             header('Content-Type: application/json');
             echo json_encode(['code'=>500, 'msg'=>'QQ API Invalid JSON', 'debug_raw'=>substr($raw, 0, 200)]);
             exit;
        }

        if (isset($json['code']) && (int)$json['code'] !== 0) {
             header('Content-Type: application/json');
             echo json_encode(['code'=>500, 'msg'=>'QQ API Error: ' . ($json['code'] ?? 'unknown')]);
             exit;
        }
        
        // Transform to standard format
        $cdlist = $json['cdlist'] ?? [];
        $cd = $cdlist[0] ?? [];
        
        $list = [];
        if (!empty($cd['songlist'])) {
            foreach ($cd['songlist'] as $s) {
                $mid = $s['songmid'] ?? $s['mid'] ?? '';
                $name = $s['songname'] ?? $s['name'] ?? '';
                if (!$mid || !$name) continue;
                
                $singers = [];
                foreach (($s['singer'] ?? []) as $sg) $singers[] = $sg['name'];
                $artist = implode(', ', $singers);
                
                $albumMid = $s['albummid'] ?? '';
                $cover = $albumMid ? "https://y.gtimg.cn/music/photo_new/T002R500x500M000$albumMid.jpg" : "";
                
                $list[] = [
                    'id' => $mid,
                    'title' => $name,
                    'artist' => $artist,
                    'cover_url' => $cover,
                    'share_url' => "https://y.qq.com/n/ryqq/songDetail/$mid"
                ];
            }
        }
        
        $data = [
            'source' => 'qq',
            'id' => $id,
            'title' => $cd['dissname'] ?? '',
            'cover_url' => $cd['logo'] ?? '',
            'list' => $list
        ];
        
        header('Content-Type: application/json');
        echo json_encode(['code'=>200, 'msg'=>'ok', 'data'=>$data]);
        exit;
    }
    
    header('Content-Type: application/json');
    echo json_encode(['code'=>400, 'msg'=>'Unknown source: ' . $source]);

} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['code'=>500, 'msg'=>'PHP Error: ' . $e->getMessage()]);
}