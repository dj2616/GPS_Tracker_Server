<?php

/*
Receive GPS location data (user GPRS link) from a TK103 gps tracker device and show in Google maps

(c) 2019 Bart Mellink

This code read the database with GPS data (created by receivermulti.php) and creates a KML file (to STDOUT)
This means the php file can be called from a web server

Note: In the future this code could be rewritten to use maps data layer.
See https://developers.google.com/maps/documentation/javascript/datalayer

Revisions:
- 1 Jun 2019: initial coding
- 10 Jun: first github release
- 13 Jun: added pin points, clean up code and layout
*/

error_reporting(E_ERROR | E_PARSE);

require_once 'config.php'; // keys and login information

// Paris and Idiorm are handy libs to handle mysql command sets
require_once 'Paris/idiorm.php';
require_once 'Paris/paris.php';

// database model
class Gpsdata extends Model { }

ORM::configure('mysql:host='.DBHOST.';dbname='.DBNAME);
ORM::configure('username', DBUSER);
ORM::configure('password', DBPASSWORD);
// set sql mode to less strict
ORM::configure('driver_options', array(PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES \'UTF8\', SQL_MODE = \'\''));

date_default_timezone_set(DBTIMEZONE);

// Arguments are:
//  date = date in format YYYY-MM-DD
//  sn = serial number
//  rnd = random number to ensure Google does not cache our kml file (ignored, used to force cache)

// allow running the script from the web server and using php manually on server (for testing)
// if date is not specified, we will set date to the most recent date we have data in the database
if (isset($_SERVER["REMOTE_ADDR"])) {
  // normal mode: from server
  $sn = (isset($_GET['sn']) ? $_GET['sn'] : 0) * 1;
  $date = (isset($_GET['date']) ? $_GET['date'] : "");
} else {
  // testing when running from command line, first argument is serial number, second is date
  $sn = (isset($argv[1]) ? $argv[1] : 0) * 1;
  $date = (isset($argv[2]) ? $argv[2] : "");
}

$lastdate=$firstdate=date('Y-m-d');

// get a list of all available dates we have for this tracker in the database
$availdates = Model::factory('Gpsdata')
        ->where_equal('serial', $sn)
        ->select_expr('date(datetime)', 'date')
        ->select_expr('max(lat)-min(lat)', 'lat1')
        ->select_expr('max(`long`)-min(`long`)', 'long1')
        ->where_not_equal('cmd','BP05') // may not have stable gps signal yet
        ->where_not_equal('valid', 0)
        ->group_by_expr('date(datetime)')
        ->order_by_desc('date')
        ->find_many();

// create $alldates array with all dates for which we have data 
// also indicate with the flag 'ismoved' if on the given date gps coordinates are spread wider
// then the minimum threshold defined in MINMOVELATLONG
// Always include the most recent date for which we have data in this list (ismoved==true)
// also assign $date in case it is not supplied as commandline argument
if (!$availdates) $alldates = 'No data'; else {
  $alldates = array();
  foreach ($availdates as $i=>$avail) {
    $dat=date_create($avail->date);

    if ($i==0) $lastdate = $avail->date;  // $availdates is sorted descending, so $i==0 is my last date
    $firstdate = $avail->date;
    if ($i==0 && $date=="") $date = $avail->date;
  
    $alldates[] = array('value'=>$avail->date, 'ismoved'=> $avail->lat1+$avail->long1 > MINMOVELATLONG || $i==0);
  }
}

// get all waypoints for a given date
// The GPS tracker often sends the same data more than once, we filter out duplicates
$datarec = Model::factory('Gpsdata')
 				->select('*')
        ->where_equal('serial', $sn)
 				->where_not_equal('valid', 0)
 				->where_not_equal('cmd','BP05') // may not have stable gps signal yet
        ->where_raw("date(datetime)=?", $date)
        ->group_by('gpstime')      // filter out duplicate entries
 				->order_by_asc('gpstime')  // this is sorting order of original gps signal
 				->find_many();
 // print_r($datarec);

