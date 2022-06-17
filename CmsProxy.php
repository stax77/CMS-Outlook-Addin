<?php
/*
--- Cisco CMS Outlook Addin /w OBTP ---

CmsProxy.php : Server side PHP script to make REST requests to the CMS Server API, and get the default space details of a user

Initial Creator : Guillaume BRAUX (gubraux@cisco.com)
Released under the GNU General Public License v3
*/

// CONFIG -------------------------------------
include ("config.php");
error_reporting(0);
// -------------------------------------------

if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return (string)$needle !== '' && strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

// check that accessMethod or coSpace is valid base for meeting info
function isValidEntry( $entry )
{
    $uri = (string)$entry->uri[0];
    // if URI starts with 51 means personal room - use this accessMethod as a template for outlook populator
    return str_starts_with( $uri, "51");
}

function askCms( $addon_url )
{
    global $cms_api_base_url, $headers, $cms_admin_username, $cms_admin_password;

    $request = $cms_api_base_url . $addon_url;

    //Start the Curl session
    $session = curl_init($request);
    
    curl_setopt($session, CURLOPT_HEADER, ($headers == "true") ? true : false);
    curl_setopt($session, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($session, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($session, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($session, CURLOPT_USERPWD, $cms_admin_username . ":" . $cms_admin_password);
    
    $response = curl_exec($session);
    $xml = new SimpleXMLElement($response);
    curl_close($session);

    // echo $xml->asXML();

    return $xml;
}

function extractInfo( $xml )
{
    global $cms_webrtc_base_url, $phone_sda, $sip_domain;

    //Extract CMS  details from API XML Answer (from coSpace or accessMethod)
    $cms_cospace_name = (string)$xml->name[0];
    $cms_cospace_uri = (string)$xml->uri[0];
    $cms_cospace_dn = (string)$xml->callId[0];
    if ($xml->passcode[0] == null)
        $cms_cospace_pin = null;
    else
        $cms_cospace_pin = (string)$xml->passcode[0];
    $cms_cospace_secret = (string)$xml->secret[0];

    $cms_cospace_webrtc = $cms_webrtc_base_url . $cms_cospace_dn . "&secret=" . $cms_cospace_secret;

    // Build an array containing the CMS  details
    $cms_cospace_array = array(
        "cms_cospace_name" => $cms_cospace_name,
        "cms_cospace_uri" => $cms_cospace_uri.$sip_domain,
        "cms_cospace_dn" => $cms_cospace_dn,
        "cms_cospace_pin" => $cms_cospace_pin,
        "cms_cospace_webrtc" => $cms_cospace_webrtc,
        "cms_phone_sda" => $phone_sda
    );

    //
    return $cms_cospace_array;
}

// Get (from URL param) a portion of the username to search for it's default Space
$userFilter = $_GET['userFilter'];

// ---------------------- SEARCH FOR CMS USER ID ---------------------
$xml = askCms( "users?filter=" . $userFilter );
$cms_user_id = $xml->user[0]['id'];

// ---------------------- GET CMS coSpaces ---------------------
// loop over all cospaces
$xml = askCms( "users/".$cms_user_id."/usercoSpaces" );
foreach( $xml->userCoSpace as $coSpace )
{
    // get co space id
    $cms_cospace_id = $coSpace['id'];
    // get all access methods and loop over them
    $ams_xml = askCms( "/coSpaces/" . $cms_cospace_id . "/accessMethods" );
    foreach( $ams_xml->accessMethod as $accessMethod )
    {
        // if URI is valid for our purpose, use this accessMethod as a template for outlook populator
        if( isValidEntry( $accessMethod ) )
        {
            // get access method info and use it as a filler for outlook template
            $am_xml = askCms( "/coSpaces/" . $cms_cospace_id . "/accessMethods/" . $accessMethod["id"] );
            $info = extractInfo( $am_xml );
            // get cospace name and use this name as a name for outlook template (not accessMethod name, cause it contains garbage)
            $cs_xml = askCms( "cospaces/" . $cms_cospace_id );
            $info["cms_cospace_name"] = (string)$cs_xml->name[0];
            // Write the array in JSON (retreived by Addin JS)
            echo json_encode( $info );
            return;
        }
    }
}

// we are here, so no scopes with accessMethod with uri starting with 51 were found. try to analyze scope itself
foreach( $xml->userCoSpace as $coSpace )
{
    // get co space id
    $cms_cospace_id = $coSpace['id'];
    // get scope info
    $cs_xml = askCms( "cospaces/" . $cms_cospace_id );
    // if URI starts with 51 means personal room - use this accessMethod as a template for outlook populator
    if( isValidEntry( $cs_xml ) )
    {
        // populate outlook template with this space's info
        $info = extractInfo( $cs_xml );
        // Write the array in JSON (retreived by Addin JS)
        echo json_encode( $info );
        return;
    }
}

// if we still here... do nothing)
?>