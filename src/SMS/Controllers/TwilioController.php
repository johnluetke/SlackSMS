<?php
/**
 *
 */
namespace SMS\Controllers;

use Exception;

use Silex\Application;
use Silex\ControllerProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use Katzgrau\KLogger\Logger;
use Psr\Log\LogLevel;

use Twilio\Rest\Client;

use SMS\Config;
use SMS\SlackClient;
/**
 * HTTP Controller for Twilio-related actions
 *
 * @author John Luetk <john@johnluetke.com>
 *
 * @since 1.0.0
 */
class TwilioController implements ControllerProviderInterface {

    /**
     * @internal Reference to a Application instance
     */
    private $app;
    /**
     * @internal reference to a Logger instance
     */
    private $logger;
    /**
     * @internal reference to a SlackClient instance
     */
    private $slack;

    /**
     * Construct a new instance of this controller. Should only be used in conjunction with
     * Application\mount()
     *
     * @param SlackClient $slack
     * @param Logger $logger
     *
     * @see \Silex\Application::mount
     */
    public function __construct(SlackClient $slack, Logger $logger) {
        $this->logger = $logger;
        $this->slack = $slack;
    }

    /**
     * Automatically called when the controller is mounted.
     *
     * @param Application $app
     *
     * @return \Silex\ControllerCollection
     *
     * @see \Silex\Application::mount
     */
    public function connect(Application $app) {
        $this->app = $app;
        $controllers = $app['controllers_factory'];
        $controllers->post("/inbound", array($this, "onInboundSMS"));
        $controllers->post("/send", array($this, "sendSMS"));

        return $controllers;
    }

    /**
     * Handle and parse an inbound SMS message, and post it to Slack
     *
     * @param Request $request the inbound POST request
     *
     * @return Response an HTTP response
     */
    public function onInboundSMS(Request $request) {
        $this->logger->info(print_r($request->request, true));
        $sid = $request->request->get("AccountSid");
        $config = Config::getByTwilioSid($sid);

        if ($config == null) {
            $msg = sprintf("Unknown account SID %s. Incoming phone number %s", $sid, $request->request->get("To"));
            $this->logger->error($msg);
            return new Response($msg, 400);
        }

        $this->slack->setToken($config->slack->token);

        $from = preg_replace("/[^\d]/", "", $request->request->get("From"));
        $to = preg_replace("/[^\d]/", "", $request->request->get("To"));
        $message = $request->request->get("Body");

        $user = $this->slack->lookupUserByPhone($from);

        if ($user == null) {
            $this->logger->notice(sprintf("%s did not match a Slack user", $from));
            return new Response(sprintf("Unknown source phone number: %s", $from), 401);
        }

        $matches = [];
        preg_match("/(#[a-z0-9-_]+)/i", $message, $matches);

        $channel = $matches[0];
        $message = trim(str_replace($channel, "", $message));

        $this->slack->execute("chat.postMessage", [ "as_user" => false,
                                              "channel" => $channel, 
                                              "icon_url" => $user['profile']['image_192'],
                                              "text" => $message,
                                              "username" => $user['real_name'] . " (via SMS)" ]);

        return new Response(200);
    }

    /**
     * Send an SMS
     *
     * @param Request $request
     *
     * @return Response
     */
    public function sendSMS(Request $request) {
        $team = $request->request->get("team");
        $number = preg_replace("/[^\d]/", "", $request->request->get("number"));
        $message = $request->request->get("message");

        $config = Config::getBySlackTeamID($team);

        $twilio = new Client($config->twilio->sid, $config->twilio->token);
        $twilio->messages->create($number, array(
            "from" => $config->phone,
            "body" => $message
        ));

        return new Response("", 202);
    }
}

?>
