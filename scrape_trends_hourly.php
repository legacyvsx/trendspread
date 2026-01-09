<?php
// scrape_trends_hourly.php - Scrape, filter, and clean trends 4x daily

$rapidApiKey = '';
$grokApiKey = '';
$baseDir = '';
$hourlyDir = "$baseDir/hourly";

// Create hourly directory if it doesn't exist
if (!is_dir($hourlyDir)) {
    mkdir($hourlyDir, 0755, true);
}

// Generate timestamp for this scrape
$timestamp = date('Y-m-d-H');
$rawFile = "$hourlyDir/$timestamp-raw.log";
$cleanFile = "$hourlyDir/$timestamp.log";

echo "Starting hourly trends scrape at " . date('Y-m-d H:i:s') . "\n";
echo "Output: $cleanFile\n\n";

// All 125 countries supported by Google Trends
$countries = [
    'AE', 'AL', 'AM', 'AO', 'AR', 'AT', 'AU', 'AZ', 'BA', 'BD', 'BE', 'BG', 'BH', 'BO', 'BR', 'BW',
    'CA', 'CH', 'CI', 'CL', 'CM', 'CO', 'CR', 'CY', 'CZ', 'DE', 'DK', 'DO', 'DZ', 'EC', 'EE', 'EG',
    'ES', 'ET', 'FI', 'FR', 'GB', 'GE', 'GH', 'GR', 'GT', 'HK', 'HN', 'HR', 'HU', 'ID', 'IE', 'IL',
    'IN', 'IQ', 'IS', 'IT', 'JM', 'JO', 'JP', 'KE', 'KG', 'KH', 'KR', 'KW', 'KZ', 'LA', 'LB', 'LK',
    'LT', 'LU', 'LV', 'LY', 'MA', 'MD', 'ME', 'MG', 'MK', 'MM', 'MN', 'MO', 'MT', 'MU', 'MW', 'MX',
    'MY', 'MZ', 'NA', 'NG', 'NI', 'NL', 'NO', 'NP', 'NZ', 'OM', 'PA', 'PE', 'PH', 'PK', 'PL', 'PR',
    'PT', 'PY', 'QA', 'RO', 'RS', 'SA', 'SE', 'SG', 'SI', 'SK', 'SN', 'SV', 'TH', 'TN', 'TR', 'TT',
    'TW', 'TZ', 'UA', 'UG', 'US', 'UY', 'UZ', 'VE', 'VN', 'YE', 'ZA', 'ZM', 'ZW'
];

echo "Scraping " . count($countries) . " countries...\n\n";

// STEP 1: Scrape raw trends
$rawTrends = [];
$errors = 0;

file_put_contents($rawFile, "# Raw Hourly Trends: " . date('Y-m-d H:i:s') . "\n");
file_put_contents($rawFile, "# Format: TIMESTAMP\tCOUNTRY\tKEYWORD\tVOLUME\n", FILE_APPEND);

foreach ($countries as $country) {
    echo "Fetching $country... ";
    
    $ch = curl_init("https://google-trends-api4.p.rapidapi.com/api/v3/trends/nows?geo=$country");
    
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "x-rapidapi-host: google-trends-api4.p.rapidapi.com",
            "x-rapidapi-key: $rapidApiKey"
        ],
        CURLOPT_TIMEOUT => 30
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        echo "ERROR (HTTP $httpCode)\n";
        $errors++;
        continue;
    }
    
    $data = json_decode($response, true);
    
    // TrendsNows: trendingNows[] at root level
    if (!isset($data['trendingNows']) || !is_array($data['trendingNows'])) {
        echo "NO TRENDS\n";
        continue;
    }
    
    $count = 0;
    foreach ($data['trendingNows'] as $trend) {
        if ($count >= 10) {
        	break;
    	}
	$keyword = $trend['keyword'] ?? '';
        $volume = $trend['searchVolume'] ?? 0;
        
        if (empty($keyword)) continue;
        
        // Decode HTML entities
        $keyword = html_entity_decode($keyword, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // Volume is already numeric
        $volume = (int)$volume;
        
        // Store for filtering
        $rawTrends[] = [
            'timestamp' => date('Y-m-d H:i:s'),
            'country' => $country,
            'keyword' => $keyword,
            'volume' => $volume
        ];
        
        // Write to raw file
        $line = date('Y-m-d H:i:s') . "\t$country\t$keyword\t$volume\n";
        file_put_contents($rawFile, $line, FILE_APPEND);
        
        $count++;
    }
    
    echo "$count trends\n";
    usleep(100000); // 0.1 second delay
}

echo "\nTotal raw trends: " . count($rawTrends) . "\n";
echo "Errors: $errors\n\n";

// STEP 2: Filter for English using Grok
echo "Filtering for English keywords using Grok AI...\n";

