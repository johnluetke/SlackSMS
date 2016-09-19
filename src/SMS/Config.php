<?php
/**
 * 
 */
namespace SMS;

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

    const CONFIG_FILE = "config.json";

    /**
     * Retrieve configuration from a Slack Team ID
     *
     * @param string $team the Team ID
     *
     * @return Config
     */
    public static function getBySlackTeamID($team) {
        $config = self::load();

        foreach ($config as $key => $entry) {
            if ($key == $team) {
                return new Config($config, $key, $entry);
            }
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
        $config = self::load();

        foreach ($config as $key => $entry) {
            if (!property_exists($entry, "twilio")) {
                continue;
            }
            else {
                if ($entry->twilio->sid == $sid) {
                    return new Config($config, $key, $entry);
                }
            }
        }

        return null;
    }

    /**
     * Load configuration from disk
     *
     * @return StdClass
     *
     * @internal
     */
    private static function load() {
        $config = null;
        if (!file_exists(self::CONFIG_FILE)) {
            $config = new \stdClass;
        }
        else {
            $config = json_decode(file_get_contents(self::CONFIG_FILE));
        }

        return $config;
    }

    /**
     * @ignore
     */
    private $global;
    /**
     * @ignore
     */
    private $key;

    /**
     * Create a Config object.
     *
     * @param StdClass $global a StdClass containing the configuration loaded from disk
     * @param string $key a key for referencing this Config globally
     * @param StdClass $obj a StdClass containing configuration properties
     *
     * @internal
     */
    private function __construct($global, $key, $obj) {
        $this->global = $global;
        $this->key = $key;

        if (is_object($obj)) {
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
        $global = $this->global;
        $key = $this->key;

        unset($this->global);
        unset($this->key);

        $global->{$key} = $this;
        error_log(print_r($global, true));
        file_put_contents(self::CONFIG_FILE, json_encode($global, JSON_PRETTY_PRINT));

        $this->global = $global;
        $this->key = $key;
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
            unset($this->channels->{$channel}[$i]);
            $this->save();
        }
    }
}
?>
