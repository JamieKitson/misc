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
