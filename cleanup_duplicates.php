<?php
// cleanup_duplicates.php - Normalize punctuation and re-aggregate duplicates

$inputFile = 'trends/trends_filtered.log';
$outputFile = 'trends_cleaned.log';

echo "Cleaning up duplicate trends...\n";
echo "Input: $inputFile\n";
echo "Output: $outputFile\n\n";

// Read filtered trends
$lines = file($inputFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

// Skip header lines (first 2 lines)
$header1 = array_shift($lines);
$header2 = array_shift($lines);

$trends = [];
$totalInput = 0;

foreach ($lines as $line) {
    $parts = explode("\t", $line);
    if (count($parts) < 4) continue;
    
    list($keyword, $volume, $countryCount, $countries) = $parts;
    $totalInput++;
    
    // NORMALIZE PUNCTUATION - MORE AGGRESSIVE
    $normalized = $keyword;
    
    // First, replace all variations of dashes/hyphens with a standard space-hyphen-space pattern
    // This includes: hyphen (-), en-dash (–), em-dash (—), minus (−), etc.
    $normalized = preg_replace('/\s*[\-\x{2010}-\x{2015}\x{2212}]\s*/u', ' - ', $normalized);
    
    // Normalize "vs" variations to " - "
    $normalized = preg_replace('/\s+vs\.?\s+/i', ' - ', $normalized);
    
    // Normalize "x" (as separator) to " - "
    $normalized = preg_replace('/\s+x\s+/i', ' - ', $normalized);
    
    // Remove "f.c." variations
    $normalized = preg_replace('/\s+f\.c\.\s+/i', ' ', $normalized);
    
    // Remove trailing words like "prediction", "match", etc that don't add meaning
    $normalized = preg_replace('/\s+(prediction|match|game|fixtures?|live|stream|highlights?)$/i', '', $normalized);
    
    // Normalize whitespace
    $normalized = preg_replace('/\s+/', ' ', trim($normalized));
    
    // Lowercase for matching
    $key = strtolower($normalized);
    
    // Parse countries
    $countryList = array_filter(explode(',', $countries));
    
    // Aggregate
    if (!isset($trends[$key])) {
        $trends[$key] = [
            'keyword' => $normalized, // Keep normalized version
            'volume' => 0,
            'countries' => []
        ];
    }
    
    $trends[$key]['volume'] += (int)$volume;
    $trends[$key]['countries'] = array_merge($trends[$key]['countries'], $countryList);
}

echo "Total input entries: $totalInput\n";

// Deduplicate countries and sort by volume
foreach ($trends as &$trend) {
    $trend['countries'] = array_unique($trend['countries']);
    sort($trend['countries']);
}

uasort($trends, function($a, $b) {
    return $b['volume'] - $a['volume'];
});

echo "Unique trends after cleanup: " . count($trends) . "\n";

// Write output
$timestamp = date('Y-m-d H:i:s');
file_put_contents($outputFile, "# Cleaned Google Trends: $timestamp\n");
file_put_contents($outputFile, "# Format: KEYWORD\tTOTAL_VOLUME\tCOUNTRY_COUNT\tCOUNTRIES\n", FILE_APPEND);

foreach ($trends as $data) {
    $keyword = $data['keyword'];
    $volume = $data['volume'];
    $countryCount = count($data['countries']);
    $countries = implode(',', $data['countries']);
    
    $line = "$keyword\t$volume\t$countryCount\t$countries\n";
    file_put_contents($outputFile, $line, FILE_APPEND);
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "Cleanup complete!\n";
echo "Saved to: $outputFile\n";
