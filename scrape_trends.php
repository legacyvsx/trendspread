<?php
// scrape_trends.php - Scrape Google Trends via RapidAPI

// RapidAPI credentials
define('RAPIDAPI_KEY', '');
define('RAPIDAPI_HOST', 'google-trends-api4.p.rapidapi.com');

// All 125 countries supported by Google Trends Daily Trending
$countries = [
    'AE' => 'United Arab Emirates', 'AL' => 'Albania', 'AM' => 'Armenia', 'AO' => 'Angola',
    'AR' => 'Argentina', 'AT' => 'Austria', 'AU' => 'Australia', 'AZ' => 'Azerbaijan',
    'BA' => 'Bosnia and Herzegovina', 'BD' => 'Bangladesh', 'BE' => 'Belgium', 'BG' => 'Bulgaria',
    'BH' => 'Bahrain', 'BO' => 'Bolivia', 'BR' => 'Brazil', 'BW' => 'Botswana',
    'CA' => 'Canada', 'CH' => 'Switzerland', 'CI' => 'Ivory Coast', 'CL' => 'Chile',
    'CM' => 'Cameroon', 'CO' => 'Colombia', 'CR' => 'Costa Rica', 'CY' => 'Cyprus',
    'CZ' => 'Czechia', 'DE' => 'Germany', 'DK' => 'Denmark', 'DO' => 'Dominican Republic',
    'DZ' => 'Algeria', 'EC' => 'Ecuador', 'EE' => 'Estonia', 'EG' => 'Egypt',
    'ES' => 'Spain', 'ET' => 'Ethiopia', 'FI' => 'Finland', 'FR' => 'France',
    'GB' => 'United Kingdom', 'GE' => 'Georgia', 'GH' => 'Ghana', 'GR' => 'Greece',
    'GT' => 'Guatemala', 'HK' => 'Hong Kong', 'HN' => 'Honduras', 'HR' => 'Croatia',
    'HU' => 'Hungary', 'ID' => 'Indonesia', 'IE' => 'Ireland', 'IL' => 'Israel',
    'IN' => 'India', 'IQ' => 'Iraq', 'IS' => 'Iceland', 'IT' => 'Italy',
    'JM' => 'Jamaica', 'JO' => 'Jordan', 'JP' => 'Japan', 'KE' => 'Kenya',
    'KG' => 'Kyrgyzstan', 'KH' => 'Cambodia', 'KR' => 'South Korea', 'KW' => 'Kuwait',
    'KZ' => 'Kazakhstan', 'LA' => 'Laos', 'LB' => 'Lebanon', 'LK' => 'Sri Lanka',
    'LT' => 'Lithuania', 'LU' => 'Luxembourg', 'LV' => 'Latvia', 'LY' => 'Libya',
    'MA' => 'Morocco', 'MD' => 'Moldova', 'ME' => 'Montenegro', 'MG' => 'Madagascar',
    'MK' => 'North Macedonia', 'MM' => 'Myanmar', 'MN' => 'Mongolia', 'MO' => 'Macau',
    'MT' => 'Malta', 'MU' => 'Mauritius', 'MW' => 'Malawi', 'MX' => 'Mexico',
    'MY' => 'Malaysia', 'MZ' => 'Mozambique', 'NA' => 'Namibia', 'NG' => 'Nigeria',
    'NI' => 'Nicaragua', 'NL' => 'Netherlands', 'NO' => 'Norway', 'NP' => 'Nepal',
    'NZ' => 'New Zealand', 'OM' => 'Oman', 'PA' => 'Panama', 'PE' => 'Peru',
    'PH' => 'Philippines', 'PK' => 'Pakistan', 'PL' => 'Poland', 'PR' => 'Puerto Rico',
    'PT' => 'Portugal', 'PY' => 'Paraguay', 'QA' => 'Qatar', 'RO' => 'Romania',
    'RS' => 'Serbia', 'SA' => 'Saudi Arabia', 'SE' => 'Sweden', 'SG' => 'Singapore',
    'SI' => 'Slovenia', 'SK' => 'Slovakia', 'SN' => 'Senegal', 'SV' => 'El Salvador',
    'TH' => 'Thailand', 'TN' => 'Tunisia', 'TR' => 'Turkey', 'TT' => 'Trinidad and Tobago',
    'TW' => 'Taiwan', 'TZ' => 'Tanzania', 'UA' => 'Ukraine', 'UG' => 'Uganda',
    'US' => 'United States', 'UY' => 'Uruguay', 'UZ' => 'Uzbekistan', 'VE' => 'Venezuela',
    'VN' => 'Vietnam', 'YE' => 'Yemen', 'ZA' => 'South Africa', 'ZM' => 'Zambia',
    'ZW' => 'Zimbabwe'
];

$logFile = 'trends.log';
$timestamp = date('Y-m-d H:i:s');

echo "Starting Google Trends scrape at $timestamp\n";
echo "Scraping " . count($countries) . " countries via RapidAPI...\n\n";


file_put_contents($logFile, "# Google Trends Scrape: $timestamp\n");

$totalTrends = 0;
$successCount = 0;
$failCount = 0;

foreach ($countries as $code => $name) {
    echo "Scraping $name ($code)... ";
    
    try {
        $trends = getTrendsForCountry($code);
        
        if (empty($trends)) {
            echo "No trends found\n";
            $failCount++;
            continue;
        }
        
        foreach ($trends as $trend) {
            $keyword = str_replace(["\n", "\t"], ' ', $trend['query']);
            $volume = $trend['traffic'] ?? 'N/A';
            $line = "$code\t$keyword\t$volume\n";
            file_put_contents($logFile, $line, FILE_APPEND);
            $totalTrends++;
        }
        
        echo count($trends) . " trends found\n";
        $successCount++;
        
        // Rate limit for API
        sleep(1);
        
    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
        $failCount++;
    }
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "Done! Total trends collected: $totalTrends\n";
echo "Successful countries: $successCount\n";
echo "Failed countries: $failCount\n";
echo "Saved to: $logFile\n";

function getTrendsForCountry($countryCode) {
    $url = "https://google-trends-api4.p.rapidapi.com/api/v3/trends?geo=" . $countryCode;
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "GET",
        CURLOPT_HTTPHEADER => [
            "x-rapidapi-host: " . RAPIDAPI_HOST,
            "x-rapidapi-key: " . RAPIDAPI_KEY
        ]
    ]);
    
    $response = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($err) {
        throw new Exception("cURL Error: $err");
    }
    
    if ($httpCode !== 200) {
        throw new Exception("HTTP $httpCode: $response");
    }
    
    $data = json_decode($response, true);
    
    if (!$data || !isset($data['trends'])) {
        return [];
    }
    
    $trends = [];
    foreach ($data['trends'] as $item) {
        $trends[] = [
            'query' => $item['keyword'] ?? 'Unknown',
            'traffic' => $item['trafficStats'] ?? 'N/A'
        ];
    }
    
    return $trends;
}
