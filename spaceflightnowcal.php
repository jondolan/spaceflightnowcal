<?php
// Written by Jon Dolan 2015
	// http://www.jondolan.me
	// http://blog.jondolan.me

// The great website SpaceFlightNow.com keeps a list of space launches around the world available at http://spaceflightnow.com/launch-schedule/
// This is a script that parses that page and creates a Google Calendar of launches that have a determined date
// The calendar is available to the public (which means you wont have to run this script yourself, I run it off my Raspberry Pi once a day to update)
    // XML: https://www.google.com/calendar/feeds/822qkodeiei5781qv56kaa0ep4%40group.calendar.google.com/public/basic
    // ICAL: https://www.google.com/calendar/ical/822qkodeiei5781qv56kaa0ep4%40group.calendar.google.com/public/basic.ics
    // HTML: https://www.google.com/calendar/embed?src=822qkodeiei5781qv56kaa0ep4%40group.calendar.google.com&ctz=America/New_York
    // It is also embedded in a blog post with some more info available at: http://www.jondolan.me/blog/projects/2015/02/spaceflightnow-calendar/ 

// This script utilizes the Google PHP Api Client (https://github.com/google/google-api-php-client) and Simple HTML Dom (http://sourceforge.net/projects/simplehtmldom/)

//---   Requirements ---//
require_once 'simple_html_dom.php'; // http://sourceforge.net/projects/simplehtmldom/
require_once 'google-api-php-client/src/Google/autoload.php'; // https://github.com/google/google-api-php-client


//---- Global settings variables - edit in file! ----//
require_once 'authenticationInfo.php';


//-- No need to change these! --//
$headers = array('http'=>array('header'=>"User-Agent:Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/32.0.1700.76 Safari/537.36\r\n"));
$context = stream_context_create($headers); // create an context to make our script look like a regular browser and be a little more friendly (I guess)
$pagePath = "http://spaceflightnow.com/launch-schedule/"; // the page we want to get the data from
$applicationName = "SpaceFlightNow Launch Schedule"; // a name for the application
$scopes ="https://www.googleapis.com/auth/calendar"; // permission scope (we want read and write access to Calendar API)
$timezone = "America/New_York"; // even if you are in a different timezone, do not change this because the launch times are parsed in EST/EDT

//---- Setup the Google API Client ----//
$client = new Google_Client(); // create the client
$client->setApplicationName($applicationName); // set the application's name
$key = file_get_contents($keyFilePath); // get the keyfile's contents
$credentials = new Google_Auth_AssertionCredentials( //  create credentials object
	$clientEmailAddress,
	array($scopes),
	$key
);
$client->setAssertionCredentials($credentials); // apply the credentials
if ($client->getAuth()->isAccessTokenExpired()) { // refresh the session if the credentials are expired
	$client->getAuth()->refreshTokenWithAssertion($credentials);
}
$cal = new Google_Service_Calendar($client); // Create a Calendar object with the credentials


//---- Get the page data with the user agent headers ----//
$rawhtml = file_get_contents($pagePath, false, $context);
if ($rawhtml === FALSE) { // if we failed at getting the page for some reason...say so and exit
	echo "Unable to get webpage contents";
	exit;
}
else { // if we succeeded, then parse the HTML into a Simple HTML DOM object  
    $pagedata = str_get_html($rawhtml);
}


