<?php
// scrape_trends_hourly.php - Scrape current Google Trends from 125 countries

$baseDir = '/var/www/morallyrelative.com/trends';
$hourlyDir = "$baseDir/hourly";

// Create hourly directory if it doesn't exist
if (!is_dir($hourlyDir)) {
    mkdir($hourlyDir, 0755, true);
}

// Configuration
$rapidApiKey = '';
$grokApiKey = '';

// All country codes we want to scrape
$countries = [
    'US', 'GB', 'CA', 'AU', 'IN', 'DE', 'FR', 'IT', 'ES', 'NL',
    'SE', 'NO', 'DK', 'FI', 'PL', 'RO', 'GR', 'PT', 'BE', 'AT',
    'CH', 'IE', 'CZ', 'HU', 'BG', 'SK', 'HR', 'SI', 'LT', 'LV',
    'EE', 'CY', 'MT', 'LU', 'IS', 'BR', 'MX', 'AR', 'CO', 'CL',
    'PE', 'VE', 'EC', 'GT', 'CU', 'BO', 'DO', 'HN', 'PY', 'SV',
    'NI', 'CR', 'PA', 'UY', 'JM', 'TT', 'GY', 'SR', 'BZ', 'BB',
    'BS', 'LC', 'GD', 'VC', 'AG', 'DM', 'KN', 'JP', 'CN', 'KR',
    'TW', 'HK', 'SG', 'MY', 'TH', 'ID', 'PH', 'VN', 'BD', 'PK',
    'LK', 'MM', 'KH', 'LA', 'NP', 'AF', 'MV', 'BT', 'MN', 'KP',
    'RU', 'UA', 'BY', 'KZ', 'UZ', 'TM', 'KG', 'TJ', 'GE', 'AM',
    'AZ', 'TR', 'SA', 'AE', 'IL', 'IQ', 'IR', 'JO', 'LB', 'SY',
    'YE', 'OM', 'KW', 'BH', 'QA', 'EG', 'DZ', 'MA', 'TN', 'LY',
    'SD', 'ET', 'KE', 'TZ', 'UG', 'ZA', 'NG', 'GH', 'CI', 'SN',
    'ML', 'BF', 'NE', 'TD', 'SO', 'MZ', 'MW', 'ZM', 'ZW', 'BW',
    'NA', 'AO', 'CD', 'CG', 'GA', 'CM', 'CF', 'RW', 'BI', 'DJ',
    'ER', 'GM', 'GN', 'GW', 'LR', 'SL', 'MR', 'TG', 'BJ', 'GQ',
    'ST', 'CV', 'KM', 'SC', 'MU', 'MG', 'RE', 'YT', 'LS', 'SZ'
];

$timestamp = date('Y-m-d H:i:s');
$dateHour = date('Y-m-d-H');
$rawOutputFile = "$hourlyDir/$dateHour-raw.log";

echo "Google Trends Hourly Scraper\n";
echo str_repeat("=", 60) . "\n";
echo "Timestamp: $timestamp\n";
echo "Countries to scrape: " . count($countries) . "\n";
echo "Output file: $rawOutputFile\n\n";

// STEP 1: Scrape from all countries
echo "Scraping trends from " . count($countries) . " countries...\n";

$allTrends = [];
$successCount = 0;
$errorCount = 0;

foreach ($countries as $country) {
    echo "  Fetching $country... ";
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "https://google-trends-api3.p.rapidapi.com/trendingnow?geo=$country",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'x-rapidapi-host: google-trends-api3.p.rapidapi.com',
            'x-rapidapi-key: ' . $rapidApiKey
        ],
        CURLOPT_TIMEOUT => 30
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        echo "ERROR (HTTP $httpCode)\n";
        $errorCount++;
        continue;
    }
    
    $data = json_decode($response, true);
    
    if (!isset($data['data']) || !is_array($data['data'])) {
        echo "ERROR (no data)\n";
        $errorCount++;
        continue;
    }
    
    // Take top 10 trends per country
    $trends = array_slice($data['data'], 0, 10);
    
    foreach ($trends as $trend) {
        $allTrends[] = [
            'timestamp' => $timestamp,
            'country' => $country,
            'keyword' => $trend['title'] ?? '',
            'volume' => $trend['formattedTraffic'] ?? '0'
        ];
    }
    
    $successCount++;
    echo "OK (" . count($trends) . " trends)\n";
    
    usleep(100000); // 100ms delay between requests
}

