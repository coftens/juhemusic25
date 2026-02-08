<?php
/**
 * Test script to verify Netease API connectivity and data structure.
 */

function wyy_api_get($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USER_AGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $resp = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    
    if ($info['http_code'] !== 200) {
        return ['error' => 'HTTP ' . $info['http_code'], 'body' => substr((string)$resp, 0, 200)];
    }
    return json_decode((string)$resp, true);
}

echo "Testing Personalized Playlists API...\n";
$playlists = wyy_api_get('https://music.163.com/api/personalized?limit=10');
if (isset($playlists['result'])) {
    echo "Found " . count($playlists['result']) . " playlists.\n";
    foreach (array_slice($playlists['result'], 0, 2) as $p) {
        echo " - [Playlist] ID: {$p['id']}, Name: {$p['name']}\n";
    }
} else {
    echo "Playlists API Failed: " . json_encode($playlists) . "\n";
}

echo "\nTesting New Songs API...\n";
$songs = wyy_api_get('https://music.163.com/api/personalized/newsong');
if (isset($songs['result'])) {
    echo "Found " . count($songs['result']) . " songs.\n";
    foreach (array_slice($songs['result'], 0, 2) as $s) {
        $name = $s['name'] ?? 'Unknown';
        echo " - [Song] ID: {$s['id']}, Name: $name\n";
    }
} else {
    echo "Songs API Failed: " . json_encode($songs) . "\n";
}

