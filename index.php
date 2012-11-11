<html>
  <head>
    <script type="text/javascript" src="https://www.google.com/jsapi"></script>
    <script type="text/javascript">
      google.load("visualization", "1", {packages:["corechart"]});
      google.setOnLoadCallback(drawChart);
      function drawChart() {
        var data = google.visualization.arrayToDataTable([
            ['date', '10am', 'Midday', '2pm'],
<?php

/*
   A web page to display the results of my solar.php script. It assumes that you
   run the script three times a day (at 10, 12 and 14 hours), alter the line above
   if this is not the case. The log file is assumed to be ../../log.txt, edit the
   line bellow if this is not the case.
   http://solar.kitten-x.com
*/

error_reporting(E_ALL);
ini_set('display_errors', '1');

$lines = file('../../log.txt');

$i = 0;

while($i < count($lines))
{
  $out = '';
  $curDate = getD($lines[$i]);
  $c = 0;
  while (($i < count($lines)) && ($curDate == getD($lines[$i])))
  {
    $out .= ', '.trim(explode(' ', $lines[$i])[1]);
    $i++;
    $c++;
  }
  if ($c == 3)
    echo "['".date("j-M", strtotime(getD($lines[$i - 1])))."'".$out."],\n";
}

function getD($s)
{
  return substr($s, 0, 10);
}

?>
]);

        var options = {
          title: 'Solar Performance',
          hAxis: {title: 'date', titleTextStyle: {color: 'red'}},
          isStacked: true
        };

        var chart = new google.visualization.ColumnChart(document.getElementById('chart_div'));
        chart.draw(data, options);
      }
    </script>
  </head>
  <body>
    <div id="chart_div" style="min-width: 500px; height: 600px;"></div>
  </body>
</html>

