<?php

/*
   A script to poll the enecsys website for solar panel output power
   and email an alert if it falls below a certain level. See also
   http://www.fhemwiki.de/wiki/Enecsys_Monitoring_System
*/

$warn = 10;

$dir = dirname(__FILE__);

$user = trim(file_get_contents("$dir/username.txt"));
$pass = trim(file_get_contents("$dir/password.txt"));
$emails = trim(file_get_contents("$dir/emails.txt"));
$log = "$dir/log.txt";

$result = doCurl('https://monitor.enecsys.net');

$DOM = new DOMDocument;
@$DOM->loadHTML($result);

$items = $DOM->getElementsByTagName('input');

$data = array('login1$UserName' => $user, 'login1$Password' => $pass);

for ($i = 0; $i < $items->length; $i++)
  if ($items->item($i)->getAttribute('type') == 'hidden')
    $data[$items->item($i)->getAttribute('name')] = $items->item($i)->getAttribute('value');

$data['__EVENTTARGET'] = 'login1$lnkLogin';

$extras = array(CURLOPT_POST => true, CURLOPT_POSTFIELDS => $data);

$result = doCurl('https://monitor.enecsys.net', $extras);

$result = doCurl('https://monitor.enecsys.net/ews/InstallationService.asmx/GetCurrentInstallationStatus');

            $p = xml_parser_create();
            xml_parse_into_struct($p, $result, $results, $index);
            xml_parser_free($p);

//echo print_r($results);
$power = $results[$index['CURRENTACPOWER'][0]]['value'];
$unit = $results[$index['CURRENTACPOWERUNIT'][0]]['value'];
$msg = "";
switch ($unit) {
  case "W": break;
  case "kW": $power *= 1000; break;
  default: $msg = "Warning: unknown unit: $unit";
}

file_put_contents($log, date("d-H:i:s "). "$power\n", FILE_APPEND);

if (($power < $warn) || !empty($msg))
  mail($emails, "Solar Power Level", "Solar power level is currently at $power watts. $msg");

function doCurl($url, $extras = array())
{

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; rv:16.0) Gecko/20100101 Firefox/16.0');
curl_setopt($ch, CURLOPT_COOKIEJAR, 'cookies.txt');
curl_setopt($ch, CURLOPT_COOKIEFILE, 'cookies.txt');
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_REFERER, $url);
curl_setopt($ch, CURLOPT_AUTOREFERER, true);
curl_setopt_array($ch, $extras);
$result = curl_exec($ch);
curl_close($ch);

return $result;

}

?>
