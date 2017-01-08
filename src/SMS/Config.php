<?php
/**
 * 
 */
namespace SMS;

use \Curl\Curl;
use \Ramsey\Uuid\Uuid;

use Exception;

/**
 * Configuration class.
 *
 * Static methods are used to retrieve configuration, based on different pieces of information available. Static
 * methods return an instance of the Config class.
 *
 * @author John Luetke <john@johnluetke.com>
 *
 * @since 1.0.0
 */
class Config {

    public static function isInstalledFor($team) {
        $url = sprintf(COUCHDB_URL, "_design/config/_view/team?key=%s");
        $search = sprintf($url, json_encode($team));

        $curl = new Curl();
        $curl->setHeader('Content-Type', 'application/json');
        $curl->setBasicAuthentication(COUCHDB_USERNAME, COUCHDB_PASSWORD);
        $response = json_decode($curl->get($search));

        return sizeof($response->rows) == 1;
    }

    public static function createConfig($team, $token, $bot_token, $bot_user) {
        if (!Config::isInstalledFor($team)) {
            $data = array(
                "team" => $team,
                "slack" => array(
                    "auth_token" => $token,
                    "token" => $bot_token,
                    "bot_user" => $bot_user
                ),
                "service" => null,
                "phone" => null,
                "last_ts" => null,
                "channels" => array()
            );

            $url = sprintf(COUCHDB_URL, Uuid::uuid4()->toString());
            $curl = new Curl();
            $curl->setHeader('Content-Type', 'application/json');
            $response = json_decode($curl->put($url, json_encode($data)));

            $data['_id'] = $response->id;
            $data['_rev'] = $response->rev;

            return new Config($team, $data);
        }
        else {
            $c = Config::getBySlackTeamID($team);
            $c->slack->token = $bot_token;
            $c->slack->bot_user = $bot_user;
            $c->slack->auth_token = $token;
            return new Config($team, $c);
        }
    }


    /**
     * Retrieve configuration from a Slack Team ID
     *
     * @param string $team the Team ID
     *
     * @return Config
     */
    public static function getBySlackTeamID($team) {
        $url = sprintf(COUCHDB_URL, "_design/config/_view/team?key=%s");
        $search = sprintf($url, json_encode($team));

        $curl = new Curl();
        $curl->setHeader('Content-Type', 'application/json');
        $curl->setBasicAuthentication(COUCHDB_USERNAME, COUCHDB_PASSWORD);
        $response = json_decode($curl->get($search));

        if (sizeof($response->rows) == 1) {
            $config = $response->rows[0]->value;
            return new Config($team, $config);
        }

        return null;
    }

    /**
     * Retrieve configuration from a Twilio SID
     * 
     * @param string $sid a Twilio SID
     *
     * @return Config
     */
    public static function getByTwilioSid($sid) {
        $url = sprintf(COUCHDB_URL, "_design/config/_view/twilio?key=%s");
        $search = sprintf($url, json_encode($sid));

        $curl = new Curl();
        $curl->setHeader('Content-Type', 'application/json');
        $curl->setBasicAuthentication(COUCHDB_USERNAME, COUCHDB_PASSWORD);
        $response = json_decode($curl->get($search));

        if (sizeof($response->rows) == 1) {
            $config = $response->rows[0]->value;
            return new Config($config->team, $config);
        }

        return null;
    }

    /**
     * @ignore
     */
    private $key;

    /**
     * Create a Config object.
     *
     * @param string $key a key for referencing this Config globally
     * @param StdClass $obj a StdClass containing configuration properties
     *
     * @internal
     */
    private function __construct($key, $obj) {
        $this->key = $key;

        if (is_object($obj) || is_array($obj)) {
            foreach ($obj as $property => $value) {
                $this->{$property} = $value;
            }
        }
    }

    /**
     * Adds the channel to the configuration
     *
     * @param string $channel the channel or group id
     */
    public function addChannel($channel) {
        if (!$this->hasChannel($channel)) {
            $this->channels->{$channel} = array();
            $this->save();
        }
    }

    /**
     * Retrieve the receipiets of messages to a channel
     *
     * @param string $channel the channel or group id
     *
     * @return array array containing Slack user IDs
     */
    public function getRecipients($channel) {
        return $this->channels->{$channel};
    }

    /**
     * Gets the channel_ids and group_ids that a user is subscribed to
     *
     * @param string $user the user_id
     *
     * @return array
     */
    public function getSubscriptions($user) {
        $channels = [];
        foreach($this->channels as $channel => $users) {
            if (in_array($user, $users)) {
                array_push($channels, $channel);
            }
        }

        return $channels;
    }

    /**
     * Determines if there is already a configuration for this channel
     *
     * @param string $channel the channel or group id
     *
     * @return boolean
     */
    public function hasChannel($channel) {
        return property_exists($this->channels, $channel);
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
        return in_array($user, $this->channels->{$channel});
    }

    /**
     * Save this configuration to disk
     */
    public function save() {
        $data = clone $this;
        $id = $data->_id;
        unset($data->_id);

        $url = sprintf(COUCHDB_URL, sprintf("%s", $id));
        $curl = new Curl();
        $curl->setHeader('Content-Type', 'application/json');
        $curl->setBasicAuthentication(COUCHDB_USERNAME, COUCHDB_PASSWORD);
        $curl->setOpt(CURLINFO_HEADER_OUT, true);
        $response = json_decode($curl->put($url, json_encode($data)));

        if (!$response->ok) {
            throw new Exception(sprintf("Could not save configuration: %s", json_encode($response)));
        }
        else {
            $this->_id = $id;
            $this->_rev = $response->rev;
        }
    }

    /**
     * Susbscribes a user to a channel
     *
     * @param string $user the user_id
     * @param string $channel the channel_id (or group_id)
     */
    public function subscribe($user, $channel) {
        if (!$this->isSubscribed($user, $channel)) {
            array_push($this->channels->{$channel}, $user);
            $this->save();
        }
    }

    /**
     * Unsubscribes a user from a channel
     *
     * @param string $user a user_id
     * @param string $channel a channel or group id
     */
    public function unsubscribe($user, $channel) {
        if (($i = array_search($user, $this->channels->{$channel})) !== false) {
            $subscribers = $this->channels->{$channel};
            unset($subscribers[$i]);
            $this->channels->{$channel} = array_values($subscribers);
            $this->save();
        }
    }
}
?>
