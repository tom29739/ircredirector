<?php
/**
 * Written in 2013 by Brad Jorsch
 *
 * To the extent possible under law, the author(s) have dedicated all copyright 
 * and related and neighboring rights to this software to the public domain 
 * worldwide. This software is distributed without any warranty. 
 *
 * See <http://creativecommons.org/publicdomain/zero/1.0/> for a copy of the 
 * CC0 Public Domain Dedication.
 */

// ******************** CONFIGURATION ********************

/**
 * Set this to point to a file (outside the webserver root!) containing the 
 * following keys:
 * - agent: The HTTP User-Agent to use
 * - consumerKey: The "consumer token" given to you when registering your app
 * - consumerSecret: The "secret token" given to you when registering your app
 */
$inifile = '/data/project/ircredirector/oauth.ini';

/**
 * Set this to the Special:OAuth/authorize URL. 
 * To work around MobileFrontend redirection, use /wiki/ rather than /w/index.php.
 */
$mwOAuthAuthorizeUrl = 'https://www.mediawiki.org/wiki/Special:OAuth/authorize';

/**
 * Set this to the Special:OAuth URL. 
 * Note that /wiki/Special:OAuth fails when checking the signature, while
 * index.php?title=Special:OAuth works fine.
 */
$mwOAuthUrl = 'https://www.mediawiki.org/w/index.php?title=Special:OAuth';

/**
 * Set this to the interwiki prefix for the OAuth central wiki.
 */
$mwOAuthIW = 'meta';

/**
 * Set this to the API endpoint
 */
$apiUrl = 'https://test.wikipedia.org/w/api.php';

/**
 * Set this to Special:MyTalk on the above wiki
 */
$mytalkUrl = 'https://test.wikipedia.org/wiki/Special:MyTalk#Hello.2C_world';

/**
 * This should normally be "500". But Tool Labs insists on overriding valid 500
 * responses with a useless error page.
 */
$errorCode = 200;

// ****************** END CONFIGURATION ******************

// Setup the session cookie
session_name( 'OAuthHelloWorld' );
$params = session_get_cookie_params();
session_set_cookie_params(
	$params['lifetime'],
	dirname( $_SERVER['SCRIPT_NAME'] )
);


// Read the ini file
$ini = parse_ini_file( $inifile );
if ( $ini === false ) {
	header( "HTTP/1.1 $errorCode Internal Server Error" );
	echo 'The ini file could not be read';
	exit(0);
}
if ( !isset( $ini['agent'] ) ||
	!isset( $ini['consumerKey'] ) ||
	!isset( $ini['consumerSecret'] )
) {
	header( "HTTP/1.1 $errorCode Internal Server Error" );
	echo 'Required configuration directives not found in ini file';
	exit(0);
}
$gUserAgent = $ini['agent'];
$gConsumerKey = $ini['consumerKey'];
$gConsumerSecret = $ini['consumerSecret'];

// Load the user token (request or access) from the session
$gTokenKey = '';
$gTokenSecret = '';
session_start();
if ( isset( $_SESSION['tokenKey'] ) ) {
	$gTokenKey = $_SESSION['tokenKey'];
	$gTokenSecret = $_SESSION['tokenSecret'];
}
session_write_close();

// Fetch the access token if this is the callback from requesting authorization
if ( isset( $_GET['oauth_verifier'] ) && $_GET['oauth_verifier'] ) {
	fetchAccessToken();
}

// Take any requested action
switch ( isset( $_GET['action'] ) ? $_GET['action'] : '' ) {
	case 'download':
		header( 'Content-Type: text/plain' );
		readfile( __FILE__ );
		return;

	case 'authorize':
		doAuthorizationRedirect();
		return;

	case 'edit':
		doEdit();
		break;

	case 'identify':
		doIdentify();
		break;

	case 'testspecial':
		doTestSpecial();
		break;
}


// ******************** CODE ********************


/**
 * Utility function to sign a request
 *
 * Note this doesn't properly handle the case where a parameter is set both in 
 * the query string in $url and in $params, or non-scalar values in $params.
 *
 * @param string $method Generally "GET" or "POST"
 * @param string $url URL string
 * @param array $params Extra parameters for the Authorization header or post 
 * 	data (if application/x-www-form-urlencoded).
 * @return string Signature
 */
