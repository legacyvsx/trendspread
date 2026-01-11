<b>TrendSpread</b><p>

TrendSpread.global is a php app that tracks worldwide search trends via Google Trends and creates some interesting visualizations:</br>
<ul>
  <li>A heatmap showing how trends spread between countries (on a given day) (uses OpenStreetMap/Leaflet.js).</li>
  <li>A word cloud of trends from that day (created with ChartJS). </li>
  <li>A pie chart which categorizes each of the trends into weather, sports, entertainment, finance/business, politics/news, technology, other (created with quickchart.io, no API key needed).</li>
</ul>
<br/><br/>
It requires API keys from xAI and this 3rd party API from RapidAPI for Google Trends data: https://rapidapi.com/superbapi-superbapi-default/api/google-trends-api4 - generating the heatmap requires the basic plan, everything else can be done on the free plan.<br/>
I'm using a 3rd party API because pytrends stopped working a few months ago and Google doesn't have an official API that's public (though you can request access I believe). <br/><br/>

Make sure your API keys and paths are correctly listed at the top of each php file. You'll want to run these scripts in a crontab to automatically update daily. Here is mine:<br/><br/>

<pre>
# Daily trends pipeline (for word cloud/pie chart) - Run BEFORE hourly scrapes
0 0 * * * php /var/www/morallyrelative.com/trends/scrape_trends.php >> ~/logs/trends_daily.log 2>&1
3 0 * * * php /var/www/morallyrelative.com/trends/filter_trends.php >> ~/logs/trends_daily.log 2>&1
8 0 * * * php /var/www/morallyrelative.com/trends/cleanup_duplicates.php >> ~/logs/trends_daily.log 2>&1
10 0 * * * php /var/www/morallyrelative.com/trends/generate_wordcloud_chartjs.php >> ~/logs/trends_daily.log 2>&1
11 0 * * * php /var/www/morallyrelative.com/trends/categorize_and_chart.php >> ~/logs/trends_daily.log 2>&1

# Hourly scraper (for heatmap) - Starts at 12:05 AM, doesn't conflict
5 0 * * * php /var/www/morallyrelative.com/trends/scrape_trends_hourly.php >> ~/logs/trends_hourly.log 2>&1
0 6 * * * php /var/www/morallyrelative.com/trends/scrape_trends_hourly.php >> ~/logs/trends_hourly.log 2>&1
0 12 * * * php /var/www/morallyrelative.com/trends/scrape_trends_hourly.php >> ~/logs/trends_hourly.log 2>&1
0 18 * * * php /var/www/morallyrelative.com/trends/scrape_trends_hourly.php >> ~/logs/trends_hourly.log 2>&1
55 23 * * * php /var/www/morallyrelative.com/trends/scrape_trends_hourly.php >> ~/logs/trends_hourly.log 2>&1

# Spread analysis (uses hourly data)
58 23 * * * php /var/www/morallyrelative.com/trends/process_daily_spread.php >> ~/logs/trends_spread.log 2>&1
59 23 * * * php /var/www/morallyrelative.com/trends/generate_heatmap.php >> ~/logs/trends_heatmap.log 2>&1

</pre>
<br/><br/>
If you want daily data that can be viewed separately, it'll be in top10_trends_spread.html, wordcloud.html, and category_piechart.png, all of which update daily (assuming you run this in a crontab as above). 
<br/><br/>You can find me at <a href="https://x.com/h45hb4ng">@h45hb4ng</a> or check out some of my other work at https://morallyrelative.com<br/><br/>
https://trendspread.global | data posted to <a href="https://x.com/h45hb4ng_data">@h45hb4ng_data</a>
</p>
