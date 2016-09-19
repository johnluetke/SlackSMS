<?php
require_once("config.php");
require_once("vendor/autoload.php");

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use Frlnc\Slack\Core\Commander;
use Frlnc\Slack\Http\CurlInteractor;
use Frlnc\Slack\Http\SlackResponseFactory;

use Katzgrau\KLogger\Logger;
use Psr\Log\LogLevel;

use SMS\SMS;
use SMS\SlackClient;
use SMS\Controllers\EventController;
use SMS\Controllers\SlashCommandController;
use SMS\Controllers\TwilioController;

$app = new \Silex\Application();
$app->register(new \Silex\Provider\SessionServiceProvider());

$logger = new Logger("logs", LogLevel::INFO, array(
    "logFormat" => "[{date}]\t[{level}{level-padding}]\t{message}]"
));
$slack = new SlackClient('xoxp-no-token', $logger);


function oauth_request(Application $app, Request $request, Array $scopes) {
	$query = http_build_query(array(
		"client_id" => SLACK_CLIENT_ID,
		"scope" => implode(",", $scopes),
		"redirect_uri" => sprintf("%s://%s%s", $request->getScheme(), $request->getHttpHost(), $request->getBaseUrl())
	));
	return $app->redirect(SLACK_OAUTH_URL . "?" . $query);
}

$authenticate = function(Request $request) use ($app, $slack, $logger) {
	$token = $app['session']->get("token");

	if (isset($_REQUEST['code'])) {
		$code = $_REQUEST['code'];
		$response = $slack->execute("oauth.access", [
	        	'client_id' => SLACK_CLIENT_ID,
	        	'client_secret' => SLACK_CLIENT_SECRET,
	        	'code' => $code,
        		'redirect_uri' => sprintf("%s://%s%s", $request->getScheme(), $request->getHttpHost(), $request->getBaseUrl())
		])->getBody();

		if (!$response['ok']) {
			throw new Exception($response['error']);
		}
        else {
            $logger->debug(print_r($response, true));
            $logger->info(sprintf("Installing for new team %s", $team));
            SMS::installFor($response['team_id'], $response['bot']['bot_access_token']);

			return $app->redirect(sprintf("%s://%s%s", $request->getScheme(), $request->getHttpHost(), $request->getBaseUrl()));
		}
	}

	if ($token === null) {
		return oauth_request($app, $request, ['bot', 'commands']);
	}
	else {
		$slack->setToken($token);

		$required_scopes = ['identify', 'bot', 'commands'];
		$needed_scopes = array_diff($required_scopes, $app['session']->get("scopes"));

		if (sizeof($needed_scopes) > 0) {
			return oauth_request($app, $request, $needed_scopes);
		}

		if ($app['session']->get("name") === null) {
			$response = $slack->execute("users.identity")->getBody();
	
			if (!$response['ok']) {
				throw new Exception($response['error']);
			}
			else {
				$app['session']->set("uid", $response['user']['id']);
				$app['session']->set("name", $response['user']['name']);
			}
		}
	}
};

$validate = function(Request $request) use ($app, $logger) {
    if ($request->request->get("token") !== SLACK_COMMAND_TOKEN) {
        $logger->error(sprintf("Invalid command token %s", $request->request->get("token")));
        return $app->abort(400);
    }

    if (!SMS::isInstalledFor($request->request->get("team_id"))) {
        $logger->error(sprintf("Team %s not configured to use app", $request->request->get("team_id")));
        $app->abort(400);
    }
};

$app->error(function(Exception $e, $code) {
    //die($request->getBaseUrl());
	// Eventually, have a custom error page
	return $e->getMessage();
});

$app->get('/', function () use ($app, $slack) {
    die("get /");
})->before($authenticate);

$app->mount("/cmd", new SlashCommandController($slack, $logger));
$app->mount("/event", new EventController($slack, $logger));
$app->mount("/twilio", new TwilioController($slack, $logger));

$app->run();
?>
