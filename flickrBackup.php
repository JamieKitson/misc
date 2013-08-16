<?php

/*

    This script attempts to download your Flickr photos and insert meta data such as 
    title, description, tags and comments into the jpegs.

*/

include('flickrapisecret.php');

// Backup directory, default is ~/flickrBackup/ Include trailing slash
define("BASE_DIR", $_SERVER['HOME'].DIRECTORY_SEPARATOR.'flickrBackup'.DIRECTORY_SEPARATOR);

// By default files will be named DAY-HOUR-MINUTE-TITLE-ID according to when photo was taken
// It's a good idea to include ID to guarantee uniqueness. See also getFileName()
define("FILENAME_FORMAT", 'd-H-i-\T\I\T\L\E-\I\D');
// By default files will be divided into directories by YEAR-MONTH according to when photo was taken
define("DIRECTORY_FORMAT", "Y-m");

// Maximum number of photos to backup per run. 0 for all. Useful for limitting bandwidth/run time
define("MAX_BATCH", 0);

// Maximum upload date of backed up photos. This is useful for not backing up photos that might be 
// updated at a later date. Example useage one month in the past: strtotime("-1 month")
define("MAX_DATE", strtotime("-1 month"));

// exiv binary
define("EXIV", "/usr/bin/exiv2");

// EXIF Tags. You probably don't want to change these
define("TITLE_TAG", "Exif.Image.ImageDescription");
define("DESCRIPTION_TAG", "Exif.Photo.UserComment");
define("TAG_TAG", "Iptc.Application2.Keywords");
define("LATITUDE_TAG", "Exif.GPSInfo.GPSLatitude");
define("LONGITUDE_TAG", "Exif.GPSInfo.GPSLongitude");

// Cache filenames. You probably don't need to change these
define("TOKEN_FILE", BASE_DIR."token.txt");
define("SECRET_FILE", BASE_DIR."secret.txt");
define("SEEN_FILE", BASE_DIR."seen.txt");
define("LAST_SEEN_PHOTO_FILE", BASE_DIR."lastseen.txt");
define("LAST_SEEN_COMMENT_FILE", BASE_DIR."lastcomment.txt");

define("DEFAULT_PER_PAGE", 100);
define("LOG_LEVEL", 3);

if (count($_SERVER['argv']) > 2)
    exit('Useage: php flickrBackup.php backup/directory [run]'.PHP_EOL);

if (!is_dir(BASE_DIR) && !mkdir(BASE_DIR, 0755, true))
    throw new Exception("Could not create directorry ".BASE_DIR);

$run = (count($_SERVER['argv']) == 2) && ($_SERVER['argv'][1] == 'run');

switch(testFlickr() + 2 * $run)
{
    case 0:
        authenticate();
        exit;
        break;
    case 1:
        exit('Already logged in. If you want to clear your session delete the token and secret files. '.
            'To run the backup include "run" as parameter.'.PHP_EOL);
        break;
    case 2:
        throw new Exception("Can't run, need interactive log in.");
        break;
    case 3:
//        getNewComments();
        runBackup();
        break;
}

function getFileName($photo)
{
    $filename = date(FILENAME_FORMAT, strtotime($photo['datetaken']));
    $filename = str_replace("TITLE", $photo['title'], $filename);
    $filename = str_replace("ID", $photo['id'], $filename);
    $filename = sanitize($filename).'.'.$photo['originalformat'];
    return $filename;
}

function flickrCall($params, $uri = "rest")
{
    $params['oauth_consumer_key'] = 'b51ae39f6b166d53ea1c4bd4751de3e0';
    $params['oauth_nonce'] = rand(0, 99999999);
    $params['oauth_timestamp'] = date('U');
    $params['oauth_signature_method'] = 'HMAC-SHA1';
    $params['oauth_version'] = '1.0';
    $params['format'] = 'php_serial';
    $token = getFiled(TOKEN_FILE);
    if ($token != '')
      $params['oauth_token'] = $token;

    $encoded_params = array();
    foreach ($params as $k => $v)
    {
            $encoded_params[] = urlencode($k).'='.urlencode($v);
    }

    sort($encoded_params);
    $p = implode('&', $encoded_params);

    $url = "http://api.flickr.com/services/$uri";

    $base = "GET&".urlencode($url)."&".urlencode($p);

    $tokensecret = getFiled(SECRET_FILE);

    $sig = urlencode(base64_encode(hash_hmac('sha1', $base, $GLOBALS['apisecret']."&$tokensecret", true)));

    $url .= "?$p&oauth_signature=$sig";

//        echo $url."\n";

    $rsp = gzipCall($url);

//        echo $rsp."\n"; 

    if ($uri == 'rest')
        return unserialize($rsp);
    elseif ($uri = 'oauth/request_token')
    {
      parse_str($rsp, $q);
      return $q;
    }
    else
        return $rsp;
      
}