$uniqueKeywords = array_unique(array_column($rawTrends, 'keyword'));
echo "Unique keywords to filter: " . count($uniqueKeywords) . "\n";

// Filter in batches of 100
$batchSize = 100;
$batches = array_chunk($uniqueKeywords, $batchSize);
$englishKeywords = [];

foreach ($batches as $i => $batch) {
    echo "Processing batch " . ($i + 1) . "/" . count($batches) . "... ";
    
    $keywordList = implode("\n", $batch);
    
    $prompt = "Filter this list of keywords and return ONLY the ones that are in English. Exclude keywords in Spanish, French, German, Portuguese, Italian, Dutch, Indonesian, Arabic, Hindi, and all other non-English languages.

Return ONLY a JSON array of English keywords. No explanation, no markdown formatting.

Keywords:
$keywordList

Example response format:
[\"weather\", \"arsenal vs aston villa\", \"new year's eve\"]";
    
    $ch = curl_init('https://api.x.ai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            "Authorization: Bearer $grokApiKey"
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'grok-4-1-fast-reasoning',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are an English language filter. Return only valid JSON arrays.'
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'temperature' => 0.1
        ]),
        CURLOPT_TIMEOUT => 60
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        echo "ERROR (HTTP $httpCode)\n";
        continue;
    }
    
    $result = json_decode($response, true);
    $content = $result['choices'][0]['message']['content'] ?? '';
    
    // Remove markdown fences
    $content = preg_replace('/```json\s*|\s*```/', '', $content);
    $content = trim($content);
    
    $filtered = json_decode($content, true);
    
    if (is_array($filtered)) {
        $englishKeywords = array_merge($englishKeywords, $filtered);
        echo count($filtered) . " English\n";
    } else {
        echo "ERROR parsing response (JSON decode failed)\n";
        echo "Raw content: " . substr($content, 0, 200) . "...\n";
    }
    
    sleep(1); // Rate limit Grok API
}

echo "Total English keywords: " . count($englishKeywords) . "\n\n";

// STEP 3: Normalize and deduplicate
echo "Normalizing and cleaning keywords...\n";

function normalizeKeyword($keyword) {
    // Replace all dash variants with standard hyphen
    $normalized = preg_replace('/\s*[\-\x{2010}-\x{2015}\x{2212}]\s*/u', ' - ', $keyword);
    // Normalize "vs" to "-"
    $normalized = preg_replace('/\s+vs\.?\s+/i', ' - ', $normalized);
    // Normalize "x" to "-"
    $normalized = preg_replace('/\s+x\s+/i', ' - ', $normalized);
    // Remove "f.c."
    $normalized = preg_replace('/\s+f\.c\.\s+/i', ' ', $normalized);
    // Remove trailing words
    $normalized = preg_replace('/\s+(prediction|match|game|fixtures?|live|stream|highlights?)$/i', '', $normalized);
    // Normalize whitespace
    $normalized = preg_replace('/\s+/', ' ', trim($normalized));
    return $normalized;
}

// Filter and normalize
$cleanTrends = [];
$englishSet = array_flip($englishKeywords); // For fast lookup

foreach ($rawTrends as $trend) {
    $keyword = $trend['keyword'];
    
    // Skip non-English
    if (!isset($englishSet[$keyword])) {
        continue;
    }
    
    // Normalize
    $normalized = normalizeKeyword($keyword);
    $key = strtolower($normalized);
    
    // Aggregate by normalized keyword + country
    $aggregateKey = $key . '|' . $trend['country'];
    
    if (!isset($cleanTrends[$aggregateKey])) {
        $cleanTrends[$aggregateKey] = [
            'timestamp' => $trend['timestamp'],
            'country' => $trend['country'],
            'keyword' => $normalized,
            'volume' => 0
        ];
    }
    
    $cleanTrends[$aggregateKey]['volume'] += $trend['volume'];
}

echo "Clean trends after filtering: " . count($cleanTrends) . "\n\n";

// STEP 4: Write clean file
file_put_contents($cleanFile, "# Clean Hourly Trends: " . date('Y-m-d H:i:s') . "\n");
file_put_contents($cleanFile, "# Format: TIMESTAMP\tCOUNTRY\tKEYWORD\tVOLUME\n", FILE_APPEND);

foreach ($cleanTrends as $trend) {
    $line = $trend['timestamp'] . "\t" . $trend['country'] . "\t" . $trend['keyword'] . "\t" . $trend['volume'] . "\n";
    file_put_contents($cleanFile, $line, FILE_APPEND);
}

echo str_repeat("=", 60) . "\n";
echo "Hourly scrape complete!\n";
echo "Raw file: $rawFile (" . count($rawTrends) . " trends)\n";
echo "Clean file: $cleanFile (" . count($cleanTrends) . " trends)\n";
echo "File size: " . number_format(filesize($cleanFile)) . " bytes\n";