// Now analyze waypoints to figure out start/stop moments of trips
// We do this by looking at the gps speed parameter. Speed should be > 1 km/hour during at least
// 4 consecutive gps coordinates (one or 2 points may point to random movement) 
// We also consider at least 4 consecutive speed==0 readings as the end of a trip.
// Note: alternative approach would be use bit 0 of the status column.
$trips = $tripids = array();
$status = 0; // 0=stop, 1,2,3=may move, 4=move, 5,6,7=may stop
$tripstart = $lastone = $firstone = array();

// status/alarm codes based on bits
$powererr = array();  // power lost (1 on bit 7)
$starterr = array();  // start motor (1 on bit 6)
$shockerr = array();  // movement (error 7) 
$chargeerr = array(); // charging now (error 1 or 2?)
$powerstat = $chargestat = $shockstat = $startstat = NULL; //copy of rec when became active

foreach($datarec as $i=>$rec) {
  $lastone = $rec->as_array();
  if ($i==0) $firstone = $lastone;
  switch($status) {
    case 0: // stopped
        if ($rec->speed > 1) $tripstart = $rec->as_array();
    case 1: // may move step 1
    case 2: // may move step 2
    case 3: // may move step 3
        if ($rec->speed > 1) $status++; else $status = 0;
        break;
    case 4: // moving
    case 5: // may stop step 1
    case 6: // may stop step 2
        if ($rec->speed == 0) $status++; else $status = 4;
        break;
    case 7: // may stop step 3
    default:
        if ($rec->speed == 0) {
          $d = date_diff(date_create($tripstart['gpstime']), date_create($rec->gpstime));
          $trips[] = array('start' => $tripstart, 'end' => $rec->as_array(), 'duration' => $d->h*60 + $d->i + ($d->s>=30 ? 1 : 0));
          $tripids[] = $tripstart['id']; // for kml generation
          $status = 0; 
        } else $status = 4;
        break;
  }
  errorhandling($powererr, (floor($rec->status/10000000)>0), $rec->as_array(), $powerstat);
  errorhandling($starterr, (floor($rec->status/1000000)%10>0), $rec->as_array(), $chargestat);
  errorhandling($shockerr, $rec->err==7, $rec->as_array(), $shockstat);
  errorhandling($chargeerr, $rec->err==2, $rec->as_array(), $startstat);
}
if ($status >= 4) {
  // handle case when we have processed all data points and we are still moving
  $d = date_diff(date_create($tripstart['gpstime']), date_create($lastone['gpstime']));
  $trips[] = array('start' => $tripstart, 'end' => $lastone, 'duration' => $d->h*60 + $d->i + ($d->s>=30 ? 1 : 0));
  $tripids[] = $tripstart['id']; // for kml generation
}
errorhandling($powererr, 0, $lastone, $powerstat);
errorhandling($starterr, 0, $lastone, $startstat);
errorhandling($shockerr, 0, $lastone, $shockstat);
errorhandling($chargeerr, 0, $lastone, $chargestat);

function errorhandling(&$err_array, $status, $rec, &$startmoment) {
  // only do something on change
  if ($status && $startmoment==NULL) {
    // Alarm just turns on
    $startmoment = $rec;
  } else if (!$status && $startmoment!=NULL) {
    // now off and it was not on
    $d = date_diff(date_create($startmoment['gpstime']), date_create($rec['gpstime']));
    $err_array[] = array('start' => $startmoment, 'end' => $rec, 'duration' => $d->h*60 + $d->i + ($d->s>=30 ? 1 : 0));
    $startmoment = NULL;
  }
}

// see https://developers.google.com/kml/articles/phpmysqlkml
// Creates the KML Document (which is in XML format)
$dom = new DOMDocument('1.0', 'UTF-8');

// Creates the root KML element and appends it to the root document.
$node = $dom->createElementNS('http://earth.google.com/kml/2.1', 'kml');
$parNode = $dom->appendChild($node);