echo "\nScraping complete:\n";
echo "  Success: $successCount countries\n";
echo "  Errors: $errorCount countries\n";
echo "  Total trends collected: " . count($allTrends) . "\n\n";

// Save raw data
echo "Saving raw data to $rawOutputFile...\n";
$fp = fopen($rawOutputFile, 'w');
fwrite($fp, "# Raw Google Trends data\n");
fwrite($fp, "# Scraped at: $timestamp\n");
fwrite($fp, "# Format: TIMESTAMP\\tCOUNTRY\\tKEYWORD\\tVOLUME\n");

foreach ($allTrends as $trend) {
    fwrite($fp, implode("\t", [
        $trend['timestamp'],
        $trend['country'],
        $trend['keyword'],
        $trend['volume']
    ]) . "\n");
}

fclose($fp);
echo "Raw data saved (" . count($allTrends) . " entries)\n\n";

// STEP 2: Filter for English keywords using Grok
echo "Filtering for English keywords using Grok AI...\n";

$keywords = array_unique(array_column($allTrends, 'keyword'));
echo "Unique keywords to check: " . count($keywords) . "\n";

$englishKeywords = [];
$batchSize = 100;
$batches = array_chunk($keywords, $batchSize);

foreach ($batches as $batchNum => $batch) {
    echo "Processing batch " . ($batchNum + 1) . "/" . count($batches) . "... ";
    
    $prompt = "Filter this list to ONLY keywords that are in English or are proper nouns (like brand names, place names, or people's names). Remove any keywords that are purely in other languages. Return ONLY the English keywords, one per line, with no explanations or extra text.\n\nKeywords:\n" . implode("\n", $batch);
    
    $ch = curl_init('https://api.x.ai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $grokApiKey
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are a language filter. Return only English keywords from the provided list, one per line. No explanations.'
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'model' => 'grok-4-1-fast-reasoning',
            'temperature' => 0.3
        ]),
        CURLOPT_TIMEOUT => 30
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $result = json_decode($response, true);
    $grokResponse = $result['choices'][0]['message']['content'] ?? '';
    
    $filtered = array_filter(
        array_map('trim', explode("\n", $grokResponse)),
        function($line) { return !empty($line); }
    );
    
    $englishKeywords = array_merge($englishKeywords, $filtered);
    
    echo count($filtered) . " English\n";
    
    sleep(1); // Rate limiting
}

echo "Total English keywords: " . count($englishKeywords) . "\n\n";

// STEP 3: Normalize and deduplicate
echo "Normalizing and cleaning keywords...\n";

function isCompleteSportsMatch($keyword) {
    // Check if it looks like a complete match (team1 - team2 or team1 vs team2)
    if (preg_match('/^.+?\s+(vs\.?|-|x)\s+.+$/i', $keyword)) {
        return true;
    }
    return false;
}

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

foreach ($allTrends as $trend) {
    $keyword = $trend['keyword'];
    
    // If it's a complete sports match, skip English check
    if (isCompleteSportsMatch($keyword)) {
        // Keep it even if team names aren't English
    } else {
        // Skip non-English for everything else
        if (!isset($englishSet[$keyword])) {
            continue;
        }
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
    
    // Parse volume (remove commas, convert to int)
    $volume = (int)str_replace([',', '+', 'K', 'M'], ['', '', '000', '000000'], $trend['volume']);
    $cleanTrends[$aggregateKey]['volume'] += $volume;
}

echo "Deduplicated to " . count($cleanTrends) . " unique trend-country pairs\n\n";

// Save clean data
$cleanOutputFile = "$hourlyDir/$dateHour.log";
echo "Saving clean data to $cleanOutputFile...\n";

$fp = fopen($cleanOutputFile, 'w');
fwrite($fp, "# Clean English-only Google Trends\n");
fwrite($fp, "# Scraped at: $timestamp\n");
fwrite($fp, "# Format: TIMESTAMP\\tCOUNTRY\\tKEYWORD\\tVOLUME\n");

foreach ($cleanTrends as $trend) {
    fwrite($fp, implode("\t", [
        $trend['timestamp'],
        $trend['country'],
        $trend['keyword'],
        $trend['volume']
    ]) . "\n");
}

fclose($fp);

echo "Clean data saved (" . count($cleanTrends) . " entries)\n";
echo "\n" . str_repeat("=", 60) . "\n";
echo "Scraping complete!\n";
echo "Raw file: $rawOutputFile\n";
echo "Clean file: $cleanOutputFile\n";
