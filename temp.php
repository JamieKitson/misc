<?php

function getifset($name, $default)
{
    return isset($_GET[$name]) ? $_GET[$name] : $default;
}

$span = getifset('span', 24 * 60 * 60);
$max = getifset('max', time());
$min = getifset('min', $max - $span);

$span = $max - $min;

$prev = "?min=" . ($min - $span) . "&max=" . ($max - $span);
$next = "?min=" . ($min + $span) . "&max=" . ($max + $span);

?>
<html>
  <head>
    <link rel="next" href="<?php echo $next ?>">
    <link rel="prev" href="<?php echo $prev ?>">
    <script type="text/javascript" src="https://www.google.com/jsapi"></script>
    <script type="text/javascript">
      google.load("visualization", "1", {packages:["corechart"]});
      google.setOnLoadCallback(drawChart);
      function drawChart() {
        var data = google.visualization.arrayToDataTable([
<?php

$lines = file('../../temp.log');

//for ($i = 0; /* max(0, count($lines) - 24 * 60); */ $i < count($lines); $i++)
foreach($lines as $line)
{
    $bits = explode(' ', trim($line));
    $tim = $bits[0];
    if (!isset($first))
        $first = $bits[0];
    if ((count($bits) >= 5) && ($tim <= $max) && ($tim >= $min))
    {
        if (!isset($c))
        {
            $c = count($bits);
            switch ($c)
            {
                case 5: echo "['Time', 'fan speed', 'temp1', 'temp2', 'temp3'],\n"; break;
                case 8: echo "['Time', 'fan speed', 'temp1', 'temp2', 'temp3', 'CPU', 'Tx', 'Rx'],\n"; break;
            }
        }
        echo "['" . date("H:i", $bits[0]) . "'";
        for ( $i = 1; $i < $c; $i++ )
        {
            if ($i >= 6)
                echo ", " . $bits[$i] / (1024);
            else
                echo ", " . $bits[$i];
        }
        echo "],\n";
        // echo sprintf("['%s', %d, %.1f, %.1f, %d],\n", date("H:i", $bits[0]), $bits[1], $bits[2], $bits[3], $bits[4]);
    }
}

?>
]);

        var options = {
          title: 'Server temperature',
          hAxis: {title: 'Time', titleTextStyle: {color: 'red'}},
          seriesType: "line",
          series:{
            0:{targetAxisIndex:0 /*, type: "bars"*/ },
            1:{targetAxisIndex:1},
            2:{targetAxisIndex:1},
            3:{targetAxisIndex:1},
            4:{targetAxisIndex:1},
            5:{targetAxisIndex:0},
            6:{targetAxisIndex:0}},
          vAxes: {
            0: {minValue: 0, maxValue: 2000, format: "# RPM"},
            1: {minValue: 30, maxValue: 70, format: "#"}}, 
        };

        var chart = new google.visualization.LineChart(document.getElementById('chart_div'));
        chart.draw(data, options);
      }
    </script>
  </head>
  <body>
    <div id="chart_div" style="min-width: 500px; height: 800px;"></div>
    <a href="?min=<?php echo $first . "&max=" . ($first + $span) ?>">First</a>
    <a href="<?php echo $prev  ?>">Previous</a>
    <a href="<?php echo $next ?>">Next</a>
    <a href="?min=<?php echo time() - $span ?>">Last</a>
  </body>
</html>