function sign_request( $method, $url, $params = array() ) {
	global $gConsumerSecret, $gTokenSecret;

	$parts = parse_url( $url );

	// We need to normalize the endpoint URL
	$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'http';
	$host = isset( $parts['host'] ) ? $parts['host'] : '';
	$port = isset( $parts['port'] ) ? $parts['port'] : ( $scheme == 'https' ? '443' : '80' );
	$path = isset( $parts['path'] ) ? $parts['path'] : '';
	if ( ( $scheme == 'https' && $port != '443' ) ||
		( $scheme == 'http' && $port != '80' ) 
	) {
		// Only include the port if it's not the default
		$host = "$host:$port";
	}

	// Also the parameters
	$pairs = array();
	parse_str( isset( $parts['query'] ) ? $parts['query'] : '', $query );
	$query += $params;
	unset( $query['oauth_signature'] );
	if ( $query ) {
		$query = array_combine(
			// rawurlencode follows RFC 3986 since PHP 5.3
			array_map( 'rawurlencode', array_keys( $query ) ),
			array_map( 'rawurlencode', array_values( $query ) )
		);
		ksort( $query, SORT_STRING );
		foreach ( $query as $k => $v ) {
			$pairs[] = "$k=$v";
		}
	}

	$toSign = rawurlencode( strtoupper( $method ) ) . '&' .
		rawurlencode( "$scheme://$host$path" ) . '&' .
		rawurlencode( join( '&', $pairs ) );
	$key = rawurlencode( $gConsumerSecret ) . '&' . rawurlencode( $gTokenSecret );
	return base64_encode( hash_hmac( 'sha1', $toSign, $key, true ) );
}

/**
 * Request authorization
 * @return void
 */
function doAuthorizationRedirect() {
	global $mwOAuthUrl, $mwOAuthAuthorizeUrl, $gUserAgent, $gConsumerKey, $gTokenSecret;

	// First, we need to fetch a request token.
	// The request is signed with an empty token secret and no token key.
	$gTokenSecret = '';
	$url = $mwOAuthUrl . '/initiate';
	$url .= strpos( $url, '?' ) ? '&' : '?';
	$url .= http_build_query( array(
		'format' => 'json',
		
		// OAuth information
		'oauth_callback' => 'oob', // Must be "oob" for MWOAuth
		'oauth_consumer_key' => $gConsumerKey,
		'oauth_version' => '1.0',
		'oauth_nonce' => md5( microtime() . mt_rand() ),
		'oauth_timestamp' => time(),

		// We're using secret key signatures here.
		'oauth_signature_method' => 'HMAC-SHA1',
	) );
	$signature = sign_request( 'GET', $url );
	$url .= "&oauth_signature=" . urlencode( $signature );
	$ch = curl_init();
	curl_setopt( $ch, CURLOPT_URL, $url );
	//curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
	curl_setopt( $ch, CURLOPT_USERAGENT, $gUserAgent );
	curl_setopt( $ch, CURLOPT_HEADER, 0 );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
	$data = curl_exec( $ch );
	if ( !$data ) {
		header( "HTTP/1.1 $errorCode Internal Server Error" );
		echo 'Curl error: ' . htmlspecialchars( curl_error( $ch ) );
		exit(0);
	}
	curl_close( $ch );
	$token = json_decode( $data );
	if ( is_object( $token ) && isset( $token->error ) ) {
		header( "HTTP/1.1 $errorCode Internal Server Error" );
		echo 'Error retrieving token: ' . htmlspecialchars( $token->error );
		exit(0);
	}
	if ( !is_object( $token ) || !isset( $token->key ) || !isset( $token->secret ) ) {
		header( "HTTP/1.1 $errorCode Internal Server Error" );
		echo 'Invalid response from token request';
		exit(0);
	}

	// Now we have the request token, we need to save it for later.
	session_start();
	$_SESSION['tokenKey'] = $token->key;
	$_SESSION['tokenSecret'] = $token->secret;
	session_write_close();

	// Then we send the user off to authorize
	$url = $mwOAuthAuthorizeUrl;
	$url .= strpos( $url, '?' ) ? '&' : '?';
	$url .= http_build_query( array(
		'oauth_token' => $token->key,
		'oauth_consumer_key' => $gConsumerKey,
	) );
	header( "Location: $url" );
	echo 'Please see <a href="' . htmlspecialchars( $url ) . '">' . htmlspecialchars( $url ) . '</a>';
}

