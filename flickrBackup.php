<?php

define("TITLE_TAG", "Exif.Image.ImageDescription");
define("DESCRIPTION_TAG", "Exif.Photo.UserComment");
define("TAG_TAG", "Iptc.Application2.Keywords");
define("LATITUDE_TAG", "Exif.GPSInfo.GPSLatitude");
define("LONGITUDE_TAG", "Exif.GPSInfo.GPSLongitude");
define("TOKEN", "token");
define("SECRET", "secret");
define("DIRECTORY_FORMAT", "Y-m");
define("FILENAME_FORMAT", "d-H-i-\T\I\T\L\E-\I\D");

function getFileName($photo)
{
    $filename = date(FILENAME_FORMAT, strtotime($photo['datetaken']));
    $title = sanitize($photo['title']); 
    $filename = str_replace("TITLE", $title, $filename);
    $filename = str_replace("ID", $photo['id'], $filename);
    $filename = $filename.'.'.$photo['originalformat'];
    return $filename;
}

include('flickrapisecret.php');

function flickrCall($params, $uri = "rest")
{
        $params['oauth_consumer_key'] = 'b51ae39f6b166d53ea1c4bd4751de3e0';
        $params['oauth_nonce'] = rand(0, 99999999);
        $params['oauth_timestamp'] = date('U');
        $params['oauth_signature_method'] = 'HMAC-SHA1';
        $params['oauth_version'] = '1.0';
        $params['format'] = 'php_serial';
        $token = getFiled(TOKEN);
        if ($token != '')
          $params['oauth_token'] = $token;

        $encoded_params = array();
        foreach ($params as $k => $v)
        {
                $encoded_params[] = urlencode($k).'='.urlencode($v); // "$k=$v"; //
        }

        sort($encoded_params);
        $p = implode('&', $encoded_params);

        $url = "http://api.flickr.com/services/$uri";

        $base = "GET&".urlencode($url)."&".urlencode($p);

        $tokensecret = getFiled(SECRET);

        $sig = urlencode(base64_encode(hash_hmac('sha1', $base, $GLOBALS['apisecret']."&$tokensecret", true)));

        $url .= "?$p&oauth_signature=$sig";

//        echo $url."\n";

        $rsp = gzipCall($url);

//        echo $rsp."\n"; 

        return $rsp;

}

function testFlickr()
{
  if ((getFiled(TOKEN) != '') && (getFiled(SECRET) != ''))
  {
    $rsp = flickrCall(Array('method' => 'flickr.test.login'));
    $p = unserialize($rsp);
    if (isset($p['stat']) && ($p['stat'] == 'ok'))
        return true;
//      return str_replace("@", "_", $p['user']['id']);
  }
  return false;
}

function getFiled($name)
{
    if (isset($GLOBALS[$name]))
        return $GLOBALS[$name];
    if (file_exists($name))
        return trim(file_get_contents($name));
    return '';
}

function setFiled($name, $value)
{
    $GLOBALS[$name] = $value;
    file_put_contents($name, $value);
}

function gzipCall($url)
{

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
    curl_setopt($ch, CURLOPT_ENCODING, ''); // Apparently enables any encoding and auto decodes
    //curl_setopt($ch, CURLOPT_HTTPHEADER, array("Accept-encoding: gzip"));
    curl_setopt($ch, CURLOPT_HEADER, 0);
    $xmlresponse = curl_exec($ch);

    if (curl_errno($ch) != 0)
    {
        throw new Exception('Curl error: ' . curl_error($ch));
    }

    curl_close($ch);

//    echo $xmlresponse;

  return $xmlresponse;
}

function getRequestToken()
{
    setFiled(TOKEN, '');
    setFiled(SECRET, '');
  $params = Array();
  $params['oauth_callback'] = 'http://flickr.com';
  $rsp = flickrCall($params, 'oauth/request_token');
  parse_str($rsp, $q);
  if (!array_key_exists('oauth_callback_confirmed', $q) || $q['oauth_callback_confirmed'] != true)
    exit("Flickr didn't return oauth_callback_confirmed true: $rsp");
  $url = 'http://www.flickr.com/services/oauth/authorize?perms=read&oauth_token='.$q['oauth_token'];
  setFiled(SECRET, $q['oauth_token_secret']);

    echo PHP_EOL."Please visit the URL below using your browser. Once you have confirmed access copy " .
        "the new URL from your browser, paste it below and press enter:".PHP_EOL.PHP_EOL;

  echo $url.PHP_EOL.PHP_EOL;

  $handle = fopen ("php://stdin","r");
  $line = trim(fgets($handle));

  if (strpos($line, '?') !== false)
      $line = explode('?', $line)[1];

parse_str($line, $params);

//    print_r($params);

    if (!(isset($params['oauth_token']) && isset($params['oauth_verifier'])))
        exit("Return URL did not contain both oauth_token and oauth_verifier".PHP_EOL);

    $rsp = flickrCall($params, 'oauth/access_token');
//    echo $rsp;
    parse_str($rsp, $q);
//    print_r($q);
    if (isset($q['oauth_problem']))
        exit("oauth_problem: ".$q['oauth_problem'].PHP_EOL);
    if (!(isset($q['oauth_token']) && isset($q['oauth_token_secret'])))
        exit("Flickr response did not contain both oauth_token and oauth_token_secret".PHP_EOL);
    setFiled(TOKEN, $q['oauth_token']);
    setFiled(SECRET, $q['oauth_token_secret']);

    echo PHP_EOL;
    if (testFlickr())
        echo "Success";
    else
        echo "Login test failed!";
    echo PHP_EOL;

}

