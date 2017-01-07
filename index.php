<?php
require_once("config.php");
require_once("vendor/autoload.php");

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

use Frlnc\Slack\Core\Commander;
use Frlnc\Slack\Http\CurlInteractor;
use Frlnc\Slack\Http\SlackResponseFactory;

use Katzgrau\KLogger\Logger;
use Psr\Log\LogLevel;

use SMS\Config;
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
        "redirect_uri" => sprintf("%s://%s%s/install", $request->getScheme(), $request->getHttpHost(), $request->getBaseUrl())
    ));
    return $app->redirect(SLACK_OAUTH_URL . "?" . $query);
}

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

$app->error(function(Exception $e, $code) use ($logger) {
    //die($request->getBaseUrl());
    $logger->error($e->getMessage());
    $logger->error($e->getTraceAsString());
    // Eventually, have a custom error page
    return $e->getMessage();
});

$app->get("/", function () {
    return "There's nothing here :-(";
});

$app->get('/install', function(Request $request) use ($app, $logger, $slack) {
    if ($request->query->has("code")) {
        $code = $request->query->get("code");
        $response = $slack->execute("oauth.access", [
                'client_id' => SLACK_CLIENT_ID,
                'client_secret' => SLACK_CLIENT_SECRET,
                'code' => $code,
                'redirect_uri' => sprintf("%s://%s%s/install", $request->getScheme(), $request->getHttpHost(), $request->getBaseUrl())
        ])->getBody();

        if (!$response['ok']) {
            throw new Exception($response['error']);
        }

        if (Config::isInstalledFor($response['team_id'])) {
            return $app->redirect(sprintf("/already-installed?team=%s", $response['team_id']));
        }
        else {
            $logger->info(sprintf("Installing for new team %s", $response['team_id']));
            $app['session']->set("token", $response['access_token']);
            $app['session']->set("team", $response['team_id']);

            $installRequest = Request::create("/install", "POST", array(
                "team_id" => $response['team_id'],
                "access_token" => $response['access_token'],
                "bot_access_token" => $response['bot']['bot_access_token'],
                "bot_user_id" => $response['bot']['bot_user_id']
            ));
            $app->handle($installRequest, HttpKernelInterface::SUB_REQUEST, false);
            return $app->redirect("/install");
        }
    }
    else {
        $token = $app['session']->get("token");
    
        if ($token === null) {
            $logger->debug("No token. Redirecting to Slack.");
            return oauth_request($app, $request, ['bot', 'commands', 'channels:write', 'groups:write', 'channels:read', 'groups:read']);
        }
        else {
            if (!Config::isInstalledFor($app['session']->get("team"))) {
                $logger->warning(sprintf("Have a token, but not installed for team %s. Reinstalling", $app['session']->get("team")));
                $app['session']->set("token", null);
                return $app->redirect("/install");
            }

            $logger->debug("Slack token possessed.");
            $slack->setToken($token);
    
            $required_scopes = ['identify', 'bot', 'commands', 'channels:write'];
            $needed_scopes = array_diff($required_scopes, $app['session']->get("scopes"));
    
            if (sizeof($needed_scopes) > 0) {
                $logger->info(sprintf("Still need scope(s): %s. Redirecting to Slack.", print_r($needed_scopes, true)));
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
    
            return $app->redirect("/welcome");
        }
    }
});

$app->post('/install', function (Request $request) use ($app, $logger, $slack) {
    $logger->notice(print_r($request, true));
    $config = Config::createConfig($request->request->get("team_id"), $request->request->get("access_token"), $request->request->get("bot_access_token"), $request->request->get("bot_user_id"));
    $config->save();

    return new Response(200);
});

$app->mount("/cmd", new SlashCommandController($slack, $logger));
$app->mount("/event", new EventController($slack, $logger));
$app->mount("/twilio", new TwilioController($slack, $logger));

$app->run();
?>