/**
 * Handle a callback to fetch the access token
 * @return void
 */
function fetchAccessToken() {
	global $mwOAuthUrl, $gUserAgent, $gConsumerKey, $gTokenKey, $gTokenSecret;

	$url = $mwOAuthUrl . '/token';
	$url .= strpos( $url, '?' ) ? '&' : '?';
	$url .= http_build_query( array(
		'format' => 'json',
		'oauth_verifier' => $_GET['oauth_verifier'],

		// OAuth information
		'oauth_consumer_key' => $gConsumerKey,
		'oauth_token' => $gTokenKey,
		'oauth_version' => '1.0',
		'oauth_nonce' => md5( microtime() . mt_rand() ),
		'oauth_timestamp' => time(),

		// We're using secret key signatures here.
		'oauth_signature_method' => 'HMAC-SHA1',
	) );
	$signature = sign_request( 'GET', $url );
	$url .= "&oauth_signature=" . urlencode( $signature );
	$ch = curl_init();
	curl_setopt( $ch, CURLOPT_URL, $url );
	//curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
	curl_setopt( $ch, CURLOPT_USERAGENT, $gUserAgent );
	curl_setopt( $ch, CURLOPT_HEADER, 0 );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
	$data = curl_exec( $ch );
	if ( !$data ) {
		header( "HTTP/1.1 $errorCode Internal Server Error" );
		echo 'Curl error: ' . htmlspecialchars( curl_error( $ch ) );
		exit(0);
	}
	curl_close( $ch );
	$token = json_decode( $data );
	if ( is_object( $token ) && isset( $token->error ) ) {
		header( "HTTP/1.1 $errorCode Internal Server Error" );
		echo 'Error retrieving token: ' . htmlspecialchars( $token->error );
		exit(0);
	}
	if ( !is_object( $token ) || !isset( $token->key ) || !isset( $token->secret ) ) {
		header( "HTTP/1.1 $errorCode Internal Server Error" );
		echo 'Invalid response from token request';
		exit(0);
	}

	// Save the access token
	session_start();
	$_SESSION['tokenKey'] = $gTokenKey = $token->key;
	$_SESSION['tokenSecret'] = $gTokenSecret = $token->secret;
	session_write_close();
}


/**
 * Send an API query with OAuth authorization
 *
 * @param array $post Post data
 * @param object $ch Curl handle
 * @return array API results
 */
function doApiQuery( $post, &$ch = null ) {
	global $apiUrl, $gUserAgent, $gConsumerKey, $gTokenKey;

	$headerArr = array(
		// OAuth information
		'oauth_consumer_key' => $gConsumerKey,
		'oauth_token' => $gTokenKey,
		'oauth_version' => '1.0',
		'oauth_nonce' => md5( microtime() . mt_rand() ),
		'oauth_timestamp' => time(),

		// We're using secret key signatures here.
		'oauth_signature_method' => 'HMAC-SHA1',
	);
	$signature = sign_request( 'POST', $apiUrl, $post + $headerArr );
	$headerArr['oauth_signature'] = $signature;

	$header = array();
	foreach ( $headerArr as $k => $v ) {
		$header[] = rawurlencode( $k ) . '="' . rawurlencode( $v ) . '"';
	}
	$header = 'Authorization: OAuth ' . join( ', ', $header );

	if ( !$ch ) {
		$ch = curl_init();
	}
	curl_setopt( $ch, CURLOPT_POST, true );
	curl_setopt( $ch, CURLOPT_URL, $apiUrl );
	curl_setopt( $ch, CURLOPT_POSTFIELDS, http_build_query( $post ) );
	curl_setopt( $ch, CURLOPT_HTTPHEADER, array( $header ) );
	//curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
	curl_setopt( $ch, CURLOPT_USERAGENT, $gUserAgent );
	curl_setopt( $ch, CURLOPT_HEADER, 0 );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
	$data = curl_exec( $ch );
	if ( !$data ) {
		header( "HTTP/1.1 $errorCode Internal Server Error" );
		echo 'Curl error: ' . htmlspecialchars( curl_error( $ch ) );
		exit(0);
	}
	$ret = json_decode( $data );
	if ( $ret === null ) {
		header( "HTTP/1.1 $errorCode Internal Server Error" );
		echo 'Unparsable API response: <pre>' . htmlspecialchars( $data ) . '</pre>';
		exit(0);
	}
	return $ret;
}

