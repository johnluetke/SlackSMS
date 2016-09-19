<?php
namespace SMS\Controllers;

use Exception;

use Silex\Application;
use Silex\ControllerProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;

use Katzgrau\KLogger\Logger;
use Psr\Log\LogLevel;

use SMS\Config;
use SMS\SlackClient;

class SlashCommandController implements ControllerProviderInterface {

    private $app;
    private $logger;
    private $slack;

    public function __construct(SlackClient $slack, Logger $logger) {
        $this->logger = $logger;
        $this->slack = $slack;
    }

    public function connect(Application $app) {
        $this->app = $app;
        $controllers = $app['controllers_factory'];
        $controllers->post("/sms", array($this, "onSMSCommand"));

        return $controllers;
    }

    public function onSMSCommand(Request $request) {
        $this->logger->debug(print_r($request->request, true));
        $team = $request->request->get("team_id");
        $user = $request->request->get("user_id");
        $channel = $request->request->get("channel_id");
        $cmd = $request->request->get("command");
        $args = explode(" ", $request->request->get("text"));

        $config = Config::getBySlackTeamID($team);

        if ($config == null) {
            $msg = sprintf("Unknown team ID %s.", $team);
            $this->logger->error($msg);
            return new Response($msg, 400);
        }

        $this->slack->setToken($config->slack->token);
        $this->logger->info(sprintf("Command received is: %s [%s]", $cmd, implode($args, ",")));

        if ($cmd !== "/sms") {
            $msg = "Command mismatch. Expected /sms";
            $this->error($msg);
            return new Response($msg, 400);
        }

        $subcmd = (sizeof($args) > 0) ? $args[0] : "subscribe";

        switch ($subcmd) {
            case "help":
                return $this->onSMSCommandHelp($user, $team);
                break;
            case "info":
                return $this->onSMSCommandInfo($config, $user, $team);
                break;
            case "stop":
            case "unsubscribe":
                return $this->onSMSCommandUnsubscribe($config, $user, $channel, $team);
                break;
            case "subscribe":
            default:
                return $this->onSMSCommandSubscribe($config, $user, $channel, $team);
                break;
        }
    }

    private function onSMSCommandHelp($user, $team) {
        $this->logger->info(sprintf("Showing help for %s on %s", $user, $team));

        return new JsonResponse(array(
            "text" => sprintf("`/sms subscribe` will subscribe you to SMS messages from this channel (default)\r\n" .
                    "`/sms info` will show you what channels you are receiving SMS messages from\r\n" .
                    "`/sms stop` will stop sending you SMS messages from this channel")
        ));
    }

    private function onSMSCommandInfo($config, $user, $team) {
        $this->logger->info(sprintf("Showing subscriptions for %s on %s", $user, $team));

        $channels = $config->getSubscriptions($user);
        $c = [];
        foreach ($channels as $channel) {
            array_push($c, $this->slack->getChannelName($channel));
        }

        if (sizeof($c) > 0) {
            sort($c);
            return new JsonResponse(array(
                "text" => sprintf("You are receiving SMS messages from: #%s", implode($c, ", #"))
            ));
        }
        else {
            return new JsonResponse(array(
                "text" => "You are not receiving SMS messages from any channel."
            ));
        }
    }

    private function onSMSCommandSubscribe($config, $user, $channel, $team) {
        $this->logger->info(sprintf("Subscribing %s to %s on %s", $user, $channel, $team));

        if (!$this->slack->hasPhoneNumber($user)) {
            $this->logger->error(sprintf("%s on %s does not have a phone number set", $user, $team));
            return new JsonResponse(array(
                "text" => "Oops! It doesn't look like you have a phone number set in your Slack profile."
            ));
        }

        if ($config->hasChannel($channel)) {
            $config->addChannel($channel);
        }

        if ($config->isSubscribed($user, $channel)) {
            $this->logger->notice(sprintf("%s is already subscribed to %s on %s", $user, $channel, $team));
            return new JsonResponse(array(
                "text" => "You are already receiving SMS messages from this channel. To stop receiving messages, use `/sms stop`"
            ));
        }

        $config->subscribe($user, $channel);

        return new JsonResponse(array(
            "text" => sprintf("Okay! I will send an SMS message to %s for each message sent to this channel.", $this->slack->getPhoneNumber($user))
        ));

    }

    private function onSMSCommandUnsubscribe($config, $user, $channel, $team) {
        $this->logger->info(sprintf("Unsubscribing %s from %s on %s", $user, $channel, $team));
        if ($config->isSubscribed($user, $channel)) {
            $config->unsubscribe($user, $channel);

            return new JsonResponse(array(
                "text" => "You will no longer receive SMS from this channel."
            ));
        }
        else {
            return new JsonResponse(array(
                "text" => "You are not receiving SMS from this channel."
            ));
        }
    } 
}
?>