// Creates a KML Document element and append it to the KML element.
$dnode = $dom->createElement('Document');
$docNode = $parNode->appendChild($dnode);

// The doc description field contains json_encoded data which we need to display the calendar view and other
// information about a given date
$docDesc = $dom->createElement('description', json_encode(
  array(
    'alldates' => $alldates,  // list of all dates for which we have data (and where there is movement)
    'date'     => $date,      // the current displayed date (for which we provide detailed data)
    'lastdate' => $lastdate,  // most recent date for which we have data (YY-MM-DD)
    'firstdate'=> $firstdate, // oldest date for which we have date (YY-MM-DD)
    'trips'    => $trips,     // list of trips for the given date
    'firstone' => $firstone,  // complete record of first data entry of given date
    'lastone'  => $lastone,   // complete record of most recent data for the given date
    'powererr' => $powererr,  // array of events related to battery power of gps tracker (on/off)
    'starterr' => $starterr,  // array of events related to motor start (on/off)
    'shockerr' => $shockerr,  // array of events related to shocking the gps tracker (on/off)
    'chargeerr' => $chargeerr,// array of events related to ecternal charging of boat battery (on/off)
    'age'      => (time() - strtotime($lastone['datetime'])), // age (in seconds) of last record
  )
));

$docNode->appendChild($docDesc);
$docName = $dom->createElement('name','Tracker data '.$date);
$docNode->appendChild($docName);

// pick colors here: http://www.zonums.com/gmaps/kml_color/
// Please note kml colors are defined as AABBGGRR and not as in CSS: RRGGBB / RRGGBBAA

// line style color red
$f = $dom->createDocumentFragment();
$f->appendXML('<Style id="colorpoly0"><LineStyle><color>501400F0</color><width>4</width></LineStyle>'.
              '<PolyStyle><color>50140000</color></PolyStyle></Style>');
$docNode->appendChild($f);

// line style color dark green
$f = $dom->createDocumentFragment();
$f->appendXML('<Style id="colorpoly1"><LineStyle><color>5f006E14</color><width>4</width></LineStyle>'.
              '<PolyStyle><color>5f006E14</color></PolyStyle></Style>');
$docNode->appendChild($f);

// line style color dark green
$f = $dom->createDocumentFragment();
$f->appendXML('<Style id="colorpoly2"><LineStyle><color>55782814</color><width>4</width></LineStyle>'.
              '<PolyStyle><color>55782814</color></PolyStyle></Style>');
$docNode->appendChild($f);

// red colored dot
$f = $dom->createDocumentFragment();
$f->appendXML('<Style id="red"><IconStyle>'.
        '<Icon><href>https://www.google.com/intl/en_us/mapfiles/ms/icons/red-dot.png</href></Icon>'.
        '</IconStyle></Style>');
$docNode->appendChild($f);

// green colored dot
$f = $dom->createDocumentFragment();
$f->appendXML('<Style id="green"><IconStyle>'.
        '<Icon><href>https://www.google.com/intl/en_us/mapfiles/ms/icons/green-dot.png</href></Icon>'.
        '</IconStyle></Style>');
$docNode->appendChild($f);

// blue colored dot
$f = $dom->createDocumentFragment();
$f->appendXML('<Style id="blue"><IconStyle>'.
        '<Icon><href>https://www.google.com/intl/en_us/mapfiles/ms/icons/blue-dot.png</href></Icon>'.
        '</IconStyle></Style>');
$docNode->appendChild($f);

$toggle = 0;  // alternate colors of trip segments (0,1,2)

$PlacemarkNode = $dom->createElement('Placemark');
$docNode->appendChild($PlacemarkNode);
$PlaceStyle = $dom->createElement('styleUrl','#colorpoly'.$toggle);
$PlacemarkNode->appendChild($PlaceStyle);
$LineString = $dom->createElement('LineString');
$PlacemarkNode->appendChild($LineString);

$coorStr ="";
$timesave=0; 

