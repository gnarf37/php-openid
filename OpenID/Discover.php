<?php

/**
 * The OpenID and Yadis discovery implementation for OpenID 1.2.
 */

require_once "Auth/OpenID.php";
require_once "Auth/OpenID/Parse.php"; // need Auth_OpenID_legacy_discover

// If the Yadis library is available, use it. Otherwise, only use
// old-style discovery.
global $_yadis_available;

$_yadis_available = false;

$try_include = @include 'Services/Yadis/Yadis.php';

if ($try_include) {
    $_yadis_available = true;
}

define('_OPENID_1_0_NS', 'http://openid.net/xmlns/1.0');
define('_OPENID_1_2_TYPE', 'http://openid.net/signon/1.2');
define('_OPENID_1_1_TYPE', 'http://openid.net/signon/1.1');
define('_OPENID_1_0_TYPE', 'http://openid.net/signon/1.0');

/**
 * Object representing an OpenID service endpoint.
 */
class Auth_OpenID_ServiceEndpoint {
    var $openid_type_uris;

    function Auth_OpenID_ServiceEndpoint()
    {
        $this->openid_type_uris = array(_OPENID_1_2_TYPE,
                                        _OPENID_1_1_TYPE,
                                        _OPENID_1_0_TYPE);

        $this->identity_url = null;
        $this->server_url = null;
        $this->type_uris = array();
        $this->delegate = null;
        $this->used_yadis = false; // whether this came from an XRDS
    }

    function usesExtension($extension_uri)
    {
        return in_array($extension_uri, $this->type_uris);
    }

    function parseService($yadis_url, $uri, $type_uris, $service_element)
    {
        // Set the state of this object based on the contents of the
        // service element.
        $this->type_uris = $type_uris;
        $this->identity_url = $yadis_url;
        $this->server_url = $uri;
        $this->delegate = findDelegate($service_element);
        $this->used_yadis = true;
    }

    function getServerID()
    {
        // Return the identifier that should be sent as the
        // openid.identity_url parameter to the server.
        if ($this->delegate === null) {
            return $this->identity_url;
        } else {
            return $this->delegate;
        }
    }

    function fromHTML($uri, $html)
    {
        // Parse the given document as HTML looking for an OpenID <link
        // rel=...>
        $urls = Auth_OpenID_legacy_discover($html);
        if ($urls === false) {
            return null;
        }

        list($delegate_url, $server_url) = $urls;
        $service = new Auth_OpenID_ServiceEndpoint();
        $service->identity_url = $uri;
        $service->delegate = $delegate_url;
        $service->server_url = $server_url;
        $service->type_uris = array(_OPENID_1_0_TYPE);
        return $service;
    }
}

function findDelegate($service)
{
    // Extract a openid:Delegate value from a Yadis Service element.
    // If no delegate is found, returns null.

    // XXX: should this die if there is more than one delegate element?
    $delegates = $service->getElements("openid:Delegate");

    if ($delegates) {
        return $delegates[0]['textParts'][0];
    } else {
        return null;
    }
}

function filter_MatchesAnyOpenIDType(&$service)
{
    $uris = $service->getTypes();

    foreach ($uris as $uri) {
        if (in_array($uri,
                     array(_OPENID_1_0_TYPE,
                           _OPENID_1_1_TYPE,
                           _OPENID_1_2_TYPE))) {
            return true;
        }
    }
    return false;
}

function Auth_OpenID_discoverWithYadis($uri)
{
    // Discover OpenID services for a URI. Tries Yadis and falls back
    // on old-style <link rel='...'> discovery if Yadis fails.

    // Might raise a yadis.discover.DiscoveryFailure if no document
    // came back for that URI at all.  I don't think falling back to
    // OpenID 1.0 discovery on the same URL will help, so don't bother
    // to catch it.
    $openid_services = array();

    $response = @Services_Yadis_Yadis::discover($uri);

    if ($response) {
        $identity_url = $response->uri;
        $openid_services =
            $response->xrds->services('filter_MatchesAnyOpenIDType');
    } else {
        return @Auth_OpenID_discoverWithoutYadis($uri,
                                                 Auth_OpenID::getHTTPFetcher());
    }

    if (!$openid_services) {
        $body = $response->body;

        // Try to parse the response as HTML to get OpenID 1.0/1.1
        // <link rel="...">
        $service = Auth_OpenID_ServiceEndpoint::fromHTML($identity_url,
                                                         $body);

        if ($service !== null) {
            $openid_services = array($service);
        }
    } else {
        $s = array();

        foreach ($openid_services as $service) {
            $type_uris = $service->getTypes();

            // If any Type URIs match and there is an endpoint URI
            // specified, then this is an OpenID endpoint
            if ($type_uris &&
                ($service->getURIs())) {
                $openid_endpoint = new Auth_OpenID_ServiceEndpoint();

                $uri = $service->getURIs();
                $uri = $uri[0];

                $openid_endpoint->parseService($response->xrds_uri,
                                               $uri,
                                               $type_uris,
                                               $service);
                $s[] = $openid_endpoint;
            }
        }

        $openid_services = $s;
    }

    return array($identity_url, $openid_services);
}

function Auth_OpenID_discoverWithoutYadis($uri, &$fetcher)
{
    $http_resp = @$fetcher->get($uri);
    list($code, $url, $body) = $http_resp;

    if ($code != 200) {
        return null;
    }

    $identity_url = $url;

    // Try to parse the response as HTML to get OpenID 1.0/1.1 <link
    // rel="...">
    $endpoint =& new Auth_OpenID_ServiceEndpoint();
    $service = $endpoint->fromHTML($identity_url, $body);
    if ($service === null) {
        $openid_services = array();
    } else {
        $openid_services = array($service);
    }

    return array($identity_url, $openid_services);
}

function Auth_OpenID_discover($uri, &$fetcher)
{
    global $_yadis_available;
    if ($_yadis_available) {
        return @Auth_OpenID_discoverWithYadis($uri);
    } else {
        return @Auth_OpenID_discoverWithoutYadis($uri, $fetcher);
    }
}

?>