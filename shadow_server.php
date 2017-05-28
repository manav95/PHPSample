<?php
//  orbit_ghost.php
//
//  Implementation of Orbit Ghost user. Essentially a backend PHP clone of what happens on client node phones whenever they load up a city.
//  If we do it on the backend automatically every few minutes or so, users pay a much smaller average time penalty when using the app. In
//  theory we should only need to do this until we have enough users for peer-to-peer info exchange and/or caching to prevail.
//
//  Brandon Thorpe (DALnet shadrak)
//  1/10/14
require_once('XMPPHP/XMPP.php');
require 'vendor/autoload.php';
define('GHOST_CONSOLE_DUMP', TRUE);
define('ORBIT_PLATFORM_INSTAGRAM_ID', '0');
define('ORBIT_PLATFORM_TWITTER_ID', 1);
define('ORBIT_PLATFORM_FOURSQUARE_ID', 2);
define('ORBIT_PLATFORM_ORBIT_ID', 9999);
//  Foursquare API
define('FOURSQUARE_API_PREFIX_VENUE_SEARCH', 'https://api.foursquare.com/v2/venues/search');
define('FOURSQUARE_API_VENUE_NOISE_FILTER_CHECKIN_MINIMUM', 500);
//  Orbit API
define('ORBIT_API_PREFIX_DYNAMIC_CONFIGURATION', 'http://api.primesengine.com:7773/app/configure/orbit.php');
define('ORBIT_API_VENUE_INFORM_PREFIX', 'http://api.primesengine.com:7773/venue/inform/orbit.php');
define('ORBIT_API_VENUE_QUANTIFY_PREFIX', 'http://api.primesengine.com:7773/venue/quantify/orbit.php');
define('ORBIT_API_MEDIA_INFORM_PREFIX', 'http://api.primesengine.com:7773/media/inform/inform_media.php');
define('PRIMES_API_GHOST_CONFIGURATION', 'http://ghost.primesengine.com:7772/ghost_config.php');
define('ORBIT_REGIONAL_SPANNING_SEARCH_RADIUS', 50e3);
define('ORBIT_ERROR_SUCCESS', 200);
define('ORBIT_ERROR_INTERNAL', 300);
define('ORBIT_INFORM_ERROR_INSUFFICIENT_INFO', 301);
define('ORBIT_INFORM_ERROR_INVALID_VALUE', 302);
define('ORBIT_QUANTIFY_ERROR_UNSUPPORTED_FEATURE', 303);
define('ORBIT_QUANTIFY_ERROR_INVALID_MODE', 304);
define('ORBIT_ORBIT_ERROR_INVALID_ACTION', 305);
define('ORBIT_SYNCHRONIZE_ERROR', 306);
define('ORBIT_QUANTIFY_ERROR_INVALID_TIMESTAMP', 307);
define('ORBIT_QUANTIFY_ERROR_INSUFFICIENT_INFO', 308);
define('ORBIT_QUANTIFY_ERROR_RESULTS_INVALID', 309);
define('ORBIT_QUANTIFY_ERROR_STALE_CONTENT', 310);
define('ORBIT_GEO_ERROR_INVALID_QUERY', 311);
define('ORBIT_SYNCHRONIZE_USER_EXISTS', 312);
define("ORBIT_USER_ERROR_EMAIL_ADDRESS_NOT_FOUND", 313);
define("ORBIT_USER_ERROR_BAD_CREDENTIALS", 314);
define("ORBIT_USER_ERROR_INVALID_UPDATE_INFO_SPECIFIED", 315);
define('ORBIT_MASTER_CLIENT_ID', 'OMC_ID');
define('ORBIT_MASTER_USER_ID', 'OMC_ID');
define('ORBIT_MASTER_ACCESS_TOKEN', 'OMC_ID');
//  Instagram API
define('INSTAGRAM_API_LOCATIONS_PREFIX', 'https://api.instagram.com/v1/locations');
define('INSTAGRAM_API_LOCATIONS_SEARCH_PREFIX', 'https://api.instagram.com/v1/locations/search');
//Parse declarations
use Parse\ParseObject;
use Parse\ParseQuery;
use Parse\ParseACL;
use Parse\ParsePush;
use Parse\ParseUser;
use Parse\ParseInstallation;
use Parse\ParseException;
use Parse\ParseAnalytics;
use Parse\ParseFile;
use Parse\ParseCloud;
use Parse\ParseClient;
// Parse API
ParseClient::initialize('cRK2THVclmPw4HvXy93kEa6XBJNtpXHfiNaQWuMy', 'O3eFtrJBZrzm8rqgp8b93nCFaE1Z9efgGB6pDmVC', 'MJFFZ18G5zybauJcWIpEqD9uaulEF4aXbzRFvEKDMJFFZ18G5zybauJcWIpEqD9uaulEF4aXbzRFvEKD');
//  Script variables
$foursquareApiCallCount = 0;
$instagramApiCallCount = 0;
$orbitApiCallCount = 0;
$unixStartTime = time();
$curl = curl_init();
$xmpp = new XMPPHP_XMPP('primesengine.com', 5222, 'ghost1', 'ghostworld', 'xmpphp');
$xmpp->connect();
$xmpp->processUntil('session_start');
$xmpp->presence();
$venueArray = array();
$firstGhostRun = TRUE;
////  TODO:   Pull city centerpoint coordinates from ghost city schedule document (uploaded via ftp under ghost account)
////  Coordinates must match those stored in Primes geonames_table db on Alice.
//$ghostCitySet = array(
//    array(34.05223, -118.24368), //  LA
//    array(36.17497, -115.13722), //  Las Vegas
//    array(33.50921, -111.89903), //  Scottsdale
//    array(41.85003, -87.65005), //  Chicago
//    array(33.749, -84.38798), //  Atlanta
//    array(25.77427, -80.19366), //  Miami
//    array(42.35843, -71.05977), //  Boston
//    array(40.71427, -74.00597)     //  New York
//);
//    array(42.35843, -71.05977), //  Boston
//    array(40.71427, -74.00597)     //  New York
//);
$ghostCitySet = array(
    array(34.029604, -118.483830),  //  Mid-City Santa Monica, CA
    array(34.085353, -118.398686),  //  The Flats Beverly Hills, CA
    array(34.056346, -118.249684),  //  New Downtown, LA
    array(34.066016, -118.327618),  //  Greater Wilshire, LA
    array(34.099569, -118.327618),  //  Hollywood, LA
    array(34.177536, -118.381201),  //  NOHO, LA
    array(34.039953, -118.340003),  //  Mid City, LA
);
while (1 == 1) {
    foreach ($ghostCitySet as $ghostCityCoords) {
        $cityCenterLatitude = $ghostCityCoords[0];
        $cityCenterLongitude = $ghostCityCoords[1];
        curl_setopt($curl, CURLOPT_POST, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
//  Get default configuration from Orbit dynamic configuration endpoint
        curl_setopt($curl, CURLOPT_URL, ORBIT_API_PREFIX_DYNAMIC_CONFIGURATION);
        $resp = curl_exec($curl);
        $orbitApiCallCount++;
        $dynamicConfig = json_decode($resp, TRUE);
        $foursquareDynamicCategories = $dynamicConfig['foursquareDynamic']['categories'];
        $foursquareDynamicOAuthClientId = $dynamicConfig['foursquareDynamic']['client_id'];
        $foursquareDynamicOAuthClientSecret = $dynamicConfig['foursquareDynamic']['client_secret'];
        $foursquareDateString = date("Ymd");
        $instagramDynamicOAuthClientId = $dynamicConfig['instagramDynamic']['client_id'];
//  TODO:   Round Robin ghost access tokens (very important)
//  Request a dynamic ghost access token
        curl_setopt($curl, CURLOPT_URL, PRIMES_API_GHOST_CONFIGURATION);
        $resp = curl_exec($curl);
        $ghostConfig = json_decode($resp, TRUE);
        $ghostName = $ghostConfig['name'];
        $ghostGroupChat = $ghostConfig['groupchat'];
        $xmppGroupChatEndpoint = "$ghostGroupChat@conference.primesengine.com";
        $instagramDynamicOAuthAccessToken = $ghostConfig['identities'][0]['platform-credentials']['custom-access-token'];
        try {
            $xmpp->presence(null, 'available', "$ghostGroupChat@conference.primesengine.com/$ghostName");
        } catch (XMPPHP_Exception $e) {
//            die($e->getMessage());
        }
        if (GHOST_CONSOLE_DUMP) {
            echo "dynamic configuration endpoint returned:\r\n";
            var_dump($foursquareDynamicCategories);
            var_dump($foursquareDynamicOAuthClientId);
            var_dump($foursquareDynamicOAuthClientSecret);
            var_dump($foursquareDynamicCategoriesString);
            var_dump($instagramDynamicOAuthClientId);
            var_dump($instagramDynamicOAuthAccessToken);
        }
    $xmpp->message($xmppGroupChatEndpoint, "I was assigned a new polymorphic identity. Foursquare OAuth client ID = $foursquareDynamicOAuthClientId Foursquare OAuth client secret = $foursquareDynamicOAuthClientSecret Foursquare search categories = $foursquareDynamicCategoriesString Instagram OAuth client ID = $instagramDynamicOAuthClientId Instagram OAuth access token = $instagramDynamicOAuthAccessToken. My name is now $ghostName.", "groupchat");
//    //  1.  For each category, perform Foursquare search on maximum radius about center point, with maximum result limit (50)
//    //  https://developer.foursquare.com/docs/venues/search
        $venue_set = array();
        foreach ($foursquareDynamicCategories as $category) {
            $query = FOURSQUARE_API_PREFIX_VENUE_SEARCH . "?categoryId=$category,&ll=$cityCenterLatitude,$cityCenterLongitude&radius=" . ORBIT_REGIONAL_SPANNING_SEARCH_RADIUS . "&limit=50&intent=browse&client_id=$foursquareDynamicOAuthClientId&client_secret=$foursquareDynamicOAuthClientSecret&v=$foursquareDateString";
            curl_setopt($curl, CURLOPT_URL, $query);
            $resp = curl_exec($curl);
            $foursquareApiCallCount++;
            $resp_array = json_decode($resp, TRUE);
            $results = $resp_array["response"]["venues"];
            if (GHOST_CONSOLE_DUMP) {
                echo "$query returned: " . count($results) . " venues.\r\n";
            }
        }
//    //  1.  For each category, perform Foursquare search on maximum radius about center point, with maximum result limit (50)
//    //  https://developer.foursquare.com/docs/venues/search
        $venue_set = array();
        foreach ($foursquareDynamicCategories as $category) {
            $query = FOURSQUARE_API_PREFIX_VENUE_SEARCH . "?categoryId=$category,&ll=$cityCenterLatitude,$cityCenterLongitude&radius=" . ORBIT_REGIONAL_SPANNING_SEARCH_RADIUS . "&limit=50&intent=browse&client_id=$foursquareDynamicOAuthClientId&client_secret=$foursquareDynamicOAuthClientSecret&v=$foursquareDateString";
            curl_setopt($curl, CURLOPT_URL, $query);
            $resp = curl_exec($curl);
            $foursquareApiCallCount++;
            $resp_array = json_decode($resp, TRUE);
            $results = $resp_array["response"]["venues"];
            if (GHOST_CONSOLE_DUMP) {
                echo "$query returned: " . count($results) . " venues.\r\n";
            }
            if (count($results) > 0) {
                $xmpp->message($xmppGroupChatEndpoint, "$query returned " . count($results) . " venues.", "groupchat");
            } else {
                $xmpp->message($xmppGroupChatEndpoint, "$query returned " . count($results) . " venues. We may have encountered a problem. Here's the raw Foursquare response: $resp", "groupchat");
                continue;
            }
//            array_push($venue_set, $results);
            foreach($results as $result)
                $venue_set[] = $result;
        }
        //  Discard any duplicates before proceeding
        $unique_venue_set = array();
       foreach ($venue_set as $venue) {
            $venueFoursquareId = $venue["id"];
            $unique_venue_set_contains_venue = FALSE;
//            array_push($venue_set, $results);
            foreach($results as $result)
                $venue_set[] = $result;
        }
        //  Discard any duplicates before proceeding
        $unique_venue_set = array();
       foreach ($venue_set as $venue) {
            $venueFoursquareId = $venue["id"];
            $unique_venue_set_contains_venue = FALSE;
            foreach ($unique_venue_set as $unique_venue) {
                if ($unique_venue["id"] == $venueFoursquareId) {
                    $unique_venue_set_contains_venue = TRUE;
                    break;
                }
            }
            if (!$unique_venue_set_contains_venue) {
                $unique_venue_set[] = $venue;
            }
        }
        $venue_set = $unique_venue_set;
//        //  1.1 Update. Ignore venues that don't meet minimum cleanliness requirements in the foursquare database.
        $clean_venue_set = array();
        foreach ($venue_set as $venue) {
            if ($venue["verified"] || $venue["stats"]["checkinsCount"] >= FOURSQUARE_API_VENUE_NOISE_FILTER_CHECKIN_MINIMUM) {
                $clean_venue_set[] = $venue;
            } else {
                $xmpp->message($xmppGroupChatEndpoint, "Ignoring garbage venue " . $venue["name"] . ".", "groupchat");
            }
        }
        $venue_set = $clean_venue_set;
        if ($firstGhostRun) {
           foreach ($venue_set as $venue) {
               $venueArray[$venue["name"]] = 0;
           }
           $firstGhostRun = FALSE;
        }
        foreach ($venue_set as $venue) {
            //  Inform Orbit that this venue is of potential (and likely) interest to other users. We'll fail silently here as it doesn't really impact the user as much as it does the backend.
            $venueFoursquareId = $venue["id"];
            $venueTitle = $venue["name"];
            $venueProvince = $venue["location"]["city"];
            $venueLatitude = $venue["location"]["lat"];
            $venueLongitude = $venue["location"]["lng"];
            $query = ORBIT_API_VENUE_INFORM_PREFIX . "?platform_id=" . ORBIT_PLATFORM_FOURSQUARE_ID . "&platform_specific_id=$venueFoursquareId&title=$venueTitle&provincial_qualifier=$venueProvince&lat=$venueLatitude&lng=$venueLongitude&radius=10&self_id=" . ORBIT_MASTER_CLIENT_ID . "&access_token=" . ORBIT_MASTER_ACCESS_TOKEN;
            curl_setopt($curl, CURLOPT_URL, $query);
            $resp = curl_exec($curl);
            $orbitApiCallCount++;
            $resp_array = json_decode($resp, TRUE);
            $orbitVenueId = $resp_array["orbit_venue_id"];
            if (GHOST_CONSOLE_DUMP) {
                echo "query $query returned orbit venue id = $orbitVenueId.\r\n";
            }
            if ($orbitVenueId == 0 || !isset($orbitVenueId) || $orbitVenueId == NULL)
                continue;
            $current_time = time();
            $unix_time_from = $current_time - 3600;
            $unix_time_to = $current_time;
            $query = ORBIT_API_VENUE_QUANTIFY_PREFIX . "?orbit_venue_id=$orbitVenueId&self_id=ghost&mode=absolute&unix_time_from=$unix_time_from&unix_time_to=$unix_time_to&client_id=" . ORBIT_MASTER_CLIENT_ID . "&access_token=" . ORBIT_MASTER_ACCESS_TOKEN;
            curl_setopt($curl, CURLOPT_URL, $query);
            $resp = curl_exec($curl);
            $resp_array = json_decode($resp, TRUE);
            $status = $resp_array["status"];
            if ($status == ORBIT_QUANTIFY_ERROR_STALE_CONTENT) {
                //  Here's why we would need a ghost in the first place.
                $xmpp->message($xmppGroupChatEndpoint, $venue["name"]." requires a content refresh, that's why I'm here.", "groupchat");
                if (GHOST_CONSOLE_DUMP) {
                    echo "query $query returned stale content notification.\r\n";
                }
                //            //  Inform api invoked conditionally, the first step was to query Orbit for a venue quantification. If a stale content warning was returned,
//            //  we perform an 'inform' operation, which returns the intended quantification result upon completion.
//            if(error.orbitErrorCode == OrbitApplicationErrorContentRequiresRefresh)
//            {
//                //  TODO:   Failing silently for now.
//                NSArray * recentContent = [[_orbitSession instagram] sampleContentStreamFromVenue:venue error:&error];
//
//                        NSString * urlString = [NSString stringWithFormat:@"%s/%s/media/recent?client_id=%s&access_token=%s",
//                                [INSTAGRAM_API_LOCATIONS_PREFIX UTF8String],
//                                [locationId UTF8String],
//                                [_nodeClientId UTF8String],
//                                [_nodeAccessToken UTF8String]];
//                urlString = [NSString stringWithFormat:@"%s?foursquare_v2_id=%s&client_id=%s&access_token=%s",
//                     [INSTAGRAM_API_LOCATIONS_SEARCH_PREFIX UTF8String],
//                     [foursquareVenue.foursquareId UTF8String],
//                     [_nodeClientId UTF8String],
//                     [self.nodeAccessToken UTF8String]];
                $query = INSTAGRAM_API_LOCATIONS_SEARCH_PREFIX . "?foursquare_v2_id=$venueFoursquareId&client_id=$instagramDynamicOAuthClientId&access_token=$instagramDynamicOAuthAccessToken";
                curl_setopt($curl, CURLOPT_URL, $query);
                $resp = curl_exec($curl);
                $instagramApiCallCount++;
                $resp_array = json_decode($resp, TRUE);
                if (GHOST_CONSOLE_DUMP) {
                    echo "query $query returned:\r\n";
                    echo $resp . "\r\n";
                    var_dump($resp_array);
                    while (strpos($resp,'OAuthAccessTokenException') !== false) {  //<< check for bad access token
                         file_put_contents ("log", "Bad token: " , FILE_APPEND);
                         file_put_contents ("log", $instagramDynamicOAuthAccessToken, FILE_APPEND);
                         file_put_contents ("log", "\r\n" , FILE_APPEND);
                         file_put_contents ("log", "Bad token: " , FILE_APPEND);
                         file_put_contents ("log", $instagramDynamicOAuthAccessToken, FILE_APPEND);
                         file_put_contents ("log", "\r\n" , FILE_APPEND);
                        //get new access token
                        curl_setopt($curl, CURLOPT_URL, PRIMES_API_GHOST_CONFIGURATION);
                        $resp = curl_exec($curl);
                        $ghostConfig = json_decode($resp, TRUE);
                        $ghostName = $ghostConfig['name'];
                        $ghostGroupChat = $ghostConfig['groupchat'];
                        $xmppGroupChatEndpoint = "$ghostGroupChat@conference.primesengine.com";
                        $instagramDynamicOAuthAccessToken = $ghostConfig['identities'][0]['platform-credentials']['custom-access-token'];
                        //rebuild the query and execute again
                        $query = INSTAGRAM_API_LOCATIONS_SEARCH_PREFIX . "?foursquare_v2_id=$venueFoursquareId&client_id=$instagramDynamicOAuthClientId&access_token=$instagramDynamicOAuthAccessToken";
                        curl_setopt($curl, CURLOPT_URL, $query);
                        $resp = curl_exec($curl);
                        $instagramApiCallCount++;
                        $resp_array = json_decode($resp, TRUE);
                         file_put_contents ("log", "New token: " , FILE_APPEND);
                         file_put_contents ("log", $instagramDynamicOAuthAccessToken, FILE_APPEND);
                         file_put_contents ("log", "\r\n" , FILE_APPEND);
                    }
                }
                $venueInstagramId = $resp_array["data"][0]["id"];
                $query = INSTAGRAM_API_LOCATIONS_PREFIX . "/$venueInstagramId/media/recent?client_id=$instagramDynamicOAuthClientId&access_token=$instagramDynamicOAuthAccessToken";
                curl_setopt($curl, CURLOPT_URL, $query);
                $resp = curl_exec($curl);
                $instagramApiCallCount++;
                $resp_array = json_decode($resp, TRUE);
                //  Regardless of the count of media returned (even if zero), we must inform the server. This allows the server
                //  to delegate the proper response to other client nodes, with regard to whether or not a venue has stale content versus
                //  no recent content. Stale meaning: a measurement has not been made recently. 'No content' meaning: a measurement was made and no fresh content was available.
//               [[_orbitSession orbit] informRegardingMedia:recentContent error:&error];
                $query = ORBIT_API_MEDIA_INFORM_PREFIX;
                $media_set = array();
                $most_recent_media_uri = NULL;
                foreach ($resp_array["data"] as $raw_media) {
                    $platform_id = ORBIT_PLATFORM_INSTAGRAM_ID;
                    $platform_specific_id = $raw_media["id"];
                    $platform_specific_user_id = $raw_media["user"]["id"];
                    $timestamp = $raw_media["created_time"];
                    $media_uri = NULL;
                    $media_type = $raw_media["type"];
                    if($media_type == "image")
                        $media_uri = $raw_media["images"]["standard_resolution"]["url"];
                    else if($media_type == "video")
                        $media_uri = $raw_media["videos"]["standard_resolution"]["url"];
                    $orbit_venue_id = $orbitVenueId;
                    $orbit_user_id = "GHOST";
                    $media_set[] = array('platform_id' => $platform_id, 'platform_specific_id' => $platform_specific_id, 'platform_specific_user_id' => $platform_specific_user_id, 'timestamp' => $timestamp, 'media_uri' => $media_uri, 'media_type' => $media_type, 'media_resolution' => 'standard', 'orbit_venue_id' => $orbit_venue_id, 'orbit_user_id' => $orbit_user_id);
                    if($most_recent_media_uri == NULL)
                        $most_recent_media_uri = $media_uri;
                }
                $xmpp->message($xmppGroupChatEndpoint, "$query returned ".count($media_set)." pieces of media from ".$venue["name"].", with the most recent uri being $most_recent_media_uri.", "groupchat");
                curl_setopt($curl, CURLOPT_URL, $query);
                curl_setopt($curl, CURLOPT_POST, true);
                curl_setopt($curl, CURLOPT_HTTPHEADER, array("Content-Type: application/json"));
                curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($media_set));
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                $resp = curl_exec($curl);
                $orbitApiCallCount++;
                if (GHOST_CONSOLE_DUMP) {
                    echo "query $query returned:\r\n";
                    var_dump($resp);
                }
                $xmpp->message($xmppGroupChatEndpoint, "$query returned $resp.", "groupchat");
                //  Restore curl
                curl_setopt($curl, CURLOPT_POST, false);
            } else {
                $quantification = $resp_array["quantification"];
                $lastQuantification = $venueArray[$venue["name"]];
                try {
                    if ($quantification > .37 and $lastQuantification != $quantification) {
                        echo "High venue: " . strval($quantification) . " and " . $venueTitle;
                        $data = json_encode(array("alert" => $venue["name"] . " is beaming right now!", "sound" => "chime", "title" => "Very high alert", "url" => "www.google.com"));
                        $queryAndroid = ParseInstallation::query();
                        $queryAndroid->equalTo('deviceType', 'android');
                        $querySec = ParseInstallation::query();
                        $querySec->equalTo('deviceType', 'ios');
                        ParsePush::send(array(
                          "where" => $queryAndroid,
                          "data" => $data
                        ));
                        ParsePush::send(array(
                            "where" => $querySec,
                            "data" => $data
                        ));
                        $venueArray[$venue["name"]] = $quantification;
                    }
                else if ($quantification > .25 and $lastQuantification != $quantification) {
                    echo "okayish venue: " . strval($quantification) . " and " . $venueTitle;
                    $data = json_encode(array("alert" => ($venue["name"] . " is beaming right now!"), "sound" => "chime", "title" => "High alert", "url" => "www.google.com"));
                    ParsePush::send(array(
                        "channels" => "singlepoint2multipoint_" . $venueFoursquareId,
                        "data" => $data
                    ));
                    $venueArray[$venue["name"]] = $quantification;
                  }
              }
            catch (ParseException $e) {
                echo $e->getMessage();
            }
            $xmpp->message($xmppGroupChatEndpoint, $venue["name"]." already quantified within a reasonable timespan. Measurement = $quantification.", "groupchat");
            if (GHOST_CONSOLE_DUMP) {
                echo "query $title returned quantification = $quantification.\r\n";
            }
        }
    }
}
    //  Report statistics
    $unixAlgorithmTimeInterval = time() - $unixStartTime;
    $instagrapApiCallCountPerHour = ($instagramApiCallCount / $unixAlgorithmTimeInterval) * 3600;
    $foursquareApiCallCountPerHour = ($foursquareApiCallCount / $unixAlgorithmTimeInterval) * 3600;
    $orbitApiCallCountPerHour = ($orbitApiCallCount / $unixAlgorithmTimeInterval) * 3600;
    $xmpp->message($xmppGroupChatEndpoint, "$instagrapApiCallCountPerHour Instagram API calls per hour. $foursquareApiCallCountPerHour Foursquare API calls per hour. $orbitApiCallCountPerHour Orbit API calls per hour.", "groupchat");
//  Sleep for 17 minutes between each ghost iteration
    $xmpp->message($xmppGroupChatEndpoint, "Resting.", "groupchat");
    sleep(1020);
}
//  --------------------------------------------------------------------------------------------------------------------
//  Functions
//  Compliments:    http://www.geodatasource.com/developers/php
/* :::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::: */
/* ::                                                                         : */
/* ::  This routine calculates the distance between two points (given the     : */
/* ::  latitude/longitude of those points). It is being used to calculate     : */
/* ::  the distance between two locations using GeoDataSource(TM) Products    : */
/* ::                     													 : */
/* ::  Definitions:                                                           : */
/* ::    South latitudes are negative, east longitudes are positive           : */
/* ::                                                                         : */
/* ::  Passed to function:                                                    : */
/* ::    lat1, lon1 = Latitude and Longitude of point 1 (in decimal degrees)  : */
/* ::    lat2, lon2 = Latitude and Longitude of point 2 (in decimal degrees)  : */
/* ::    unit = the unit you desire for results                               : */
/* ::           where: 'M' is statute miles                                   : */
/* ::                  'K' is kilometers (default)                            : */
/* ::                  'N' is nautical miles                                  : */
/* ::  Worldwide cities and other features databases with latitude longitude  : */
/* ::  are available at http://www.geodatasource.com                          : */
/* ::                                                                         : */
/* ::  For enquiries, please contact sales@geodatasource.com                  : */
/* ::                                                                         : */
/* ::  Official Web site: http://www.geodatasource.com                        : */
/* ::                                                                         : */
/* ::         GeoDataSource.com (C) All Rights Reserved 2014		   		     : */
/* ::                                                                         : */
/* :::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::: */
function distance($lat1, $lon1, $lat2, $lon2, $unit)
{
    $theta = $lon1 - $lon2;
    $dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
    $dist = acos($dist);
    $dist = rad2deg($dist);
    $miles = $dist * 60 * 1.1515;
    $unit = strtoupper($unit);
    if ($unit == "K") {
        return ($miles * 1.609344);
    } else if ($unit == "N") {
        return ($miles * 0.8684);
    } else if ($unit == "M") {
        return ($miles * 1.609344 * 1000);
    } else {
        return $miles;
    }
}
?>
