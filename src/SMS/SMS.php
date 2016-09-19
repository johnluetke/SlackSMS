<?php
namespace SMS;

use Twilio\Rest\Client;
use Katzgrau\KLogger\Logger;
use Psr\Log\LogLevel;

class SMS {

    const CONFIG_FILE = "config.json";
    const LOG_DIR = "logs";

    var $team;
    var $logger;
    var $twilio;

    public static function installFor($team, $token) {
        $json = json_decode(file_get_contents(self::CONFIG_FILE));

        if (!property_exists($json, $team)) {
            $data = array(
                "slack" => array(
                    "token" => $token
                ),
                "channels" => array()
            );
            $json->{$team} = $data;
            file_put_contents(self::CONFIG_FILE, json_encode($json, JSON_PRETTY_PRINT));
        }
    }

    public static function isInstalledFor($team) {
        $json = json_decode(file_get_contents(self::CONFIG_FILE));
        return property_exists($json, $team);
    }

    /*
     *
     */
    public function __construct($data) {
        if (is_array($data)) {
            if (isset($data['team'])) {
                $team = $data['team'];
                $this->team = $team;
                $this->loadConfig();
                $this->logger = new Logger(self::LOG_DIR, LogLevel::DEBUG);
                $this->twilio = new Client($this->config->twilio->sid, $this->config->twilio->token);
            }
            else if (isset($data['phone'])) {
                $this->logger = new Logger(self::LOG_DIR, LogLevel::DEBUG);
                $this->loadConfigByPhoneNumber($data['phone']);
                $this->twilio = new Client($this->config->twilio->sid, $this->config->twilio->token);
            }
        }
        // DEPRECATED
        else if (is_string($data)) {
            $this->team = $data;
            $this->loadConfig();
            $this->logger = new Logger(self::LOG_DIR, LogLevel::DEBUG);
            $this->twilio = new Client($this->config->twilio->sid, $this->config->twilio->token);
        }
    }

    /**
     * Add a new channel
     *
     * @param string $channel the channel_id (or group_id)
     */
    public function addChannel($channel) {
        if (!$this->hasChannel($channel)) {
            $app->config->channels->{$channel} = array();
        }
    }

    public function getRecipients($channel) {
        return $this->config->channels->{$channel};
    }

    public function getTS() {
        return $this->config->last_ts;
    }

    /**
     * Gets the channel_ids and group_ids that a user is subscribed to
     *
     * @param string $user the user_id
     *
     * @return array
     */
    public function getSubscriptions($user) {
        $this->logger->debug(print_r($this->config, true));
        $this->logger->debug(print_r(func_get_args(), true));
        $channels = [];
        foreach($this->config->channels as $channel => $users) {
            if (in_array($user, $users)) {
                array_push($channels, $channel);
            }
        }

        return $channels;
    }

    public function getTeam() {
        return $this->team;
    }

    public function getToken() {
        return $this->config->slack->token;
    }

    public function hasChannel($channel) {
        return property_exists($app->config->channels, $channel);
    }

    /**
     * Determines if the given user is subscribed to the given channel
     *
     * @param string $user the user_id
     * @param string $channel the channel_id (or group_id)
     *
     * @return boolean
     */
    public function isSubscribed($user, $channel) {
        $this->logger->debug(print_r($this->config, true));
        return in_array($user, $this->config->channels->{$channel});
    }

    public function loadConfig() {
        if (!file_exists(self::CONFIG_FILE)) {
            $this->config = new \stdClass;
            $this->saveConfig();
        }

        $this->gconfig = json_decode(file_get_contents(self::CONFIG_FILE));
        $this->config = $this->gconfig->{$this->team};
    }

    private function loadConfigByPhoneNumber($phone) {
        if (!file_exists(self::CONFIG_FILE)) {
            $this->config = new \stdClass;
            $this->saveConfig();
        }

        $this->gconfig = json_decode(file_get_contents(self::CONFIG_FILE));
        $this->logger->debug(print_r($this->gconfig, true));

        foreach ($this->gconfig as $team => $config) {
            if ($config->phone == $phone) {
                $this->team = $team;
                $this->config = $config;
                $this->logger->debug(sprintf("Loaded config for team %s", $this->team));
                return;
            }
        }

        $this->logger->error(sprintf("Failed to find a config matching phone number %s", $phone));
        exit();
    }

    public function saveConfig() {
        $this->gconfig->{$this->team} = $this->config;
        file_put_contents(self::CONFIG_FILE, json_encode($this->gconfig, JSON_PRETTY_PRINT));
    }

    public function send($number, $message) {
        $number = preg_replace("/[^\d]/", "", $number);
        $this->twilio->messages->create($number, array(
            "from" => $this->config->phone,
            "body" => $message
        ));
    }

    public function setTS($ts) {
        $this->config->last_ts = intval($ts);
        $this->saveConfig();
    }

    /**
     * Susbscribes a user to a channel
     *
     * @param string $user the user_id
     * @param string $channel the channel_id (or group_id)
     *
     */
    public function subscribe($user, $channel) {
        if (!$this->isSubscribed($user, $channel)) {
            array_push($this->config->channels->{$channel}, $user);
            $this->saveConfig();
        }
    }

    public function unsubscribe($user, $channel) {
        if (($i = array_search($user, $this->config->channels->{$channel})) !== false) {
            unset($this->config->channels->{$channel}[$i]);
            $this->saveConfig();
        }
    }
}
?>
