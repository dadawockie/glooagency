<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// AllSports API sabitleri
define('ALLSPORTS_BASE_URL', 'https://apiv2.allsportsapi.com/football');
define('ALLSPORTS_API_KEY', '38a1df80ae613ada40a41875525441ad470d25d585b5d2b7645fc3914db7e854');

// OPTIONS isteği için
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// POST isteği kontrolü
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Only POST method allowed']);
    exit();
}

// JSON input al
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || !isset($data['homeTeam']) || !isset($data['awayTeam'])) {
    http_response_code(400);
    echo json_encode(['error' => 'homeTeam and awayTeam are required']);
    exit();
}

$homeTeam = trim($data['homeTeam']);
$awayTeam = trim($data['awayTeam']);

// Takım ID'lerini bul
function searchTeamId($teamName) {
    $searchUrl = ALLSPORTS_BASE_URL . '?met=Teams&APIkey=' . ALLSPORTS_API_KEY . '&teamName=' . urlencode($teamName);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $searchUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; H2H Bot/1.0)'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($response === false || $httpCode !== 200) {
        return null;
    }
    
    $teamData = json_decode($response, true);
    
    if (isset($teamData['result']) && is_array($teamData['result']) && count($teamData['result']) > 0) {
        // En iyi eşleşmeyi bul
        $bestMatch = null;
        $highestSimilarity = 0;
        
        foreach ($teamData['result'] as $team) {
            if (isset($team['team_key']) && isset($team['team_name'])) {
                $similarity = similar_text(strtolower($teamName), strtolower($team['team_name']));
                if ($similarity > $highestSimilarity) {
                    $highestSimilarity = $similarity;
                    $bestMatch = $team;
                }
            }
        }
        
        return $bestMatch ? $bestMatch['team_key'] : null;
    }
    
    return null;
}

// Alternatif takım ismi deneme (yaygın kısaltmalar ve çeviriler)
function getAlternativeTeamNames($teamName) {
    $alternatives = [
        // Türkiye takımları
        'Galatasaray' => ['Galatasaray SK', 'GS', 'Gala'],
        'Fenerbahçe' => ['Fenerbahçe SK', 'FB', 'Fener'],
        'Beşiktaş' => ['Besiktas', 'BJK', 'Besiktas JK'],
        'Trabzonspor' => ['Trabzon', 'TS'],
        
        // Avrupa takımları
        'Real Madrid' => ['Real Madrid CF', 'Madrid', 'RM'],
        'Barcelona' => ['FC Barcelona', 'Barca', 'FCB'],
        'Manchester United' => ['Man United', 'MUFC', 'Man Utd'],
        'Manchester City' => ['Man City', 'MCFC', 'City'],
        'Liverpool' => ['Liverpool FC', 'LFC'],
        'Chelsea' => ['Chelsea FC', 'CFC'],
        'Arsenal' => ['Arsenal FC', 'AFC'],
        'Tottenham' => ['Tottenham Hotspur', 'Spurs', 'THFC'],
        
        // Diğer ülkeler
        'PSG' => ['Paris Saint-Germain', 'Paris SG'],
        'Bayern Munich' => ['Bayern München', 'FC Bayern'],
        'Juventus' => ['Juventus FC', 'Juve'],
        'AC Milan' => ['Milan', 'AC Milan'],
        'Inter Milan' => ['Inter', 'FC Internazionale']
    ];
    
    $result = [$teamName];
    
    // Direkt eşleşme
    if (isset($alternatives[$teamName])) {
        $result = array_merge($result, $alternatives[$teamName]);
    }
    
    // Tersten arama
    foreach ($alternatives as $key => $values) {
        if (in_array($teamName, $values)) {
            $result[] = $key;
            $result = array_merge($result, $values);
        }
    }
    
    return array_unique($result);
}

// H2H verilerini getir
function getH2HData($homeTeamId, $awayTeamId) {
    $h2hUrl = ALLSPORTS_BASE_URL . '?met=H2H&APIkey=' . ALLSPORTS_API_KEY . 
              '&firstTeamId=' . $homeTeamId . '&secondTeamId=' . $awayTeamId;
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $h2hUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; H2H Bot/1.0)'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($response === false || $httpCode !== 200) {
        return null;
    }
    
    return json_decode($response, true);
}

try {
    // Takım ID'lerini bul
    $homeTeamId = null;
    $awayTeamId = null;
    
    // Ana takım isimlerini dene
    $homeAlternatives = getAlternativeTeamNames($homeTeam);
    $awayAlternatives = getAlternativeTeamNames($awayTeam);
    
    foreach ($homeAlternatives as $alt) {
        $homeTeamId = searchTeamId($alt);
        if ($homeTeamId) break;
    }
    
    foreach ($awayAlternatives as $alt) {
        $awayTeamId = searchTeamId($alt);
        if ($awayTeamId) break;
    }
    
    if (!$homeTeamId || !$awayTeamId) {
        echo json_encode([
            'error' => 'Takım ID\'leri bulunamadı',
            'debug' => [
                'homeTeam' => $homeTeam,
                'awayTeam' => $awayTeam,
                'homeTeamId' => $homeTeamId,
                'awayTeamId' => $awayTeamId,
                'homeAlternatives' => $homeAlternatives,
                'awayAlternatives' => $awayAlternatives
            ]
        ]);
        exit();
    }
    
    // H2H verilerini getir
    $h2hData = getH2HData($homeTeamId, $awayTeamId);
    
    if (!$h2hData) {
        echo json_encode([
            'error' => 'H2H verileri alınamadı',
            'homeTeamId' => $homeTeamId,
            'awayTeamId' => $awayTeamId
        ]);
        exit();
    }
    
    // Veriyi işle ve formatla
    $response = [
        'success' => true,
        'homeTeam' => $homeTeam,
        'awayTeam' => $awayTeam,
        'homeTeamId' => $homeTeamId,
        'awayTeamId' => $awayTeamId,
        'h2hData' => $h2hData,
        'summary' => null
    ];
    
    // H2H özetini çıkar
    if (isset($h2hData['result']) && is_array($h2hData['result'])) {
        $homeWins = 0;
        $awayWins = 0;
        $draws = 0;
        $totalMatches = count($h2hData['result']);
        $recentMatches = array_slice($h2hData['result'], 0, 5);
        
        foreach ($h2hData['result'] as $match) {
            if (isset($match['match_hometeam_score']) && isset($match['match_awayteam_score'])) {
                $homeScore = (int)$match['match_hometeam_score'];
                $awayScore = (int)$match['match_awayteam_score'];
                
                if ($homeScore > $awayScore) {
                    // Ev sahibi takımın kim olduğunu kontrol et
                    if (isset($match['match_hometeam_id']) && $match['match_hometeam_id'] == $homeTeamId) {
                        $homeWins++;
                    } else {
                        $awayWins++;
                    }
                } elseif ($awayScore > $homeScore) {
                    if (isset($match['match_awayteam_id']) && $match['match_awayteam_id'] == $homeTeamId) {
                        $homeWins++;
                    } else {
                        $awayWins++;
                    }
                } else {
                    $draws++;
                }
            }
        }
        
        $response['summary'] = [
            'totalMatches' => $totalMatches,
            'homeWins' => $homeWins,
            'awayWins' => $awayWins,
            'draws' => $draws,
            'recentMatches' => $recentMatches
        ];
    }
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Sunucu hatası: ' . $e->getMessage(),
        'homeTeam' => $homeTeam ?? null,
        'awayTeam' => $awayTeam ?? null
    ]);
}
?>