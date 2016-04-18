<?php
/**
 * Created by PhpStorm.
 * User: tom29739
 * Date: 24/02/2016
 * Time: 20:58
 */
require __DIR__ . '/vendor/autoload.php';
use MediaWiki\OAuthClient\ClientConfig;
use MediaWiki\OAuthClient\Consumer;
use MediaWiki\OAuthClient\Client;

$endpoint = 'http://mediawiki.org/w/index.php?title=Special:OAuth';
$redir = 'http://mediawiki.org/wiki/Special:OAuth?';
$consumerKey = '40a96777692df4ea05c5c8c9f3029f76';
$consumerSecret = file_get_contents('~/secret.ini');

$conf = new ClientConfig( $endpoint );
$conf->setRedirURL( $redir );
$conf->setConsumer( new Consumer( $consumerKey, $consumerSecret ) );

$client = new Client( $conf );
$client->setCallback( 'http://tools.wmflabs.org/ircredirector/index.php' );

// Step 1 = Get a request token
list( $next, $token ) = $client->initiate();

// Step 2 - Have the user authorize your app. Get a verifier code from
// them. (if this was a webapp, you would redirect your user to $next,
// then use the 'oauth_verifier' GET parameter when the user is redirected
// back to the callback url you registered.
echo "Point your browser to: $next\n\n";
print "Enter the verification code:\n";
$fh = fopen( 'php://stdin', 'r' );
$verifyCode = trim( fgets( $fh ) );

// Step 3 - Exchange the token and verification code for an access
// token
$accessToken = $client->complete( $token,  $verifyCode );

// You're done! You can now identify the user, and/or call the API with
// $accessToken

// If we want to authenticate the user
$ident = $client->identify( $accessToken );
echo "Authenticated user {$ident->username}\n";

// Do a simple API call
echo "Getting user info: ";
echo $client->makeOAuthCall(
    $accessToken,
    'http://mediawiki.org/wiki/api.php?action=query&meta=userinfo&uiprop=rights&format=json'
);

$client->setExtraParams( $apiParams ); // sign these too

echo $client->makeOAuthCall(
    $accessToken,
    'http://mediawiki.org/wiki/api.php',
    true,
    $apiParams
);