foreach($datarec as $i=>$rec) {
  // Creates a coordinates element and gives it the value of the lng and lat columns from the results.
  // Coordinates in database are already in DD (Decimal Degrees) format (52.1825)

  $t = strtotime($rec->gpstime);
  $hst = ($rec->long) . ','  . ($rec->lat).",0 \n";

  // add the first timestamp in the description within Placemark
  if ($i==0) {
  	$PlaceDesc = $dom->createElement('description','Start at '.$rec->gpstime);
    $PlacemarkNode->appendChild($PlaceDesc);
  	$PlaceName = $dom->createElement('name','Start at '.substr($rec->gpstime,0,5));
    $PlacemarkNode->appendChild($PlaceName);
  }

  // we will create a new LineString if:
  // - the time interval between the current and prior point is larger than TIMEGAP 
  // - if we cross the day boundary (does not happen in most cases) [Note: date('z') gives day in year 0..365]
  // - if we start a new trip (based on $tripids array) 

  if ($i>0 && ($t>$timesave+TIMEGAP || date('z',$t)!=date('z',$timesave) || in_array($rec->id, $tripids) )) { 

  	// We include the current lat/long in case we move to new trip (so are lines are not broken).
    // In other cases we do not include the current coordinate
  	$coorNode = $dom->createElement('coordinates', $coorStr.(in_array($rec->id, $tripids) ? $hst : ''));
  	$LineString->appendChild($coorNode);
  	// and create a new one
  	$PlacemarkNode = $dom->createElement('Placemark');
  	$docNode->appendChild($PlacemarkNode);
   	$PlaceDesc = $dom->createElement('description','Start at '.$rec->gpstime);
    $PlacemarkNode->appendChild($PlaceDesc);
   	$PlaceName = $dom->createElement('name','Start at '.substr($rec->gpstime,0,5));
    $PlacemarkNode->appendChild($PlaceName);
    $toggle++; 
    if ($toggle>2) $toggle=0;
  	$PlaceStyle = $dom->createElement('styleUrl','#colorpoly'.$toggle);
    
  	$PlacemarkNode->appendChild($PlaceStyle);
  	$LineString = $dom->createElement('LineString');
  	$PlacemarkNode->appendChild($LineString);
   	$coorStr = $hst;  // start of new line coordinates
  } else {
  	// simply append coordinate to string
  	$coorStr .= $hst;
  }
  $timesave = $t;
}

$coorNode = $dom->createElement('coordinates', $coorStr);
$LineString->appendChild($coorNode);

function addplacemarkpin($id, $name, $lat, $long, $styleUrl) {
  global $docNode, $dom;
  $PlacemarkNode = $dom->createElement('Placemark');
  $PlacemarkNode->setAttribute('id', 'id'.$id);
  $PlaceName = $dom->createElement('name', $name);
  $PlacemarkNode->appendChild($PlaceName);
  $PlacemarkPoint = $dom->createElement('Point');
  $coorNode = $dom->createElement('coordinates', $long.','.$lat.',0');
  $PlacemarkPoint->appendChild($coorNode);
  $PlacemarkNode->appendChild($PlacemarkPoint);
  $StyleUrl = $dom->createElement('styleUrl', $styleUrl);
  $PlacemarkNode->appendChild($StyleUrl);
  $docNode->appendChild($PlacemarkNode);
}

// show green pin/dot at the start of each trip
foreach ($trips as $i=>$trip) {
  $rec = $trip['start'];
  addplacemarkpin($rec['id'], 'Trip: '.(substr($rec['gpstime'],0,5)).' -> '.(substr($trip['end']['gpstime'],0,5)), $rec['lat'], $rec['long'], '#green');
}

// show red pin/dot at the last entry we have
if (!empty($lastone)) {
  addplacemarkpin($lastone['id'], 'Last position '.substr($lastone['gpstime'],0,5), $lastone['lat'], $lastone['long'], '#red');
}

// now generate our XML data
$kmlOutput = $dom->saveXML();
header('Content-type: application/vnd.google-earth.kml+xml');
echo $kmlOutput;
?>