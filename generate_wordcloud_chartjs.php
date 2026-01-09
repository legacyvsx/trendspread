<?php
// generate_wordcloud_chartjs.php - Generate Chart.js word cloud HTML

$inputFile = 'trends_cleaned.log';
$outputFile = 'wordcloud.html';

echo "Generating Chart.js word cloud HTML...\n";
echo "Input: $inputFile\n";

// Read cleaned trends
$lines = file($inputFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

// Skip header lines
array_shift($lines);
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
