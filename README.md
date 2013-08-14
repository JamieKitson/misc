Dad
===
A script to poll the [SOS.org.uk](http://sos.org.uk) website and email me posts by my dad.

Solar
=====
A script to poll the [enecsys](https://monitor.enecsys.net) website for solar panel output power
and email an alert if it falls below a certain level. See also
<http://www.fhemwiki.de/wiki/Enecsys_Monitoring_System>

index.php builds the resultant log file into a google chart, see for example:
<http://solar.kitten-x.com>

Flickr Backup/Migration Script
==============================

This script attempts to download your pictures from Flickr and add
tags, geo-tags, title, description and comments to the EXIF data. It
is an attempt at a more useful version of 
<http://hsivonen.iki.fi/photobackup/>. 

Note that because the Flickr
API does not offer a way to check for updates to images this is not an
incremental, cumulative or differential backup tool, ie, it will not
automatically backup changes to your photos, eg, added tags, comments,
editted or replaced photos will not be automatically backed up. It is
probably more useful as a migration tool.

With thanks to [Seth Golub](http://www.sethoscope.net/geophoto/) for 
the EXIF GPS info.
