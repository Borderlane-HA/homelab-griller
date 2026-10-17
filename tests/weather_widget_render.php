<?php
// Isolated regression test: feed deterministic weather data into the original renderer.
$code = file_get_contents(__DIR__.'/../app/index.php');
$start = strpos($code,'function page_event_weather(array $event): void {');
$stop  = strpos($code,"\nfunction page_weather_search_api()", $start);
if ($start === false || $stop === false) {fwrite(STDERR, "Weather renderer missing\n"); exit(1);}
$renderer = substr($code,$start,$stop-$start);
function weather_day($d){ return ['icon'=>'🌦️','min'=>7,'max'=>17,'rain'=>80,'wind'=>14,'sun'=>6]; }
function setting($k){return $k==='weather_city'?'Eitting, Bavaria, Germany':'';}
function weather_data(){ $x=['hourly'=>['time'=>[], 'temperature_2m'=>[], 'precipitation_probability'=>[], 'wind_speed_10m'=>[], 'weather_code'=>[]]];
  for ($i=10;$i<=22;$i+=2){$x['hourly']['time'][]='2026-10-17T'.str_pad((string)$i,2,'0',STR_PAD_LEFT).':00';$x['hourly']['temperature_2m'][]=18-($i-10)/2;$x['hourly']['precipitation_probability'][]=10;$x['hourly']['wind_speed_10m'][]=12;$x['hourly']['weather_code'][]=3;}return $x; }
function weather_icon($x){return '🌤️';}
function h($x){return htmlspecialchars((string)$x,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function t($x){return ['weather_forecast'=>'Wettervorhersage','weather_show_details'=>'Details anzeigen','weather_hide_details'=>'Weniger anzeigen','weather_hours_label'=>'Stündliche Vorhersage','inventory_sun'=>'Sonne','forecast_unavailable'=>'Keine Prognose','weather_hourly_update'=>'Aktualisierung stündlich'][$x]??$x;}
eval($renderer);
ob_start(); page_event_weather(['event_date'=>'2026-10-17']); $html=ob_get_clean();
foreach ([
  ['<details class="card weather-event-card weather-disclosure">', 'weather container'],
  ['<summary class="weather-disclosure-summary">', 'collapsible summary'],
  ['Details', 'details label'],
  ['Eitting, Bavaria, Germany', 'location'],
] as [$needle,$label]) {
  if (strpos($html,$needle)===false) {fwrite(STDERR,"Missing $label\n");exit(1);}
}
if (strpos($html,'<details open')!==false || substr_count($html,'class="forecast-hour"')!==7) {
  fwrite(STDERR, "Incorrect default or hourly forecast\n");exit(1);
}
echo $html;