/**
 * Perform a generic edit
 * @return void
 */
function doEdit() {
	global $mwOAuthIW;

	$ch = null;

	// First fetch the username
	$res = doApiQuery( array(
		'format' => 'json',
		'action' => 'query',
		'meta' => 'userinfo',
	), $ch );

	if ( isset( $res->error->code ) && $res->error->code === 'mwoauth-invalid-authorization' ) {
		// We're not authorized!
		echo 'You haven\'t authorized this application yet! Go <a href="' . htmlspecialchars( $_SERVER['SCRIPT_NAME'] ) . '?action=authorize">here</a> to do that.';
		echo '<hr>';
		return;
	}

//	if ( !isset( $res->query->userinfo ) ) {
//		header( "HTTP/1.1 $errorCode Internal Server Error" );
//		echo 'Bad API response: <pre>' . htmlspecialchars( var_export( $res, 1 ) ) . '</pre>';
//		exit(0);
//	}
	if ( isset( $res->query->userinfo->anon ) ) {
		header( "HTTP/1.1 $errorCode Internal Server Error" );
		echo 'Not logged in. (How did that happen?)';
		exit(0);
	}
	$page = 'User talk:' . $res->query->userinfo->name;

	// Now perform the edit
	$res = header('Location: http://www.example.com/$page');
		exit(0);

	echo 'API edit result: <pre>' . htmlspecialchars( var_export( $res, 1 ) ) . '</pre>';
	echo '<hr>';
}

/**
 * Request a JWT and verify it
 * @return void
 */
