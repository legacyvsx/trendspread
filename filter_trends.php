<?php
// filter_trends.php - Filter for English-only trends using Grok AI

define('GROK_API_KEY', '');

$inputFile = 'trends.log';
$outputFile = 'trends_filtered.log';

echo "Filtering trends with Grok AI...\n";
echo "Input: $inputFile\n";
echo "Output: $outputFile\n\n";

// Read all trends
$lines = file($inputFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
array_shift($lines); // Skip header

// First pass: collect unique keywords and their data
$allKeywords = [];
$totalInput = 0;

foreach ($lines as $line) {
    $parts = explode("\t", $line);
    if (count($parts) < 3) continue;
    
    list($country, $keyword, $volume) = $parts;
    $totalInput++;
    
    // Decode HTML entities
    $keyword = html_entity_decode($keyword, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    
    // Store keyword data
    if (!isset($allKeywords[$keyword])) {
        $allKeywords[$keyword] = [];
    }
    
    $allKeywords[$keyword][] = [
        'country' => $country,
        'volume' => volumeToNumeric($volume)
    ];
}

echo "Total unique keywords before filtering: " . count($allKeywords) . "\n";

// Filter with Grok in batches
$batchSize = 100;
$allUniqueKeywords = array_keys($allKeywords);
$englishKeywords = [];

for ($i = 0; $i < count($allUniqueKeywords); $i += $batchSize) {
    $batch = array_slice($allUniqueKeywords, $i, $batchSize);
    $batchNum = floor($i / $batchSize) + 1;
    
    echo "Processing batch $batchNum (" . count($batch) . " keywords)...\n";
    
    $batchEnglish = filterEnglishWithGrok($batch);
    $englishKeywords = array_merge($englishKeywords, $batchEnglish);
    
    echo "  Found " . count($batchEnglish) . " English keywords in this batch\n";
    
    // Small delay to avoid rate limiting
    sleep(2);
}

$englishKeywords = array_unique($englishKeywords);
echo "\nTotal English keywords: " . count($englishKeywords) . "\n";

// Aggregate English trends
$trends = [];
$filteredOut = 0;

foreach ($allKeywords as $keyword => $dataList) {
    if (!in_array($keyword, $englishKeywords)) {
        $filteredOut += count($dataList);
        continue;
    }
    
    $normalized = strtolower(trim($keyword));
    
    if (!isset($trends[$normalized])) {
        $trends[$normalized] = [
            'keyword' => $keyword,
            'volume' => 0,
            'countries' => []
        ];
    }
    
    foreach ($dataList as $data) {
        $trends[$normalized]['volume'] += $data['volume'];
        $trends[$normalized]['countries'][] = $data['country'];
    }
}

// Sort by volume
uasort($trends, function($a, $b) {
    return $b['volume'] - $a['volume'];
});

// Write output
$timestamp = date('Y-m-d H:i:s');
file_put_contents($outputFile, "# Filtered Google Trends: $timestamp\n");
file_put_contents($outputFile, "# Format: KEYWORD\tTOTAL_VOLUME\tCOUNTRY_COUNT\tCOUNTRIES\n", FILE_APPEND);

foreach ($trends as $data) {
    $keyword = $data['keyword'];
    $volume = $data['volume'];
    $countries = array_unique($data['countries']);
    $countryCount = count($countries);
    $countriesStr = implode(',', $countries);
    
    $line = "$keyword\t$volume\t$countryCount\t$countriesStr\n";
    file_put_contents($outputFile, $line, FILE_APPEND);
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "Total input trends: $totalInput\n";
echo "Filtered out (non-English): $filteredOut\n";
echo "Unique English trends: " . count($trends) . "\n";
echo "Saved to: $outputFile\n";

function filterEnglishWithGrok($keywords) {
    $keywordList = implode("\n", array_map(function($kw) {
        return "- " . $kw;
    }, $keywords));
    
    $prompt = "You are a language detector. Given this list of keywords/phrases, identify which ones are in English.

IMPORTANT RULES:
1. Return ONLY keywords that are primarily English
2. Reject non-English words even if they use Latin alphabet (e.g., \"silvester\" is German, reject it)
3. Reject proper nouns from non-English speaking regions unless they're commonly used in English
4. Accept English phrases about non-English topics (e.g., \"man united vs wolves\" is English even though it's about sports)

Keywords to check:
$keywordList

Return ONLY a JSON array of the English keywords, nothing else. Format: [\"keyword1\", \"keyword2\", ...]";

    $ch = curl_init('https://api.x.ai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . GROK_API_KEY
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are a language detection expert. Return only valid JSON arrays.'
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'model' => 'grok-4-1-fast-reasoning',
            'temperature' => 0.1,
            'max_tokens' => 4000
        ]),
        CURLOPT_TIMEOUT => 60
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        echo "  ERROR: HTTP $httpCode - $response\n";
        return [];
    }
    
    $result = json_decode($response, true);
    if (!$result || !isset($result['choices'][0]['message']['content'])) {
        echo "  ERROR: Invalid response format\n";
        return [];
    }
    
    $content = trim($result['choices'][0]['message']['content']);
    
    // Remove markdown code blocks if present
    if (strpos($content, '```') === 0) {
        $parts = explode('```', $content);
        $content = $parts[1] ?? $content;
        if (strpos($content, 'json') === 0) {
            $content = substr($content, 4);
        }
        $content = trim($content);
    }
    
    $englishKeywords = json_decode($content, true);
    
    if (!is_array($englishKeywords)) {
        echo "  ERROR: Could not parse JSON response\n";
        return [];
    }
    
    return $englishKeywords;
}

function volumeToNumeric($volume) {
    $volume = trim(str_replace('+', '', $volume));
    $numeric = (int)$volume;
    return ($numeric == 0 && $volume !== '0') ? 0 : $numeric;
}
