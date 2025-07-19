<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Nosyapi sabitleri
define('NOSYAPI_BASE_URL', 'https://www.nosyapi.com/apiv2/service/');
define('NOSYAPI_TOKEN', 'yUVTcuQGd5idAU09ShjqgaitPGezTgUwXcP2AQxI1ufEwVD9n9dPj32Q112y');

// Aynı fonksiyonları kullan
function fetchMatchesFromNosyapi(): array {
    $url = NOSYAPI_BASE_URL . 'bettable-matches';
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'X-NSYP: ' . NOSYAPI_TOKEN,
            'Accept: application/json',
            'User-Agent: Mozilla/5.0 (compatible; PHP Bot/1.0)'
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log("Nosyapi CURL Error: " . $error);
        return [];
    }
    if ($httpCode !== 200) {
        error_log("Nosyapi HTTP Error: Code $httpCode");
        return [];
    }
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("Nosyapi JSON Error: " . json_last_error_msg());
        return [];
    }
    if (!isset($data['data']) || !is_array($data['data'])) {
        error_log("Nosyapi Invalid Response Format");
        return [];
    }
    return $data['data'];
}

function processNosyapiMatches(array $matches): array {
    $out = [];
    foreach ($matches as $m) {
        if (!isset($m['MatchID'], $m['Team1'], $m['Team2'])) {
            continue;
        }
        $out[] = [
            'matchCode' => $m['MatchID'],
            'home' => $m['Team1'],
            'away' => $m['Team2'],
            'date' => $m['Date'] ?? date('Y-m-d'),
            'time' => isset($m['Time']) ? substr($m['Time'], 0, 5) : '20:00',
            'league' => $m['League'] ?? 'Unknown League',
            'country' => $m['Country'] ?? 'Unknown'
        ];
    }
    return $out;
}

// Cache kontrolü
function getDailyCouponsFilePath() {
    $dir = 'cache';
    if (!is_dir($dir)) {
        return false;
    }
    return "$dir/nosyapi_daily_coupons_" . date('Y-m-d') . ".json";
}

function getTodaysMatches(): ?array {
    $fp = getDailyCouponsFilePath();
    if (!$fp || !file_exists($fp)) {
        return null;
    }
    $c = @file_get_contents($fp);
    if ($c === false) {
        return null;
    }
    $j = json_decode($c, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }
    if ($j['date'] !== date('Y-m-d')) {
        return null;
    }
    
    // Kuponlardan maç kodlarını çıkar
    $matches = [];
    if (isset($j['coupons']) && is_array($j['coupons'])) {
        foreach ($j['coupons'] as $coupon) {
            if (isset($coupon['matches']) && is_array($coupon['matches'])) {
                foreach ($coupon['matches'] as $match) {
                    $key = $match['matchCode'];
                    if (!isset($matches[$key])) {
                        $matches[$key] = [
                            'matchCode' => $match['matchCode'],
                            'home' => $match['home'],
                            'away' => $match['away']
                        ];
                    }
                }
            }
        }
    }
    
    return array_values($matches);
}

try {
    // Önce cache'den kontrol et
    $matches = getTodaysMatches();
    
    if ($matches === null) {
        // Cache yoksa API'den getir
        $raw = fetchMatchesFromNosyapi();
        $processed = processNosyapiMatches($raw);
        
        $matches = [];
        foreach ($processed as $match) {
            $matches[] = [
                'matchCode' => $match['matchCode'],
                'home' => $match['home'],
                'away' => $match['away']
            ];
        }
    }
    
    // Maç koduna göre sırala
    usort($matches, function($a, $b) {
        return strcmp($a['matchCode'], $b['matchCode']);
    });
    
    echo json_encode($matches, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Sunucu hatası: ' . $e->getMessage()
    ]);
}
?>