function exivCmd($cmd, $file)
{
    $cmd = str_replace('"', '\"', $cmd);
//    echo $cmd.PHP_EOL;
    exiv('-M"'.$cmd.'"', $file);
}

function exiv($params, $file)
{
    exec("exiv2 -q $params $file", $output, $return);
    return $output;
}

function latLon($deg, $tag, $pos, $neg, $file)
{
    if ($deg < 0)
        $sign = $neg;
    else
        $sign = $pos;
    list($int, $frac) = explode('.', trim(abs($deg), '0'));
    $ration = ltrim($int . $frac, '0') . '/1' . str_repeat('0', strlen($frac));
    exivCmd("set $tag $ration", $file);
    exivCmd("set ".$tag."Ref $sign", $file);
}

function getTags($tag, $filename)
{
    return exiv("-g $tag -P v", $filename);
}

function hasLatLon($filename)
{
    return count(getTags(LATITUDE_TAG.' -g '.LONGITUDE_TAG, $filename)) > 0;
}

function sanitize($string) // , $force_lowercase = false, $anal = false) 
{
    $strip = array("~", "`", "!", "@", "#", "$", "%", "^", "&", "*", "(", ")", "_", "=", "+", "[", "{", "]",
                   "}", "\\", "|", ";", ":", "\"", "'", "&#8216;", "&#8217;", "&#8220;", "&#8221;", "&#8211;", "&#8212;",
                   "â€”", "â€“", ",", "<", ".", ">", "/", "?");
    $clean = trim(str_replace($strip, "-", strip_tags($string)));
    $clean = preg_replace('/\s+/', "-", $clean);
/*    $clean = ($anal) ? preg_replace("/[^a-zA-Z0-9]/", "", $clean) : $clean ;
    return ($force_lowercase) ?
        (function_exists('mb_strtolower')) ?
            mb_strtolower($clean, 'UTF-8') :
            strtolower($clean) : */
    return    $clean;
}

switch(testFlickr() + 2 * (count($_SERVER['argv']) - 1))
{
    case 0:
        getRequestToken();
        exit;
        break;
    case 1:
        exit('Already logged in. If you want to clear your session delete the token and secret files. '.
            'To run the backup include a directory as a parameter.'.PHP_EOL);
        break;
    case 2:
        throw new Exception("Can't run, need interactive log in.");
        break;
    case 3:
}

// print_r($_SERVER['argv']);

$params = Array();
$params['user_id'] = 'me';
$params['sort'] = 'date-posted-asc';
$params['method'] = 'flickr.photos.search';
$params['per_page'] = 9;
$params['page'] = 1;
$params['extras'] = 'description,original_format,geo,tags,machine_tags,date_taken';

$rsp = flickrCall($params);
$rsp = unserialize($rsp);

print_r($rsp);

$basedir = $_SERVER['argv'][1];

foreach($rsp['photos']['photo'] as $p)
{
    echo 'Processing photo '.$p['id'].PHP_EOL;
    $url = sprintf("http://farm%s.staticflickr.com/%s/%s_%s_o.%s", 
        $p['farm'], $p['server'], $p['id'], $p['originalsecret'], $p['originalformat']);
    $dir = $basedir.DIRECTORY_SEPARATOR.date(DIRECTORY_FORMAT, strtotime($p['datetaken']));
    if (!is_dir($dir) && !mkdir($dir, 0755, true))
        throw new Exception("Could not create directorry $dir");
    $filename = $dir.DIRECTORY_SEPARATOR.getFileName($p);
    echo "Saving to $filename".PHP_EOL;
    copy($url, $filename);
    if ($p['originalformat'] == 'gif')
    {
        echo "Can't tag gifs.".PHP_EOL;
        continue;
    }
    $existingtags = getTags(TAG_TAG, $filename);
    foreach(explode(' ', $p['tags']) as $tag)
    {
        if (!in_array($tag, $existingtags))
            exivCmd("add ".TAG_TAG." String $tag", $filename);
    }
//    echo gettype($p['latitude']);
    if ((($p['latitude'] !== 0) || ($p['longitude'] !== 0)) && !hasLatLon($filename)) 
    {
        latLon($p['latitude'], LATITUDE_TAG, 'N', 'S', $filename);
        latLon($p['longitude'], LONGITUDE_TAG, 'E', 'W', $filename);
    }
    exivCmd("set ".TITLE_TAG." ".$p['title'], $filename);
    exivCmd("set ".DESCRIPTION_TAG." ".$p['description']['_content'], $filename);

}

?>
