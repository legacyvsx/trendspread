<?php
// generate_heatmap.php - Generate geographic heatmap for a spreading trend

$baseDir = '';
$spreadDir = "$baseDir/spread";

echo "Generating trend spread heatmap\n";
echo str_repeat("=", 60) . "\n\n";

// Find most recent spread file
$spreadFiles = glob("$spreadDir/*-spread.json");
if (empty($spreadFiles)) {
    die("ERROR: No spread analysis files found. Run process_daily_spread.php first.\n");
}

// Sort by modification time, get most recent
usort($spreadFiles, function($a, $b) {
    return filemtime($b) - filemtime($a);
});

$spreadFile = $spreadFiles[0];
echo "Using spread data: " . basename($spreadFile) . "\n";

// Load spread data
$spreadData = json_decode(file_get_contents($spreadFile), true);

if (!$spreadData || !isset($spreadData['spreading_trends'])) {
    die("ERROR: Invalid spread data\n");
}

$spreadingTrends = $spreadData['spreading_trends'];
echo "Found " . count($spreadingTrends) . " spreading trends\n\n";

// Prepare all trends data for JavaScript
$allTrendsJson = json_encode($spreadingTrends);

// Get top trend as default
$topTrend = array_key_first($spreadingTrends);

echo "Generating interactive heatmap\n";
echo "Top trend: $topTrend\n";

