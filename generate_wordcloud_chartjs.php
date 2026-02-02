<?php
// generate_wordcloud_chartjs.php - Generate Chart.js word cloud HTML

$baseDir = '';
$hourlyDir = "$baseDir/hourly";
$outputFile = "$baseDir/wordcloud.html";

echo "Generating Chart.js word cloud HTML...\n";

// Get current date for filtering
$targetDate = date('Y-m-d');

// Find all clean hourly files from today
$allFiles = glob("$hourlyDir/*.log");
$allFiles = array_filter($allFiles, function($file) {
    return substr($file, -8) !== '-raw.log';
});

if (empty($allFiles)) {
    die("ERROR: No hourly data files found\n");
}

// Filter to only files from today
$todayFiles = array_filter($allFiles, function($file) use ($targetDate) {
    return strpos(basename($file), $targetDate) === 0;
});

// Take the 5 most recent from today
usort($todayFiles, function($a, $b) {
    return filemtime($b) - filemtime($a);
});
$files = array_slice($todayFiles, 0, 5);

if (empty($files)) {
    die("ERROR: No hourly data files found for today ($targetDate)\n");
}

echo "Using " . count($files) . " hourly files from $targetDate\n";

// Load and aggregate all trends from hourly files
$allTrends = [];
foreach ($files as $file) {
    echo "Loading " . basename($file) . "... ";
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    $count = 0;
    foreach ($lines as $line) {
        if (substr($line, 0, 1) === '#') continue; // Skip headers
        
        $parts = explode("\t", $line);
        if (count($parts) < 4) continue;
        
        list($timestamp, $country, $keyword, $volume) = $parts;
        
        $keyword = trim($keyword);
        $volume = (int)$volume;
        
        if (!isset($allTrends[$keyword])) {
            $allTrends[$keyword] = 0;
        }
        
        $allTrends[$keyword] += $volume;
        $count++;
    }
    
    echo "$count entries loaded\n";
}

// Convert to array and sort by volume
$trends = [];
foreach ($allTrends as $keyword => $volume) {
    $trends[] = [
        'keyword' => $keyword,
        'volume' => $volume
    ];
}

usort($trends, function($a, $b) {
    return $b['volume'] - $a['volume'];
});

// Increase to 100 keywords to fill more space
$trends = array_slice($trends, 0, 100);
echo "Using top " . count($trends) . " keywords\n";

// Find max volume for scaling
$maxVolume = max(array_column($trends, 'volume'));

// Build data for Chart.js - use SQUARE ROOT scaling
$labels = [];
$data = [];
foreach ($trends as $trend) {
    $labels[] = $trend['keyword'];
    // Square root scaling with slightly smaller max size to fit more words
    $sqrtRatio = sqrt($trend['volume']) / sqrt($maxVolume);
    $size = 8 + ($sqrtRatio * 55);  // Range: 8-63px (slightly smaller)
    $data[] = $size;
}

$labelsJson = json_encode($labels);
$dataJson = json_encode($data);

// Generate HTML
$html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Google Trends Word Cloud</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-chart-wordcloud@4.4.0/build/index.umd.min.js"></script>
        <style>
        @import url('https://fonts.googleapis.com/css2?family=Montserrat:wght@700&display=swap');

        body {
            margin: 0;
            padding: 0;
            background: white;
            font-family: 'Montserrat', sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        #container {
            width: 100%;
            max-width: 1200px;
            height: 800px;
            margin: 0 auto;
            padding: 20px;
            box-sizing: border-box;
        }

        @media (max-width: 768px) {
            #container {
                height: 600px;
                padding: 10px;
            }
        }
    </style>
</head>
<body>
    <div id="container">
        <canvas id="wordcloud"></canvas>
    </div>

    <script>
        // Wait for font to load
        document.fonts.ready.then(function() {
            const ctx = document.getElementById('wordcloud').getContext('2d');

            new Chart(ctx, {
                type: 'wordCloud',
                data: {
                    labels: $labelsJson,
                    datasets: [{
                        label: 'Google Trends',
                        data: $dataJson
                    }]
                },
                options: {
                    devicePixelRatio: 1,
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    elements: {
                        word: {
                            color: function(context) {
                                const colors = ['#1f77b4', '#ff7f0e', '#2ca02c', '#d62728', '#9467bd', '#8c564b', '#e377c2', '#7f7f7f'];
                                return colors[context.dataIndex % colors.length];
                            },
                            fontFamily: 'Montserrat',
                            fontWeight: 'bold',
                            padding: 1.5,
                            minRotation: -90,      // -90 degrees (vertical)
                            maxRotation: 0,        // 0 degrees (horizontal)
                            rotationSteps: 2       // Only 2 steps: exactly 0° or -90°
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>
HTML;

file_put_contents($outputFile, $html);

echo "\n" . str_repeat("=", 60) . "\n";
echo "HTML generated successfully!\n";
echo "Saved to: $outputFile\n";
echo "\nOpen in browser: http://morallyrelative.com/trends/wordcloud.html\n";