//---- Parse the webpage for events ----//
$launches = []; // (soon to be) multidimensional array to hold the launches and their respective data
$numlaunches = 0; // track the number because not all are added to the array (just curious)
foreach($pagedata->find('div') as $element) { // for each DIV found in the page
    if ($element->class === "datename") { // start a sequence based on the elements: datename, missiondata, missiondescription that appear in sequence in the HTML
        $numlaunches++; // increment it
        $allday = false; // false all day event by default
        
        $datename = $element; // it's the element we found after all   
        $missionData = $element->nextSibling()->innertext; // missiondata is found in the next element (ie: <div class="missiondata"><span class="strong">Launch window:</span> 2150:20 GMT (5:50:20 p.m. EDT)<span class="strong"><br>Launch site:</span> ELS, Sinnamary, French Guiana</div>)
        $missionDescription = $element->nextSibling()->nextSibling()->innertext; // the missiondescription (calendar notes) is the easiest, it's just 2 siblings down and in it's own element
        $missionDescription = str_replace("&#8217;", "'", $missionDescription); // fix single quotes
        $missionDescription = strip_tags($missionDescription); // strip all remaining tags
        
        $name = $datename->lastChild()->innertext; // the name is in the last child of the datename div
        $name = str_replace("&amp;", "&", $name); // fix the &amp;s that showed up
        
        echo "\n" . $name . ": "; // print out the name with more info to be followed
        
        $locationSubStrStart = strrpos($missionData, "</span> ") + 8; // where to start the search for launch location
        $location = substr($missionData, $locationSubStrStart); // get the location isolated
        
        $date = $datename->firstChild()->innertext; // the date is in the first child of the datename div (ie: <div class="datename"><span class="launchdate">March 26</span><span class="mission">Soyuz • Galileo FOC-2</span></div>)
        if (stristr($date, "TBD")) { // if there is no date, there is no event yet
			echo "launch date yet to be determined..."; // say there's no date yet 'cause it's TBD
			continue; // continue to the next launch
		}
        if (strpos($date, "/") !== false) { // if there is a / (ie: March 25/26)
            $date = explode("/", $date)[0]; // changes are it's because the GMT time is ahead, meaning a date overlap, so take the first...should be more elegant but
        }
        $date = str_replace(".", "", $date); // remove the periods (ie: Feb. 17)
        $date = date_create_from_format("M j", $date); // TODO: update to look around the end of the year based on the current day (obviously they wouldn't be adding a new date in January if it's March)
        if (!$date) { // date is invalid (ie: 2nd Quarter)
            echo "exact launch date yet to be determined..."; // the launch date is ambiguous
            continue; // next!
        } else { // the date is valid...
            $date = date_format($date, "Y-m-d"); // ...so let's get it in the format we want
        }
        
        $timeSubStrStart = strpos($missionData, ":</span> ") + 9; // where to start the search for the launch window time
        $timeSubStrEnd = strrpos($missionData, "<span class"); // where to end it
        $timeWindow = substr($missionData, $timeSubStrStart, $timeSubStrEnd - $timeSubStrStart); // isolate the time window
        
        if (stristr($timeWindow, "TBD")) { // if the time is TBD
            echo "specific launch time yet to be determined...but date is " . $date; // say the time is TBD, but there is a date!
            $start = $end = $date; // the start and the end are the same
            $allday = true; // 'cause it's an all day event
        } else { // if we have a time, continue parsing the time  
            $timeMatches = []; // array to be populated with just the EST/EDT portion of the time window (parse out the GMT)
            preg_match("/\([0-9]+:[0-9]+.*[E][A-Z][T]/", $timeWindow, $timeMatches); // regular expression search for the EST/EDT part
            if (!empty($timeMatches)) // if the timewindow is, for example, TBD, then we don't want to trim because we didn't match anything
                $timeWindow = trim($timeMatches[0], "()"); // trim off the ( ) from the time window
            $timeWindow = str_replace(".", "", $timeWindow); // remove the periods (ie: 8:00 a.m. EST)
            if (strpos($timeWindow, "-") !== false) { // if there is a "-", then we have a time range not an instantaneous launch window (ie: 8:00-9:43 a.m. EST)
                $explode = explode("-", $timeWindow); // explode the window range into 2 parts
                $timeModifierMatches = []; // we need to put the EST/EDT and am/pm on both sides of the time range, but if we split it by the - we will just have it on the second part
                preg_match("/[a,p]\.?[m]\.?\ ?[E][S,D][T]/", $explode[1], $timeModifierMatches); // match the time modifiers ("a.m." (or "am" thanks to the ?s), p.m and EST/EDT)
                $timeWindowStart = $explode[0] . " " . $timeModifierMatches[0]; // start of the time window
                $timeWindowEnd = $explode[1]; // end of the time window
            } else { // if there is no range, it's just one time
                $timeWindowStart = $timeWindowEnd = $timeWindow; // so they're all equal to the timeWindow itself
            }
            $start = date_create_from_format("Y-m-d g:i a T", $date . " " . $timeWindowStart); // create a date based off what we know
            $end = date_create_from_format("Y-m-d g:i a T", $date . " " . $timeWindowEnd); // same thing ^
            if (!$start || !$end) { // if the time failed to create
                echo "something went wrong :("; // TODO: mail me
                continue; // skip out
            } else { // should be good to create the string we want, we want a string like 2011-06-03T10:25:00.000-07:00
                $start = date_format($start, "c"); // "c" is a standard that Google wants
                $end = date_format($end, "c"); // make it for both
            }
            
            echo "launching on " . $start; // say the launch is on _
        }
               
        // push this launch info to the end of the launches array
        array_push($launches, [
            "summary" => $name . " at " . $location,
            "description" => $missionDescription,
            "location" => $location,
            "start" => $start,
            "end" => $end,
            "allday" => $allday
        ]);
       
    }
}