// Generate HTML with Leaflet map
$html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TrendSpread - Global Trends Heatmap</title>
    
    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        #map {
            width: 100%;
            height: 100vh;
        }
        .info-box {
            position: absolute;
            top: 20px;
            left: 20px;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            z-index: 1000;
            max-width: 350px;
        }
        .info-box h2 {
            margin: 0 0 15px 0;
            font-size: 18px;
            color: #2c3e50;
        }
        .info-box select {
            width: 100%;
            padding: 8px;
            margin-bottom: 15px;
            border: 2px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            background: white;
            cursor: pointer;
        }
        .info-box select:focus {
            outline: none;
            border-color: #3498db;
        }
        .info-box p {
            margin: 5px 0;
            color: #666;
            font-size: 14px;
        }
        .legend {
            position: absolute;
            bottom: 30px;
            right: 30px;
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            z-index: 1000;
        }
        .legend h3 {
            margin: 0 0 10px 0;
            font-size: 14px;
            font-weight: bold;
        }
        .legend-item {
            display: flex;
            align-items: center;
            margin: 5px 0;
            font-size: 12px;
        }
        .legend-color {
            width: 30px;
            height: 15px;
            margin-right: 8px;
            border: 1px solid #333;
        }
        .leaflet-popup-content {
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="info-box">
        <h2>Trend Spread Heatmap</h2>
        
        <select id="trendSelector">
            <option value="">Select a trend...</option>
        </select>
        
        <div id="trendInfo" style="display: none;">
            <p><strong>Keyword:</strong> <span id="trendKeyword"></span></p>
            <p><strong>Countries:</strong> <span id="trendCountries"></span></p>
            <p><strong>Total Volume:</strong> <span id="trendVolume"></span></p>
            <p><strong>Range:</strong> <span id="trendTimeRange"></span></p>
        </div>
    </div>
    
    <div class="legend">
        <h3>Trend Appearance</h3>
        <div class="legend-item">
            <div class="legend-color" style="background: #053061;"></div>
            <span>12:05 AM</span>
        </div>
        <div class="legend-item">
            <div class="legend-color" style="background: #2166ac;"></div>
            <span>6:00 AM</span>
        </div>
        <div class="legend-item">
            <div class="legend-color" style="background: #92c5de;"></div>
            <span>12:00 PM</span>
        </div>
        <div class="legend-item">
            <div class="legend-color" style="background: #f4a582;"></div>
            <span>6:00 PM</span>
        </div>
        <div class="legend-item">
            <div class="legend-color" style="background: #b2182b;"></div>
            <span>11:55 PM</span>
        </div>
        <div class="legend-item">
            <div class="legend-color" style="background: #cccccc;"></div>
            <span>No data</span>
        </div>
    </div>
    
    <div id="map"></div>
    
    <!-- Leaflet JS -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    
    <script>
        // All trends data from PHP
        const allTrends = ' . $allTrendsJson . ';
        
        // Global variables
        let map;
        let geoJsonLayer;
        let countriesData;
        
        // Fixed time-of-day based coloring
        function getColorByTime(dateStr) {
            const date = new Date(dateStr);
            const hour = date.getHours();
            const minute = date.getMinutes();
            
            // Convert to total minutes since midnight for easier comparison
            const totalMinutes = hour * 60 + minute;
            
            // Map to specific snapshot times (with some tolerance)
            if (totalMinutes <= 60) {
                // Around 12:05 AM (0:05)
                return "#053061"; // Darkest blue
            } else if (totalMinutes >= 330 && totalMinutes <= 390) {
                // Around 6:00 AM (360 minutes = 6:00)
                return "#2166ac"; // Dark blue
            } else if (totalMinutes >= 690 && totalMinutes <= 750) {
                // Around 12:00 PM (720 minutes = 12:00)
                return "#92c5de"; // Light blue
            } else if (totalMinutes >= 1050 && totalMinutes <= 1110) {
                // Around 6:00 PM (1080 minutes = 18:00)
                return "#f4a582"; // Tan/orange
            } else if (totalMinutes >= 1430) {
                // Around 11:55 PM (1435 minutes = 23:55)
                return "#b2182b"; // Dark red
            }
            
            // Default fallback
            return "#92c5de";
        }
        
        // Format number with K/M suffix
        function formatNumber(num) {
            if (num >= 1000000) {
                return (num / 1000000).toFixed(1) + "M";
            } else if (num >= 1000) {
                return (num / 1000).toFixed(1) + "K";
            }
            return num.toString();
        }
        
        // Format date with correct time - FIXED to preserve exact minutes
        function formatDateTime(dateStr) {
            const date = new Date(dateStr);
            
            const months = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
            const month = months[date.getMonth()];
            const day = date.getDate();
            const year = date.getFullYear();
            
            let hours = date.getHours();
            const minutes = date.getMinutes();
            const ampm = hours >= 12 ? "PM" : "AM";
            hours = hours % 12;
            hours = hours ? hours : 12; // 0 should be 12
            const minutesStr = minutes < 10 ? "0" + minutes : minutes;
            
            return month + " " + day + ", " + year + " " + hours + ":" + minutesStr + ampm;
        }
        
        // Update map with selected trend
        function updateMap(keyword) {
            const trendData = allTrends[keyword];
            
            if (!trendData) {
                console.error("Trend not found:", keyword);
                return;
            }
            
            // Update info box
            document.getElementById("trendKeyword").textContent = keyword;
            document.getElementById("trendCountries").textContent = trendData.country_count;
            document.getElementById("trendVolume").textContent = formatNumber(trendData.total_volume);
            
            const timeStart = formatDateTime(trendData.earliest_appearance);
            const timeEnd = formatDateTime(trendData.latest_appearance);
            document.getElementById("trendTimeRange").textContent = timeStart + " - " + timeEnd;
            
            document.getElementById("trendInfo").style.display = "block";
            
            console.log("Updating map for:", keyword);
            console.log("Earliest:", trendData.earliest_appearance);
            console.log("Latest:", trendData.latest_appearance);
            
            // Update the GeoJSON layer styles
            if (geoJsonLayer) {
                geoJsonLayer.eachLayer(function(layer) {
                    const iso2 = layer.feature.properties["ISO3166-1-Alpha-2"];
                    const countryInfo = iso2 ? trendData.countries[iso2] : null;
                    
                    if (countryInfo) {
                        layer.setStyle({
                            fillColor: getColorByTime(countryInfo.first_seen),
                            fillOpacity: 0.7,
                            color: "#333",
                            weight: 1
                        });
                    } else {
                        layer.setStyle({
                            fillColor: "#cccccc",
                            fillOpacity: 0.3,
                            color: "#666",
                            weight: 1
                        });
                    }
                    
                    // Update popup binding
                    layer.off("mouseover");
                    layer.off("mouseout");
                    
                    const countryName = layer.feature.properties.name || "Unknown";
                    
                    layer.on("mouseover", function(e) {
                        this.setStyle({
                            weight: 3,
                            fillOpacity: 0.9
                        });
                        
                        let popupContent = "<strong>" + countryName + "</strong>";
                        if (countryInfo) {
                            popupContent += "<br>First seen: " + formatDateTime(countryInfo.first_seen);
                            popupContent += "<br>Volume: " + countryInfo.volume.toLocaleString();
                        } else {
                            popupContent += "<br><em>No data</em>";
                        }
                        
                        this.bindPopup(popupContent).openPopup();
                    });
                    
                    layer.on("mouseout", function(e) {
                        this.setStyle({
                            weight: 1,
                            fillOpacity: countryInfo ? 0.7 : 0.3
                        });
                        this.closePopup();
                    });
                });
            }
        }
        
        // Initialize map with different views for mobile vs desktop
        const isMobile = window.innerWidth <= 768;
        if (isMobile) {
            // Mobile: Center on US
            map = L.map("map").setView([37.0902, -95.7129], 3);
        } else {
            // Desktop: Show full world
            map = L.map("map").setView([20, 0], 2);
        }
        
        // Add OpenStreetMap tiles
        L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
            attribution: "© OpenStreetMap contributors",
            maxZoom: 18
        }).addTo(map);
        
        // Populate dropdown
        const selector = document.getElementById("trendSelector");
        Object.keys(allTrends).forEach(function(keyword) {
            const option = document.createElement("option");
            option.value = keyword;
            option.textContent = keyword + " (" + allTrends[keyword].country_count + " countries)";
            selector.appendChild(option);
        });
        
        // Set first trend as default
        const firstTrend = Object.keys(allTrends)[0];
        selector.value = firstTrend;
        
        // Add change event listener
        selector.addEventListener("change", function() {
            if (this.value) {
                updateMap(this.value);
            }
        });
        
        // Load country boundaries GeoJSON
        fetch("https://raw.githubusercontent.com/datasets/geo-countries/master/data/countries.geojson")
            .then(response => response.json())
            .then(data => {
                countriesData = data;
                
                // Add country borders as a layer
                geoJsonLayer = L.geoJSON(data, {
                    style: {
                        fillColor: "#cccccc",
                        fillOpacity: 0.3,
                        color: "#666",
                        weight: 1
                    }
                }).addTo(map);
                
                console.log("Loaded country boundaries");
                
                // Load first trend
                updateMap(firstTrend);
            })
            .catch(error => {
                console.error("Error loading country boundaries:", error);
            });
    </script>
</body>
</html>';

$outputFile = "$baseDir/trend_heatmap.html";
file_put_contents($outputFile, $html);

echo "Heatmap generated: $outputFile\n";
echo "Open in browser to view\n";