function testFlickr()
{
  if ((getFiled(TOKEN_FILE) != '') && (getFiled(SECRET_FILE) != ''))
  {
    $p = flickrCall(Array('method' => 'flickr.test.login'));
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
    {
        $GLOBALS[$name] = trim(file_get_contents($name));
        return $GLOBALS[$name];
    }
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

    $c = 0;
    do
    {
        if ($c == 3)
            throw new Exception('Curl error: ' . curl_error($ch));
        if ($c > 0)
            mylog("Error making Flickr call: " . curl_error($ch).PHP_EOL."Retry $c", 2);
        $xmlresponse = curl_exec($ch);
        $c++;
    } while (curl_errno($ch) != 0);
/*
    if (curl_errno($ch) != 0)
    {
        throw new Exception('Curl error: ' . curl_error($ch));
    }
*/
    curl_close($ch);

//    echo $xmlresponse;

  return $xmlresponse;
}

function authenticate()
{
    setFiled(TOKEN_FILE, '');
    setFiled(SECRET_FILE, '');
    $params = Array();
    $params['oauth_callback'] = 'http://flickr.com';
    $q = flickrCall($params, 'oauth/request_token');
    if (!array_key_exists('oauth_callback_confirmed', $q) || $q['oauth_callback_confirmed'] != true)
        exit("Flickr didn't return oauth_callback_confirmed true: $rsp");
    $url = 'http://www.flickr.com/services/oauth/authorize?perms=read&oauth_token='.$q['oauth_token'];
    setFiled(SECRET_FILE, $q['oauth_token_secret']);

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

    $q = flickrCall($params, 'oauth/access_token');
//    print_r($q);
    if (isset($q['oauth_problem']))
        exit("oauth_problem: ".$q['oauth_problem'].PHP_EOL);
    if (!(isset($q['oauth_token']) && isset($q['oauth_token_secret'])))
        exit("Flickr response did not contain both oauth_token and oauth_token_secret".PHP_EOL);
    setFiled(TOKEN_FILE, $q['oauth_token']);
    setFiled(SECRET_FILE, $q['oauth_token_secret']);

    echo PHP_EOL;
    if (testFlickr())
        echo "Success";
    else
        echo "Login test failed!";
    echo PHP_EOL;

}

// Thanks http://darklaunch.com/2013/03/21/php-exec-stderr-stdout-return-code
function pipe_exec($cmd, $input='') 
{
    $proc = proc_open($cmd, array(array('pipe', 'r'), array('pipe', 'w'), array('pipe', 'w')), $pipes);
    fwrite($pipes[0], $input);
    fclose($pipes[0]);
 
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
 
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
 
    $return = (int)proc_close($proc);
 
    return array(
        'stdout' => $stdout,
        'stderr' => $stderr,
        'return' => $return,
    );
}

function exivCmd($cmd, $file)
{
    $cmd = str_replace('"', '\"', $cmd);
//    echo $cmd.PHP_EOL;
    exiv('-M"'.$cmd.'"', $file);
//    return ' -M"'.$cmd.'" ';
}

function exiv($params, $file)
{
    $res = pipe_exec(EXIV." -q $params $file");
//    if ($res['return'] != 0)
    if ($res['stderr'] != '')
    {
//        mylog($res['return']);
        mylog("exiv error with command '-q $params $file', Error: ".$res['stderr']);
    }
    return explode("\n", $res['stdout']);
}

function latLon($deg, $tag, $pos, $neg, $file)
{
    if ($deg < 0)
        $sign = $neg;
    else
        $sign = $pos;
    list($int, $frac) = explode('.', trim(abs($deg), '0'));
    $ration = ltrim($int . $frac, '0') . '/1' . str_repeat('0', strlen($frac));
    $res = exivCmd("set $tag $ration", $file);
    $res .= exivCmd("set ".$tag."Ref $sign", $file);
    return $res;
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
    $strip = array("~", "`", "!", "@", "#", "$", "%", "^", "&", "*", "(", ")", "_", "=", "+", "[", "{", "]", " ",
                   "}", "\\", "|", ";", ":", "\"", "'", "&#8216;", "&#8217;", "&#8220;", "&#8221;", "&#8211;", "&#8212;",
                   "â€”", "â€“", ",", "<", ".", ">", "/", "?");
    $clean = trim(str_replace($strip, "-", strip_tags($string)));
    $clean = collapseChar($clean, '-', '-');
/*    $clean = ($anal) ? preg_replace("/[^a-zA-Z0-9]/", "", $clean) : $clean ;
    return ($force_lowercase) ?
        (function_exists('mb_strtolower')) ?
            mb_strtolower($clean, 'UTF-8') :
            strtolower($clean) : */
    return    $clean;
}

function file_contents_if_exists($filename)
{
    $filename = $filename;
    if (file_exists($filename) !== false)
        return file_get_contents($filename);
    return '';
}

function collapseChar($string, $char, $replacement)
{
    return preg_replace('/'.$char.'+/', $replacement, $string);
}

function runBackup()
{
    $params = Array();
    $params['user_id'] = 'me';
    $params['sort'] = 'date-posted-asc';
    $params['method'] = 'flickr.photos.search';
    $params['extras'] = 'description,geo,tags,machine_tags,date_taken,date_upload,url_o,original_format';
    $params['min_upload_date'] = file_contents_if_exists(LAST_SEEN_PHOTO_FILE) - 1000;
    $params['max_upload_date'] = MAX_DATE;
    $params['per_page'] = DEFAULT_PER_PAGE;

    $params['page'] = 1;
    $count = 0;
    if (file_exists(SEEN_FILE))
        $seen = file(SEEN_FILE, FILE_IGNORE_NEW_LINES);
    else
        $seen = array();

    do
    {
        if (MAX_BATCH > 0)
            $params['per_page'] = min(DEFAULT_PER_PAGE, MAX_BATCH - $count);

        mylog("Getting page ".$params['page'], 2);
        mylog("Params: ".print_r($params, true), 3);

        $rsp = flickrCall($params);

        mylog("Got ".count($rsp['photos']['photo'])." results", 2);

        // print_r($rsp);

        foreach($rsp['photos']['photo'] as $p)
        {
            mylog('Copying photo '.$p['id'].' '.$p['title']);
            if (in_array(trim($p['id']), $seen) === true)
            {
                mylog('Already seen photo '.$p['id'].', skipping.');
                continue;
            }
            $url = $p['url_o'];
            $dir = BASE_DIR.date(DIRECTORY_FORMAT, strtotime($p['datetaken']));
            if (!is_dir($dir) && !mkdir($dir, 0755, true))
                throw new Exception("Could not create directorry $dir");
            $filename = $dir.DIRECTORY_SEPARATOR.getFileName($p);
            $c = 1;
            while (!copy($url, $filename))
            {
                mylog("Copy failed: ".$error['message'].PHP_EOL."Retry $c", 2);
                if ($c == 3)
                {
                    $error = error_get_last();
                    throw new Exception('Copy error: ' . $error['message']);
                }
                $c++;
            }
            mylog("Saved to $filename");
            $count++;
            if ($p['originalformat'] == 'gif')
            {
                mylog("Can't tag gifs.");
            }
            else
            {
                $existingtags = getTags(TAG_TAG, $filename);
                $cmd = '';
                $newtags = explode(' ', $p['tags']);
                // Machine tags
                foreach(array("ispublic", "isfriend", "isfamily") as $mtag)
                {
                    $newtags[] = "flickr:$mtag=".$p[$mtag];
                }
                foreach($newtags as $tag)
                {
                    if (!in_array($tag, $existingtags))
                        $cmd .= exivCmd("add ".TAG_TAG." String $tag", $filename);
                }
            //    echo gettype($p['latitude']);
                if ((($p['latitude'] !== 0) || ($p['longitude'] !== 0)) && !hasLatLon($filename)) 
                {
                    $cmd .= latLon($p['latitude'], LATITUDE_TAG, 'N', 'S', $filename);
                    $cmd .= latLon($p['longitude'], LONGITUDE_TAG, 'E', 'W', $filename);
                }
                $cmd .= exivCmd("set ".TITLE_TAG." ".$p['title'], $filename);
                $description = $p['description']['_content'];
                $comments = flickrCall(array('method' => 'flickr.photos.comments.getList', 'photo_id' => $p['id']));

                if (isset($comments['comments']['comment']))
                {
                    foreach($comments['comments']['comment'] as $comment)
                    {
                        $description .= PHP_EOL.$comment['authorname'].': '.collapseChar($comment['_content'], '\s', ' ');
                    }
                }

                $cmd .= exivCmd("set ".DESCRIPTION_TAG." ".$description, $filename);
                // exiv($cmd, $filename);
            }
            $seen[] = $p['id'];
            file_put_contents(SEEN_FILE, $p['id'].PHP_EOL, FILE_APPEND);
            // Account for FLickr weirdness http://www.flickr.com/groups/api/discuss/72157635089183188/#comment72157635083987333
            file_put_contents(LAST_SEEN_PHOTO_FILE, $p['dateupload'] - 1000);
        }

        $params['page']++;

    } while ( $params['page'] <= $rsp['photos']['pages'] && ( MAX_BATCH == 0 || $count < MAX_BATCH ) ) ;
}

function mylog($msg, $level = 0)
{
    if (LOG_LEVEL >= $level)
        echo $msg.PHP_EOL;
}

function getNewComments()
{
    $last = file_contents_if_exists(LAST_SEEN_COMMENT_FILE);
    if ($last == '')
        exit;
    $params['timeframe'] = ceil((time() - $last) / 3600) . 'h';
    $params['method'] = 'flickr.activity.userPhotos';
    $params['perpage'] = 50;
    $activity = flickrCall($params);
    print_r($activity);
    print_r($params);
    foreach($activity['items']['item'] as $act)
    {
        echo $act['event']['type'];
    }
}

?>
