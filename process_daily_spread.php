<?php
// process_daily_spread.php - Analyze trend spread across countries

$baseDir = '';
$hourlyDir = "$baseDir/hourly";
$spreadDir = "$baseDir/spread";

// Create spread directory if it doesn't exist
if (!is_dir($spreadDir)) {
    mkdir($spreadDir, 0755, true);
}

echo "Processing trend spread analysis\n";
echo str_repeat("=", 60) . "\n\n";

// Find all clean hourly files (exclude -raw.log)
$allFiles = glob("$hourlyDir/*.log");
$allFiles = array_filter($allFiles, function($file) {
    return substr($file, -8) !== '-raw.log';  // PHP 7 compatible
});

if (empty($allFiles)) {
    die("ERROR: No hourly data files found\n");
}

// Sort by modification time (most recent first)
usort($allFiles, function($a, $b) {
    return filemtime($b) - filemtime($a);
});

// Get current date for filtering
$targetDate = date('Y-m-d');

// Filter to only files from today
$todayFiles = array_filter($allFiles, function($file) use ($targetDate) {
    return strpos(basename($file), $targetDate) === 0;
});

// Take the 5 most recent from today (or however many exist)
$files = array_slice($todayFiles, 0, 5);

if (empty($files)) {
    die("ERROR: No hourly data files found for today ($targetDate)\n");
}

// Sort chronologically for processing (oldest to newest)
usort($files, function($a, $b) {
    return filemtime($a) - filemtime($b);
});

echo "Using " . count($files) . " most recent hourly snapshot(s):\n";
foreach ($files as $file) {
    $time = date('Y-m-d H:i:s', filemtime($file));
    echo "  - " . basename($file) . " (modified: $time)\n";
}
echo "\n";

echo "Output date: $targetDate\n\n";

// STEP 1: Load all trends from all snapshots
$allTrends = [];
$snapshotTimes = [];

foreach ($files as $file) {
    echo "Loading from " . basename($file) . "... ";
    
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    // Skip header lines and find the actual timestamp from the data
    $snapshotTime = null;
    $dataLines = [];
    
    foreach ($lines as $line) {
        if (substr($line, 0, 1) === '#') {
            continue; // Skip header
        }
        
        $dataLines[] = $line;
        
        // Extract timestamp from first data line if we don't have it yet
        if ($snapshotTime === null) {
            $parts = explode("\t", $line);
            if (count($parts) >= 1) {
                $snapshotTime = trim($parts[0]); // Use actual timestamp from data
            }
        }
    }
    
    if ($snapshotTime === null) {
        echo "ERROR: No data found\n";
        continue;
    }
    
    $snapshotTimes[] = $snapshotTime;
    
    $count = 0;
    foreach ($dataLines as $line) {
        $parts = explode("\t", $line);
        if (count($parts) < 4) continue;
        
        list($timestamp, $country, $keyword, $volume) = $parts;
        
        $keyword = trim($keyword);
        $country = trim($country);
        
        // Store: keyword -> country -> first_seen_time
        if (!isset($allTrends[$keyword])) {
            $allTrends[$keyword] = [];
        }
        
        if (!isset($allTrends[$keyword][$country])) {
            $allTrends[$keyword][$country] = [
                'first_seen' => $snapshotTime, // Use snapshot time, not individual line timestamp
                'volume' => (int)$volume
            ];
        }
        
        $count++;
    }
    
    echo "$count entries (timestamp: $snapshotTime)\n";
}

echo "\nTotal unique keywords across all snapshots: " . count($allTrends) . "\n\n";

// STEP 2: Identify spreading trends (appeared in 2+ countries AND 2+ snapshots)
echo "Identifying spreading trends (2+ countries, 2+ snapshots)...\n";

$spreadingTrends = [];

foreach ($allTrends as $keyword => $countries) {
    $countryCount = count($countries);
    
    if ($countryCount >= 2) {
        // Calculate spread metrics
        $firstSeenTimes = array_column($countries, 'first_seen');
        $earliestTime = min($firstSeenTimes);
        $latestTime = max($firstSeenTimes);
        
        // Get unique timestamps to see how many different snapshots this appeared in
        $uniqueTimes = array_unique($firstSeenTimes);
        
        // Skip if appeared in only one snapshot (not actually spreading)
        if (count($uniqueTimes) < 2) {
            continue;
        }
        
        // Find ALL countries with earliest appearance
        $firstCountries = [];
        foreach ($countries as $country => $data) {
            if ($data['first_seen'] === $earliestTime) {
                $firstCountries[] = $country;
            }
        }
        $firstCountry = implode(', ', $firstCountries);
        
        // Calculate how many NEW countries it spread to (total - starting countries)
        $spreadToCount = $countryCount - count($firstCountries);
        
        $totalVolume = array_sum(array_column($countries, 'volume'));
        
        $spreadingTrends[$keyword] = [
            'countries' => $countries,
            'country_count' => $countryCount,
            'spread_to_count' => $spreadToCount,
            'total_volume' => $totalVolume,
            'earliest_appearance' => $earliestTime,
            'latest_appearance' => $latestTime,
            'first_country' => $firstCountry
        ];
    }
}