function doIdentify() {
	global $mwOAuthUrl, $gUserAgent, $gConsumerKey, $gTokenKey, $gConsumerSecret;

	$url = $mwOAuthUrl . '/identify';
	$headerArr = array(
		// OAuth information
		'oauth_consumer_key' => $gConsumerKey,
		'oauth_token' => $gTokenKey,
		'oauth_version' => '1.0',
		'oauth_nonce' => md5( microtime() . mt_rand() ),
		'oauth_timestamp' => time(),

		// We're using secret key signatures here.
		'oauth_signature_method' => 'HMAC-SHA1',
	);
	$signature = sign_request( 'GET', $url, $headerArr );
	$headerArr['oauth_signature'] = $signature;

	$header = array();
	foreach ( $headerArr as $k => $v ) {
		$header[] = rawurlencode( $k ) . '="' . rawurlencode( $v ) . '"';
	}
	$header = 'Authorization: OAuth ' . join( ', ', $header );

	$ch = curl_init();
	curl_setopt( $ch, CURLOPT_URL, $url );
	curl_setopt( $ch, CURLOPT_HTTPHEADER, array( $header ) );
	//curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
	curl_setopt( $ch, CURLOPT_USERAGENT, $gUserAgent );
	curl_setopt( $ch, CURLOPT_HEADER, 0 );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
	$data = curl_exec( $ch );
	if ( !$data ) {
		header( "HTTP/1.1 $errorCode Internal Server Error" );
		echo 'Curl error: ' . htmlspecialchars( curl_error( $ch ) );
		exit(0);
	}
	$err = json_decode( $data );
	if ( is_object( $err ) && isset( $err->error ) && $err->error === 'mwoauthdatastore-access-token-not-found' ) {
		// We're not authorized!
		echo 'You haven\'t authorized this application yet! Go <a href="' . htmlspecialchars( $_SERVER['SCRIPT_NAME'] ) . '?action=authorize">here</a> to do that.';
		echo '<hr>';
		return;
	}

	// There are three fields in the response
	$fields = explode( '.', $data );
	if ( count( $fields ) !== 3 ) {
		header( "HTTP/1.1 $errorCode Internal Server Error" );
		echo 'Invalid identify response: ' . htmlspecialchars( $data );
		exit(0);
	}

	// Validate the header. MWOAuth always returns alg "HS256".
	$header = base64_decode( strtr( $fields[0], '-_', '+/' ), true );
	if ( $header !== false ) {
		$header = json_decode( $header );
	}
	if ( !is_object( $header ) || $header->typ !== 'JWT' || $header->alg !== 'HS256' ) {
		header( "HTTP/1.1 $errorCode Internal Server Error" );
		echo 'Invalid header in identify response: ' . htmlspecialchars( $data );
		exit(0);
	}

	// Verify the signature
	$sig = base64_decode( strtr( $fields[2], '-_', '+/' ), true );
	$check = hash_hmac( 'sha256', $fields[0] . '.' . $fields[1], $gConsumerSecret, true );
	if ( $sig !== $check ) {
		header( "HTTP/1.1 $errorCode Internal Server Error" );
		echo 'JWT signature validation failed: ' . htmlspecialchars( $data );
		echo '<pre>'; var_dump( base64_encode($sig), base64_encode($check) ); echo '</pre>';
		exit(0);
	}

	// Decode the payload
	$payload = base64_decode( strtr( $fields[1], '-_', '+/' ), true );
	if ( $payload !== false ) {
		$payload = json_decode( $payload );
	}
	if ( !is_object( $payload ) ) {
		header( "HTTP/1.1 $errorCode Internal Server Error" );
		echo 'Invalid payload in identify response: ' . htmlspecialchars( $data );
		exit(0);
	}

	echo 'JWT payload: <pre>' . htmlspecialchars( var_export( $payload, 1 ) ) . '</pre>';
	echo '<hr>';
}

function doTestSpecial() {
	global $mwOAuthUrl, $gUserAgent, $gConsumerKey, $gTokenKey, $gConsumerSecret;

	$url = str_replace( 'Special:OAuth', 'Special:MyPage', $mwOAuthUrl );
	$headerArr = array(
		// OAuth information
		'oauth_consumer_key' => $gConsumerKey,
		'oauth_token' => $gTokenKey,
		'oauth_version' => '1.0',
		'oauth_nonce' => md5( microtime() . mt_rand() ),
		'oauth_timestamp' => time(),

		// We're using secret key signatures here.
		'oauth_signature_method' => 'HMAC-SHA1',
	);
	$signature = sign_request( 'GET', $url, $headerArr );
	$headerArr['oauth_signature'] = $signature;

	$header = array();
	foreach ( $headerArr as $k => $v ) {
		$header[] = rawurlencode( $k ) . '="' . rawurlencode( $v ) . '"';
	}
	$header = 'Authorization: OAuth ' . join( ', ', $header );

	$ch = curl_init();
	curl_setopt( $ch, CURLOPT_URL, $url );
	curl_setopt( $ch, CURLOPT_HTTPHEADER, array( $header ) );
	//curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
	curl_setopt( $ch, CURLOPT_USERAGENT, $gUserAgent );
	curl_setopt( $ch, CURLOPT_HEADER, 1 );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
	curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, 0 );
	$data = curl_exec( $ch );
	if ( !$data ) {
		header( "HTTP/1.1 $errorCode Internal Server Error" );
		echo 'Curl error: ' . htmlspecialchars( curl_error( $ch ) );
		exit(0);
	}

	echo 'Redirect response from Special:MyPage: <pre>' . htmlspecialchars( $data ) . '</pre>';
	echo '<hr>';
}

// ******************** WEBPAGE ********************

?><!DOCTYPE html>
<html lang="en" dir="ltr">
 <head>
  <meta charset="UTF-8" />
  <title>OAuth Hello World!</title>
 </head>
 <body>
