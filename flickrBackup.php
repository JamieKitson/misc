<?php

include_once('flickrapisecret.php');

function flickrCall($params, $uri = "rest")
{

        $params['oauth_consumer_key'] = 'b51ae39f6b166d53ea1c4bd4751de3e0';
        $params['oauth_nonce'] = rand(0, 99999999);
        $params['oauth_timestamp'] = date('U');
        $params['oauth_signature_method'] = 'HMAC-SHA1';
        $params['oauth_version'] = '1.0';
        $params['format'] = 'php_serial';
        $token = getToken();
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

        $tokensecret = getSecret();

        $sig = urlencode(base64_encode(hash_hmac('sha1', $base, $GLOBALS['apisecret']."&$tokensecret", true)));

        $url .= "?$p&oauth_signature=$sig";

//        echo $url."\n";

        $rsp = gzipCall($url);

//        echo $rsp."\n"; 

        return $rsp;

}

function testFlickr()
{
  if ((getToken() != '') && (getSecret() != ''))
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

function getToken()
{
    return getFiled('token');
}

function setToken($token)
{
    setFiled('token', $token);
}

function getSecret()
{
    return getFiled('secret');
}

function setSecret($secret)
{
    setFiled('secret', $secret);
}

function flickrAuthLink($class)
{
  return "<a class=\"$class\" href=\"getFlickr.php\">Authorise Flickr</a>";
}

function flickrCallWithRetry($params)
{
  $tries = 0;
  while($tries < 3)
  {
    $rsp = flickrCall($params);
    $fc = unserialize($rsp);

    if ($fc['stat'] == 'ok')
      return $fc;

    $tries++;
  }

  if (is_array($fc) && array_key_exists('message', $fc)) {
    $msg = $fc['message'];
  } else {
    $msg = $rsp;
  }
  errorExit('Flickr call failed with: '.$msg);
}

function pagePhotos($total, $params, $statFile)
{
  $params['page'] = 1;
  $allPhotos = array();
  do {
    $params['per_page'] = $total - count($allPhotos);
    $fc = flickrCallWithRetry($params);
    $allPhotos = array_merge($allPhotos, $fc['photos']['photo']);
    writeProgress("Getting photos from Flickr.", (100 * count($allPhotos) / $total), $statFile);
    $params['page'] += 1;
  } while((count($allPhotos) < $total) && ($params['page'] <= $fc['photos']['pages']));
  return $allPhotos;
}

function getPhotos($count, $maxDate, $minDate, $tags, $statFile)
{
  $count = $count ?: DEF_COUNT;
  writeProgress("Getting photos from Flickr.", 0, $statFile);

  if ($maxDate > "")
    $maxDate = date('Y-m-d', strtotime($maxDate) + 24 * 60 * 60); // add a day to make it inclusive, change back to MySQL datestamp as getWithoutGeoData doesn't like Unix time stamps
  $params = array(
    'method' => 'flickr.photos.getWithoutGeoData',
    'sort' => 'date-taken-desc',
    'extras' => 'date_taken,geo',
    'max_taken_date' => $maxDate,
    'min_taken_date' => $minDate
  );

  if ($tags > "")
  {
    $params['method'] = 'flickr.photos.search';
    $params['user_id'] = 'me';
    $params['has_geo'] = 0;
    $params['tags'] = $tags;
    $params['content_type'] = 7;
  }

  $photos = pagePhotos($count, $params, $statFile);
  addUTime($photos);
  return $photos;
}

function addUTime(&$photos)
{
  // bail if we've got no photos
  if (count($photos) == 0)
  {
    errorExit('No Flickr photos found.');
  }

  // do expensive str date processing just once
  foreach($photos as &$p)
  {
    $p[UTIME] = strtotime($p['datetaken']);
  }

  // sort photos by date taken in case flickr has fucked up or the user has overridden sort
  sort_array_by_utime($photos);

  return $photos;

}

function flickrAddTags($photoId, $tags)
{
  return flickrCall(array(
        'method' => 'flickr.photos.addTags',
        'photo_id' => $photoId,
        'tags' => $tags));
}

function flickrSetGeo($photoId, $lat, $long)
{
  return flickrCall(array(
        'method' => 'flickr.photos.geo.setLocation',
        'photo_id' => $photoId,
        'lat' => $lat,
        'lon' => $long));
}

function getSet($setId)
{
  $params = array(
      'method' => 'flickr.photosets.getPhotos',
      'extras' => 'date_taken,geo',
      'photoset_id' => $setId
    );
  $fc = flickrCallWithRetry($params);
  $photos = $fc['photoset']['photo'];
  addUTime($photos);
  return $photos;
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

/*

  $opts = array(
      'http'=>array(
      'method'=>"GET",
      'header'=>"Accept-Encoding: gzip\r\n" .
                "User-Agent: my program (gzip)\r\n"
                                )
    );

  $context = stream_context_create($opts);

  $xmlresponse = file_get_contents($url, false, $context);

  if ($xmlresponse !== false)
  {
        $xmlresponse = gzdecode($xmlresponse);
  }

*/

//    echo $xmlresponse;

  return $xmlresponse;
}

function getRequestToken()
{
    setToken('');
    setSecret('');
  $params = Array();
  $params['oauth_callback'] = 'http://flickr.com';
  $rsp = flickrCall($params, 'oauth/request_token');
  parse_str($rsp, $q);
  if (!array_key_exists('oauth_callback_confirmed', $q) || $q['oauth_callback_confirmed'] != true)
    exit("Flickr didn't return oauth_callback_confirmed true: $rsp");
  $url = 'http://www.flickr.com/services/oauth/authorize?perms=read&oauth_token='.$q['oauth_token'];
  setSecret($q['oauth_token_secret']);

    echo PHP_EOL."Please visit the URL below using your browser. Once you have confirmed access copy " .
        "the new URL from your browser, paste it below and press enter:".PHP_EOL.PHP_EOL;

  echo $url.PHP_EOL.PHP_EOL;

  $handle = fopen ("php://stdin","r");
  $line = trim(fgets($handle));

  if (strpos($line, '?') !== false)
      $line = explode('?', $line)[1];

parse_str($line, $params);

/*

  $params = Array();
  foreach (explode('&', $line) as $chunk) 
  {
        $param = explode("=", $chunk);
        if (in_array($param[0], array('oauth_token', 'oauth_verifier')))
            $params[$param[0]] = $param[1];
    }
*/
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
    setToken($q['oauth_token']);
    setSecret($q['oauth_token_secret']);

    if (!testFlickr())
        throw new Exception('Login test failed.');

}

// if (!testFlickr())
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

print_r($_SERVER['argv']);

$params = Array();
$params['user_id'] = 'me';
$params['sort'] = 'date-posted-asc';
$params['method'] = 'flickr.photos.search';
$params['per_page'] = 2;
$params['page'] = 1;
$params['extras'] = 'description,original_format,geo,tags,machine_tags';

$rsp = flickrCall($params);
$rsp = unserialize($rsp);
print_r($rsp);

?>