// Sort by spread_to_count (most spread first)
uasort($spreadingTrends, function($a, $b) {
    return $b['spread_to_count'] - $a['spread_to_count'];
});

echo "Found " . count($spreadingTrends) . " spreading trends\n\n";

// STEP 3: Save results
$outputFile = "$spreadDir/$targetDate-spread.json";

$output = [
    'date' => $targetDate,
    'snapshot_count' => count($files),
    'snapshot_times' => $snapshotTimes,
    'total_keywords' => count($allTrends),
    'spreading_trends' => $spreadingTrends
];

file_put_contents($outputFile, json_encode($output, JSON_PRETTY_PRINT));

echo "Spread analysis saved to: $outputFile\n";
echo "File size: " . number_format(filesize($outputFile)) . " bytes\n\n";

// STEP 4: Display top 10 spreading trends
echo "Top 10 spreading trends:\n";
echo str_repeat("=", 60) . "\n";

$top10 = array_slice($spreadingTrends, 0, 10, true);

foreach ($top10 as $keyword => $data) {
    echo sprintf("%-40s %3d add'l countries  %8d volume\n", 
        substr($keyword, 0, 40), 
        $data['spread_to_count'], 
        $data['total_volume']
    );
    echo "  First: {$data['earliest_appearance']} -> Last: {$data['latest_appearance']}\n";
}

echo "\n" . str_repeat("=", 60) . "\n";

// STEP 5: Generate HTML table
echo "Generating HTML table...\n";

function formatVolume($vol) {
    if ($vol >= 1000000) {
        return round($vol / 1000000, 1) . 'M';
    } elseif ($vol >= 1000) {
        return round($vol / 1000, 1) . 'k';
    }
    return $vol;
}

$htmlFile = "$baseDir/top10_trends_spread.html";

$html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Top 10 Spreading Trends - ' . $targetDate . '</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            max-width: 1200px;
            margin: 40px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        h1 {
            color: #333;
            text-align: center;
            margin-bottom: 10px;
        }
        .subtitle {
            text-align: center;
            color: #666;
            margin-bottom: 30px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border-radius: 8px;
            overflow: hidden;
        }
        th {
            background: #2c3e50;
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: 0.5px;
        }
        td {
            padding: 15px;
            border-bottom: 1px solid #eee;
        }
        tr:last-child td {
            border-bottom: none;
        }
        tr:hover {
            background: #f8f9fa;
        }
        .keyword {
            font-weight: 600;
            color: #2c3e50;
            word-break: break-word;
        }
        .country {
            font-family: monospace;
            background: #e3f2fd;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 13px;
            font-weight: bold;
        }
        .count {
            text-align: center;
            font-weight: 600;
            color: #3498db;
        }
        .volume {
            text-align: right;
            font-weight: 600;
            color: #27ae60;
        }
        .footer {
            text-align: center;
            margin-top: 30px;
            color: #999;
            font-size: 14px;
        }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            body {
                margin: 10px;
                padding: 10px;
            }
            
            h1 {
                font-size: 18px;
            }
            
            .subtitle {
                font-size: 12px;
            }
            
            th, td {
                padding: 8px 5px;
                font-size: 11px;
            }
            
            th {
                font-size: 10px;
            }
            
            .keyword {
                font-size: 12px;
            }
            
            .country {
                padding: 2px 4px;
                font-size: 11px;
            }
            
            .footer {
                font-size: 12px;
            }
        }
    </style>
</head>
<body>
    <h1>Top 10 Spreading Trends</h1>
    <div class="subtitle">Generated on ' . date('F j, Y \a\t g:i A') . '</div>
    
    <table>
        <thead>
            <tr>
                <th>Search Keyword</th>
                <th>First Country</th>
                <th style="text-align: center;"># of Add\'l Countries Spread To</th>
                <th style="text-align: right;">Search Volume</th>
            </tr>
        </thead>
        <tbody>
';

foreach ($top10 as $keyword => $data) {
    $html .= '            <tr>
                <td class="keyword">' . htmlspecialchars($keyword) . '</td>
                <td><span class="country">' . htmlspecialchars($data['first_country']) . '</span></td>
                <td class="count">' . $data['spread_to_count'] . '</td>
                <td class="volume">' . formatVolume($data['total_volume']) . '</td>
            </tr>
';
}

$html .= '        </tbody>
    </table>
    
    <div class="footer">
        Data collected from ' . count($files) . ' hourly snapshots on ' . $targetDate . '
    </div>
</body>
</html>';

file_put_contents($htmlFile, $html);

echo "HTML table saved to: $htmlFile\n";
echo "File size: " . number_format(filesize($htmlFile)) . " bytes\n\n";

echo str_repeat("=", 60) . "\n";
echo "Processing complete!\n";
