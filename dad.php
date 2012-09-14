<?

/*
   A script to poll the SOS.org.uk website and email posts by my dad
*/

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

$doc = new DOMDocument();

// $doc->loadHTMLFile("http://www.sos.org.uk/index.php?option=com_jobline4&Itemid=10");
$doc->loadHTMLFile("http://www.sos.org.uk/index.php?option=com_jobline4&task=results&Itemid=10&search=kitson&limit=5");

$xpath = new DOMXPath($doc);

$query = '//html/body/div/div/table/tr/td[2]/div/div[2]/div[3]/div/table/tr[4]/td/table/tr[contains(@class,"sectiontableentry")]';

$entries = $xpath->query($query);

$mail = '';

for ($i = 0; $i < $entries->length; $i += 2)
{

	$xmlHeading = $entries->item($i);
	$strBody = $entries->item($i + 1)->nodeValue;

	$date = $xmlHeading->firstChild->nodeValue;

	if (($date != '') && (strtotime($date) > time() - 2*24*60*60)) // && (stripos($strBody, 'Kitson') !== false))
	{
		$anch = $xmlHeading->childNodes->item(2)->firstChild;
		$title = $anch->nodeValue;

		if ($anch->nodeName == 'a')
		{
			$url = $anch->getAttribute("href");
		}
		else
		{
			$url = '';
		}
		
		$mail = $mail . $title . $strBody . $url . "\n\n";
	}
}

if ($mail != '')
	mail("jamie@kitten-x.com,rodkitson@hotmail.com", "SOS", $mail);
	// echo $mail;

?>
