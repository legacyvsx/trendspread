<?php
// categorize_and_chart.php - Categorize trends with Grok and generate pie chart

$inputFile = 'trends_cleaned.log';
$categoriesFile = 'categories.json';
$chartFile = 'category_piechart.png';

$grokApiKey = '';

echo "Categorizing trends and generating pie chart...\n";
echo "Input: $inputFile\n\n";

// Read cleaned trends
$lines = file($inputFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
array_shift($lines); // Remove headers
array_shift($lines);

$trends = [];
foreach ($lines as $line) {
    $parts = explode("\t", $line);
    if (count($parts) < 3) continue;
    
    list($keyword, $volume, $countryCount) = $parts;
    $trends[] = [
        'keyword' => trim($keyword),
        'volume' => (int)$volume
    ];
}

echo "Total keywords to categorize: " . count($trends) . "\n\n";

// Define categories
$categories = [
    'Sports',
    'Weather',
    'Entertainment',
    'Holidays/Events',
    'Politics/News',
    'Technology',
    'Finance/Business',
    'Other'
];

echo "Categories: " . implode(', ', $categories) . "\n\n";

// Prepare keywords for Grok (send all at once)
$keywordList = array_column($trends, 'keyword');
$keywordsText = implode("\n", $keywordList);

// Call Grok API to categorize
echo "Calling Grok API to categorize keywords...\n";

$prompt = "Categorize each of the following keywords into EXACTLY ONE of these categories: Sports, Weather, Entertainment, Holidays/Events, Politics/News, Technology, Finance/Business, Other.

Return ONLY a JSON object where each keyword maps to its category. No explanation, no markdown formatting, just the raw JSON.

Categories:
- Sports: football/soccer matches, basketball, cricket, sports teams, player stats
- Weather: weather queries, earthquake alerts, natural disasters
- Entertainment: movies, TV shows, celebrities, music, games
- Holidays/Events: New Year's, Christmas, birthdays, celebrations
- Politics/News: elections, political figures, current events
- Technology: tech products, apps, software, AI
- Finance/Business: tax, banking, stock market, business
- Other: anything that doesn't fit above

Keywords to categorize:
$keywordsText

Return format (example):
{
  \"weather\": \"Weather\",
  \"arsenal - aston villa\": \"Sports\",
  \"new year's eve\": \"Holidays/Events\"
}";

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
                'content' => 'You are a keyword categorization assistant. Return only valid JSON with no markdown formatting.'
            ],
            [
                'role' => 'user',
                'content' => $prompt
            ]
        ],
        'temperature' => 0.1
    ]),
    CURLOPT_TIMEOUT => 120
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    die("ERROR: Grok API returned HTTP $httpCode\n$response\n");
}

$result = json_decode($response, true);
$content = $result['choices'][0]['message']['content'] ?? '';

// Remove markdown code fences if present
$content = preg_replace('/```json\s*|\s*```/', '', $content);
$content = trim($content);

echo "Grok response received. Parsing...\n";

$categorized = json_decode($content, true);

if (!$categorized) {
    die("ERROR: Could not parse Grok response as JSON\n$content\n");
}

echo "Successfully categorized " . count($categorized) . " keywords\n\n";

// Count volumes by category
$categoryVolumes = array_fill_keys($categories, 0);
$categoryCounts = array_fill_keys($categories, 0);

foreach ($trends as $trend) {
    $keyword = $trend['keyword'];
    $volume = $trend['volume'];
    
    $category = $categorized[$keyword] ?? 'Other';
    
    // Validate category
    if (!in_array($category, $categories)) {
        echo "WARNING: Unknown category '$category' for '$keyword', using 'Other'\n";
        $category = 'Other';
    }
    
    $categoryVolumes[$category] += $volume;
    $categoryCounts[$category]++;
}

// Remove categories with 0 count
$categoryVolumes = array_filter($categoryVolumes);
$categoryCounts = array_filter($categoryCounts);

// SORT BY VOLUME DESCENDING
arsort($categoryVolumes);

// Save categorization results
file_put_contents($categoriesFile, json_encode([
    'categorized' => $categorized,
    'volumes' => $categoryVolumes,
    'counts' => $categoryCounts
], JSON_PRETTY_PRINT));

echo "Categories saved to: $categoriesFile\n\n";

// Display summary
echo "Category Summary:\n";
echo str_repeat("=", 60) . "\n";
foreach ($categoryVolumes as $cat => $vol) {
    $count = $categoryCounts[$cat];
    echo sprintf("%-20s %6d keywords  %10d total volume\n", $cat . ":", $count, $vol);
}
echo str_repeat("=", 60) . "\n\n";

// Generate pie chart using QuickChart
echo "Generating pie chart...\n";

// Format volume labels with K suffix
function formatVolume($vol) {
    if ($vol >= 1000) {
        return round($vol / 1000, 1) . 'k';
    }
    return $vol;
}

// Create labels with volumes (already sorted)
$labels = [];
$data = [];
foreach ($categoryVolumes as $cat => $vol) {
    $labels[] = $cat . ': ' . formatVolume($vol) . ' searches';
    $data[] = $vol;
}

$chartConfig = [
    'type' => 'pie',
    'data' => [
        'labels' => $labels,
        'datasets' => [[
            'data' => $data,
            'backgroundColor' => [
                '#1f77b4', '#ff7f0e', '#2ca02c', '#d62728', 
                '#9467bd', '#8c564b', '#e377c2', '#7f7f7f'
            ],
            'borderWidth' => 3,
            'borderColor' => '#ffffff'
        ]]
    ],
    'options' => [
        'plugins' => [
            'legend' => [
                'display' => true,
                'position' => 'top',
                'labels' => [
                    'font' => [
                        'size' => 13,
                        'weight' => 'bold'
                    ],
                    'padding' => 12,
                    'boxWidth' => 15
                ]
            ],
            'title' => [
                'display' => true,
                'text' => 'Google Trends by Category',
                'font' => [
                    'size' => 20,
                    'weight' => 'bold'
                ],
                'padding' => 20
            ],
            'datalabels' => [
                'display' => false
            ]
        ],
        'layout' => [
            'padding' => 20
        ]
    ]
];

$chartUrl = 'https://quickchart.io/chart?width=800&height=600&chart=' . urlencode(json_encode($chartConfig));
// Fetch chart
$chartData = file_get_contents($chartUrl);

if ($chartData === false) {
    die("ERROR: Could not fetch chart from QuickChart\n");
}

file_put_contents($chartFile, $chartData);

echo "\n" . str_repeat("=", 60) . "\n";
echo "Pie chart generated successfully!\n";
echo "Saved to: $chartFile\n";
echo "File size: " . number_format(filesize($chartFile)) . " bytes\n";
