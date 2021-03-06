<?php

/*
    This script attempts to download your Flickr photos and insert meta data such as 
    title, description, tags and comments into the jpegs as EXIF tags.

    To use this script you will need to get your own API Key and add it and the secret
    to the settings below. I have my API secret in flickrapisecret.php so as not to commit
    it.

    http://www.flickr.com/services/apps/create/apply/

    Currently (Aug 2013) the Flickr API doesn't function properly when the min_upload_date
    and/or max_upload_date parameters are used, so rather than keeping a record of the
    latest upload date seen, I am keeping a record of the last page seen. As long as
    the per_page parameter is not changed and you don't change the upload date of photos
    or delete any old photos this should remain constant.

    http://www.flickr.com/help/forum/en-us/72157635089298188/#reply72157635100917894
    http://tech.groups.yahoo.com/group/yws-flickr/message/8311

*/

// You will need to change these two settings
define("API_SECRET", file_contents_if_exists(dirname(__FILE__).DIRECTORY_SEPARATOR."flickrapisecret.php"));
define("API_KEY", "b51ae39f6b166d53ea1c4bd4751de3e0");

// Backup directory, default is ~/flickrBackup/ (include trailing slash)
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
define("LAST_SEEN_PAGE_FILE", BASE_DIR."lastseenpage.txt");
define("LAST_SEEN_PHOTO_FILE", BASE_DIR."lastseendate.txt");
define("LAST_SEEN_COMMENT_FILE", BASE_DIR."lastcomment.txt");

define("LOG_LEVEL", 2);

$CMDS = Array('run', 'auth', 'gps');

if ((count($_SERVER['argv']) != 2) || (!in_array($_SERVER['argv'][1], $CMDS)))
    exit('Useage: php flickrBackup.php backup/directory ['.implode('|', $CMDS).']'.PHP_EOL);

if (!is_dir(BASE_DIR) && !mkdir(BASE_DIR, 0755, true))
    throw new Exception("Could not create directorry ".BASE_DIR);

$run = (count($_SERVER['argv']) == 2) && ($_SERVER['argv'][1] == 'run');

$loggedIn = testFlickr();
$arg = $_SERVER['argv'][1];

// auth
if ($arg == $CMDS[1])
{
    if ($loggedIn)
        exit('Already logged in. If you want to clear your session delete the token and secret files. '.
            'To run the backup include "run" as parameter.'.PHP_EOL);
    authenticate();
    exit;
}

if (!$loggedIn)
{
    throw new Exception("Can't run, need interactive log in.");
}

// run
if ($arg == $CMDS[0])
{
//        getNewComments();
    runBackup();
}

// gps
if ($arg == $CMDS[2])
{
    updateGPS();
}

function getFileName($photo)
{
    $filename = date(FILENAME_FORMAT, strtotime($photo['datetaken']));
    $filename = str_replace("ID", $photo['id'], $filename);
    $filename = str_replace("TITLE", $photo['title'], $filename);
    $filename = sanitize($filename).'.'.$photo['originalformat'];
    return $filename;
}