<p>This is a very simple "<a href="//en.wikipedia.org/wiki/Hello_world_program">Hello world</a>" program to show how to use OAuth. If you so desire, you may <a href="<?php echo htmlspecialchars( $_SERVER['SCRIPT_NAME'] );?>?action=download">download this file</a>. For a more end-user friendly version, look at <a href="enduser.php">enduser.php</a>.</p>

<h2>Overview</h2>
<p>OAuth is a method for your application to act on behalf of a user on a website, without having to know the user's username and password. First your application is regisetered with the website, then you send the user to a special page on the website where they give your application permission, and then you provide special HTTP headers when accessing the website.</p>

<h2>Creating your consumer</h2>
<p>To be able to use OAuth in your application, you first need to register it as a consumer. To do this, you visit Special:OAuthConsumerRegistration on the OAuth central wiki. For WMF wikis, this is currently <a href="https://www.mediawiki.org/wiki/Special:OAuthConsumerRegistration/propose">mediawiki.org</a>, but will likely change to Meta once OAuth is fully deployed.</p>
<p>On this page, you will fill out information required by your application. Most of the fields are straightforward. Of the rest:</p>
<ul>
 <li>OAuth "callback" URL: After the user authorizes the application, their browser will be sent to this URL. It will be given two parameters, <code>oauth_verifier</code> and <code>oauth_token</code>, which your application will need in order to complete the authorization process.</li>
 <li>Applicable wiki: If your app is only for use in one wiki, specify the wiki id here (this may be retrieved from the API with <code>action=query&amp;meta=siteinfo</code>). If your app is for use on all wikis, specify "*" (without the quotes).</li>
 <li>Applicable grants: Check the checkbox for the grants that provide the rights your application needs. Note that "Basic rights" is almost certainly required, and that even if your application specifies advanced rights such as "Delete pages" your application will still not be able to delete pages on behalf of users who don't already have the delete right.</li>
 <li>Usage restrictions (JSON): This can be used to limit usage of your application, e.g. to certain IP addresses. The default value should be fine.</li>
 <li>Public RSA key: OAuth requires that requests be signed; this can be done by using a shared secret, or by using <a href="https://en.wikipedia.org/wiki/Public-key_cryptography">public-key cryptography</a>. If you want to use the latter, provide a public key here.</li>
</ul>
<p>After submitting your registration request, you will be returned a "consumer token" and a "secret token". In this Hello world program, these go in your ini file as consumerKey and consumerSecret. Note you can later update the Usage restrictions and Public RSA key, and can reset the secret token.</p>
<p>Your application must then be approved by someone with the "mwoauthmanageconsumer" user right.</p>

<h2>Authorizing a user</h2>
<p>When a new user wishes to use your application, they must first authorize it. You do this by making a call to Special:OAuth/initiate to get a request token, then send the user to Special:OAuth/authorize. If the user authorizes your app, the user will be redirected back to your callback URL with the <code>oauth_verifier</code> parameter set; you then call Special:OAuth/token to fetch the access token.</p>

<h2>Deauthorizing a user</h2>
<p>A user may revoke the authorization for the application by visiting Special:OAuthManageMyGrants on the OAuth central wiki.</p>

<h2>Identifying a user</h2>
<p>OAuth itself doesn't provide any way to reliably identify a user to the consumer. The MediaWiki OAuth extension can be queried for a JSON Web Token attesting to the identity of the authorized user.</p>

<h2>Try it out!</h2>
<ul>
 <li><a href="<?php echo htmlspecialchars( $_SERVER['SCRIPT_NAME'] );?>?action=authorize">Authorize this application</a></li>
 <li><a href="<?php echo htmlspecialchars( $_SERVER['SCRIPT_NAME'] );?>?action=edit">Post to your talk page</a></li>
 <li><a href="<?php echo htmlspecialchars( $_SERVER['SCRIPT_NAME'] );?>?action=identify">Verify your identity</a></li>
 <li><a href="<?php echo htmlspecialchars( $mytalkUrl );?>">Visit your talk page</a></li>
</ul>

</body>
</html>