//---- Create the calendar events ----//
$eventList = $cal->events->listEvents($calendarId)->getItems();
$eventsAdded = 0; // track events added
$eventsChanged = 0; // track events changed
foreach ($launches as $launch) { // for each launch in the array that we just pulled from online
    $preexisting = false; // to track if it's new or not
    foreach ($eventList as $event) { // for each prexisting event in the calendar
        if ($event["summary"] === $launch["summary"]) { // if the events match by title
            $preexisting = true; // the event does already exist
            $different = []; // an array to keep track of what's different
            foreach ($launch as $key => $value) { // check each of the fields to check for differences 
                if ($key != "allday") { // we don't care to check it
                    if ($event[$key] == $value) { // check for similarity
                        continue; // this is good, no update for this key
                    } elseif (($key == "end" || $key == "start") && ($event[$key]["date"] == $value || $event[$key]["dateTime"] == $value)) { // the date time is slightly harder to compare, this is a little hacky but it's the easiest...
                        continue; // this is good too
                    } else { // this has differences, so we need to address it later
                        $different[$key] = $value; // pass the value to look at later
                    }
                }
            }
            if (empty($different)) { // no differences, say so
                echo "\nComplete match between local and remote \"" . $launch["summary"] . "\" event";
            } else { // we have differences, so we should update it
                $differencesList = "";
                foreach ($different as $key => $value) { // get a list of differences (useful for debugging)
                    $differencesList .= $key . " ";
                }
                echo "\nDifferences between local and remote \"" . $launch["summary"] . "\" event in: " . $differencesList; // say it's different and why
                updateEvent($event, $different, $launch['allday']); // update it now
            }
        }
    }
    if (!$preexisting) { // it's a new event...
        createCalEvent($launch); // ...so make it!
    }
}

function updateEvent(&$event, $updates, $allday) { // update events based on an array passed
    global $cal, $calendarId, $timezone; // global stuff needed to change calendar

    $event->sequence = $event->sequence + 1; // increment the number of times the event has been updated

    if (!empty($updates["summary"])) // if we have a summary update
        $event->setSummary($updates["summary"]);
    if (!empty($updates["description"])) // if we have a description update
        $event->setDescription($updates["description"]);
    if (!empty($updates["location"])) // if we have a location update
	$event->setLocation($updates["location"]);
    

    if (!empty($updates["start"])) { // if we're updating the start
        $start = new Google_Service_Calendar_EventDateTime(); // make a new start time object
        $start->setTimeZone($timezone);
        if ($allday) // if it's allday
            $start->setDate($updates["start"]); // set the start
        else // not all day
            $start->setDateTime($updates["start"]); // set the specific start time
        $event->setStart($start); // apply
    }
    if (!empty($updates["end"])) { // if we're updating the start
        $end = new Google_Service_Calendar_EventDateTime(); // make a new end time object
        $end->setTimeZone($timezone);
        if ($allday) // if it's allday
            $end->setDate($updates["end"]); // set the end
        else // not all day
            $end->setDateTime($updates["end"]); // set the specific end time
        $event->setEnd($end); // apply
    }
    
    $event->setTransparency("transparent"); // make sure it's not taking up time
    $cal->events->update($calendarId, $event->getId(), $event); // push the update
    echo "\n     Updated"; // say we updated
}

function createCalEvent($launch) { // create calendar events
	global $cal, $calendarId; // global vars for dealing with the cal
	
	$event = new Google_Service_Calendar_Event(); // create a new event object
	$event->setSummary($launch["summary"]); // set summary
	$event->setDescription($launch["description"]); // set description
	$event->setLocation($launch["location"]); // set location
    
	$estart = new Google_Service_Calendar_EventDateTime(); // event start object
    if (!$launch["allday"]) // all day? if not
        $estart->setDateTime($launch["start"]); // specific time
    else // if all day
        $estart->setDate($launch["start"]); // just a date, no time
	$estart->setTimeZone("America/New_York");
	$event->setStart($estart); // apply
    
	$eend = new Google_Service_Calendar_EventDateTime(); // event end object
	if (!$launch["allday"]) // all day? if not
        $eend->setDateTime($launch["end"]); // specific time
    else // if all day
        $eend->setDate($launch["end"]); // just a date, no time
	$eend->setTimeZone("America/New_York");
	$event->setEnd($eend); // apply
    
    $event->setTransparency("transparent"); // we don't want to appear busy
    
	$new_event = $cal->events->insert($calendarId, $event); // create the new event
	echo "\nEvent \"" . $event["summary"] . "\" created"; // say so!
}

//---- Spit out some information ----//
echo "\n\nThe calendar had " . sizeof($eventList) . " items. Now it has " . sizeof($cal->events->listEvents($calendarId)->getItems()) . " items.";
echo "\nThere were " . $numlaunches . " launches detected, of which " . sizeof($launches) . " had at least a valid date and were able to be parsed for changes\n\n";
?>