function flickrCall($params, $uri = "rest")
{
    $params['oauth_consumer_key'] = API_KEY;
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

    $url = "https://api.flickr.com/services/$uri";

    $base = "GET&".urlencode($url)."&".urlencode($p);

    $tokensecret = getFiled(SECRET_FILE);

    $sig = urlencode(base64_encode(hash_hmac('sha1', $base, API_SECRET."&$tokensecret", true)));

    $url .= "?$p&oauth_signature=$sig";

//        echo $url."\n";

    $rsp = gzipCall($url);

//        echo $rsp."\n"; 

    if ($uri == 'rest')
    {
        return unserialize($rsp);
    }
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
        exit("Flickr didn't return oauth_callback_confirmed true: ".print_r($q, true).PHP_EOL);
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

function exivCmd($cmd, $tag, $type, $value, $file)
{
    // Quoting exiv values is a bit weird, but this works.
    $value = str_replace('"', '\"', "\"$value\"");
    $cli = "-M\"$cmd $tag $type $value\"";
//    echo $cli.PHP_EOL;
    exiv($cli, $file);
//    return " $cli ";
}

function exiv($params, $file)
{
    $cmd = EXIV." -q $params $file";
    mylog($cmd, 4);
    $res = pipe_exec($cmd);
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
    $res = exivCmd("set", $tag, "Rational", $ration, $file);
    $res .= exivCmd("set", $tag."Ref", "Ascii", $sign, $file);
    return $res;
}

function getTags($tag, $filename)
{
    return exiv("-g $tag -P v", $filename);
}

function hasLatLon($filename)
{
    $res = getTags(LATITUDE_TAG.' -g '.LONGITUDE_TAG, $filename);
    return (count($res) > 0) && (trim($res[0]) != '');
}

function sanitize($string) // , $force_lowercase = false, $anal = false) 
{
    $strip = array("~", "`", "!", "@", "#", "$", "%", "^", "&", "*", "(", ")", "_", "=", "+", "[", "{", "]", " ",
                   "}", "\\", "|", ";", ":", "\"", "'", "&#8216;", "&#8217;", "&#8220;", "&#8221;", "&#8211;", "&#8212;",
                   "â€”", "â€“", ",", "<", ".", ">", "/", "?");
    $clean = trim(str_replace($strip, "-", strip_tags($string)));
    $clean = collapseChar($clean, '-', '-');
    return    $clean;
}

function file_contents_if_exists($filename, $default = '')
{
    $filename = $filename;
    if (file_exists($filename) != false)
        return trim(file_get_contents($filename));
    return $default;
}

function collapseChar($string, $char, $replacement)
{
    return preg_replace('/'.$char.'+/', $replacement, $string);
}

function runBackup()
{
    $params = Array();
    $params['user_id'] = 'me';
    $params['method'] = 'flickr.photos.search';
    $params['extras'] = 'description,geo,tags,machine_tags,date_taken,date_upload,url_o,original_format';

// See note at top regarding min_upload_date and max_upload_date
//    $params['min_upload_date'] = file_contents_if_exists(LAST_SEEN_PHOTO_FILE);
//    $params['max_upload_date'] = MAX_DATE;

    // WARNING: If you change the per_page/sort parameters then the saved page parameter won't make sense any more
    $params['per_page'] = 100;
    $params['sort'] = 'date-posted-asc';

    $params['page'] = file_contents_if_exists(LAST_SEEN_PAGE_FILE, 1);

    $count = 0;
    if (file_exists(SEEN_FILE))
        $seen = file(SEEN_FILE, FILE_IGNORE_NEW_LINES);
    else
        $seen = array();

    do
    {
        file_put_contents(LAST_SEEN_PAGE_FILE, $params['page']);
        
        mylog("Getting page ".$params['page'], 2);
        mylog("Params: ".print_r($params, true), 3);

        $rsp = flickrCall($params);

        mylog("Got ".count($rsp['photos']['photo'])." results", 2);

        // print_r($rsp);

        foreach($rsp['photos']['photo'] as $p)
        {
            mylog('Processing photo '.$p['id'].' '.$p['title']);
            if ($p['dateupload'] > MAX_DATE)
            {
                mylog("Reached MAX_DATE, ".$p['dateupload']." > ".MAX_DATE);
                exit;
            }
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
            mylog('Copying photo '.$p['id'].' '.$p['title'], 4);
            while (!copy($url, $filename))
            {
                $error = error_get_last();
                if ($c == 3)
                {
                    throw new Exception('Copy error: ' . $error['message']);
                }
                mylog("Copy failed: ".$error['message'].PHP_EOL."Retry $c", 2);
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
                        $cmd .= exivCmd("add", TAG_TAG, "String", $tag, $filename);
                }
            //    echo gettype($p['latitude']);
                if ((($p['latitude'] !== 0) || ($p['longitude'] !== 0)) && !hasLatLon($filename)) 
                {
                    $cmd .= latLon($p['latitude'], LATITUDE_TAG, 'N', 'S', $filename);
                    $cmd .= latLon($p['longitude'], LONGITUDE_TAG, 'E', 'W', $filename);
                }
                $cmd .= exivCmd("set", TITLE_TAG, "Ascii", $p['title'], $filename);
                $description = $p['description']['_content'];
                $comments = flickrCall(array('method' => 'flickr.photos.comments.getList', 'photo_id' => $p['id']));

                if (isset($comments['comments']['comment']))
                {
                    foreach($comments['comments']['comment'] as $comment)
                    {
                        $description .= PHP_EOL.$comment['authorname'].': '.collapseChar($comment['_content'], '\s', ' ');
                    }
                }

                if (trim($description) != '')
                    $cmd .= exivCmd("set", DESCRIPTION_TAG, "Ascii", $description, $filename);
                // exiv($cmd, $filename);
            }
            $seen[] = $p['id'];
            file_put_contents(SEEN_FILE, $p['id'].PHP_EOL, FILE_APPEND);
            // Account for FLickr weirdness http://www.flickr.com/groups/api/discuss/72157635089183188/#comment72157635083987333
            file_put_contents(LAST_SEEN_PHOTO_FILE, $p['dateupload']); //  - 1000);
            if ((MAX_BATCH > 0) && ($count < MAX_BATCH))
            {
                mylog("MAX_BATCH of ".MAX_BATCH." reached.");
                exit;
            }
        }

        $params['page'] = $params['page'] + 1;

    } while ( $params['page'] <= $rsp['photos']['pages'] ) ;
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

function updateGPS()
{
    $seenfile = 'seengps.txt';
    if (file_exists($seenfile))
        $seen = file($seenfile, FILE_IGNORE_NEW_LINES);
    else
        $seen = array();

    $it = new RecursiveDirectoryIterator(BASE_DIR);
    $display = Array ( 'png', 'jpg' );
    foreach(new RecursiveIteratorIterator($it) as $filename)
    {
        $parts = explode('.', $filename);
        if (in_array(strtolower(array_pop($parts)), $display))
        {
            $id = array_pop(explode('-', $parts[0]));
            if (in_array($id, $seen) === true)
            {
                mylog("Already seen photo $id, skipping.");
                continue;
            }
            if (hasLatLon($filename))
            {
                mylog("$filename has Lat Lon", 3);
            }
            else
            {
                mylog("$filename doesn't have Lat Lon", 2);
                $p = flickrCall(Array('method' => 'flickr.photos.geo.getLocation', 'photo_id' => $id));
                // print_r($p);
                if ($p['stat'] == 'ok')
                {
                    $lat = $p['photo']['location']['latitude'];
                    $lon = $p['photo']['location']['longitude'];
                    mylog("setting lat lon $lat $lon");
                    latLon($lat, LATITUDE_TAG, 'N', 'S', $filename);
                    latLon($lon, LONGITUDE_TAG, 'E', 'W', $filename);
                }
                else
                {
                    mylog("no lat lon from flickr");
                }
            }
            $seen[] = $id;
            file_put_contents($seenfile, $id.PHP_EOL, FILE_APPEND);
        }
    }

}

?>
