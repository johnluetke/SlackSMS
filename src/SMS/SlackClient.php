<?php
namespace SMS;

use Exception;

use Frlnc\Slack\Core\Commander;
use Frlnc\Slack\Http\CurlInteractor;
use Frlnc\Slack\Http\SlackResponseFactory;

use Katzgrau\KLogger\Logger;
use Psr\Log\LogLevel;

class SlackClient extends Commander {

    public function __construct($token, Logger $logger) {
        $interactor = new CurlInteractor();
        $interactor->setResponseFactory(new SlackResponseFactory);
        parent::__construct($token, $interactor);
        $this->logger = $logger;
    }

    /**
     * Adds a user to a channel or group
     *
     * @param string $user a Slack user id
     * @param string $channel a Slack channel or group id
     */
    public function addUserToChannel($user, $channel) {
        $isGroup = substr($channel, 0, 1) == "G";
        $response = $this->execute(!$isGroup ? "channels.invite" : "groups.invite", [ "channel" => $channel, "user" => $user ])->getBody();
         if (empty($response['ok']) || !$response['ok']) {
            throw new Exception($response['error']);
        }
    }

    public function getUser($user) {
        $response = $this->execute("users.info", ["user" => $user])->getBody();
         if (empty($response['ok']) || !$response['ok']) {
            throw new Exception($response['error']);
        }
         else {
             return $response['user'];
        }

    }

    public function getUserIDByToken($token) {
        $response = $this->execute("auth.test")->getBody();
         if (empty($response['ok']) || !$response['ok']) {
            throw new Exception($response['error']);
        }
         else {
             return $response['user_id'];
        }
    }

    /**
     * Returns the name of a given channel or group
     *
     * @param string $channel a channel or group id
     *
     * @return string a name
     */
    public function getChannelName($channel) {
        $this->logger->debug(sprintf("Looking up name for %s", $channel));
        $isGroup = substr($channel, 0, 1) == "G";
        $response = $this->execute($isGroup ? "groups.info" : "channels.info", [ "channel" => $channel ])->getBody();
        if (empty($response['ok']) || !$response['ok']) {
            throw new Exception($response['error']);
        }
        else {
            $this->logger->debug(sprintf("Response for channel %s", $channel, $response));
            return $response[$isGroup ? 'group' : 'channel']['name'];
        }
    }

    /**
     * Returns the 'real name' of the Slack user with the given ID
     *
     * @param string $user a user id
     *
     * @return string a name
     */
    public function getUserName($user) {
        $this->logger->debug(sprintf("Looking up name for user %s", $user ));
        $response = $this->execute("users.info", [ "user" => $user ])->getBody();
        if (empty($response['ok']) || !$response['ok']) {
            throw new Exception($response['error']);
        }
        else {
            $this->logger->debug(print_r($response, true));
            return $response['user']['name'];
        }
    }

    /**
     * Returns the 'real name' of the Slack user with the given ID
     *
     * @param string $user a user id
     *
     * @return string a name
     */
    public function getUserRealName($user) {
        $this->logger->debug(sprintf("Looking up name for user %s", $user ));
        $response = $this->execute("users.info", [ "user" => $user ])->getBody();
        if (empty($response['ok']) || !$response['ok']) {
            throw new Exception($response['error']);
        }
        else {
            $this->logger->debug(print_r($response, true));
            return $response['user']['profile']['real_name'];
        }
    }

    public function getPhoneNumber($user) {
        $response = $this->execute("users.info", [ "user" => $user ])->getBody();
        if (!$response['ok']) {
            throw new Exception($response['error']);
        }
        else {
            $this->logger->debug(print_r($response, true));
            return $response['user']['profile']['phone'];
        }

    }

    public function hasPhoneNumber($user) {
        $response = $this->execute("users.info", [ "user" => $user ])->getBody();
        if (!$response['ok']) {
            throw new Exception($response['error']);
        }
        else {
            $this->logger->debug(print_r($response, true));
            return !empty($response['user']['profile']['phone']);
        }
    }

    public function isAdmin($user) {
        return $user['is_admin'];
    }

    public function isUserInChannel($user, $channel) {
        $isGroup = substr($channel, 0, 1) == "G";
        $response = $this->execute(!$isGroup ? "channels.info" : "groups.info", [ "channel" => $channel, "user" => $user ])->getBody();
         if (empty($response['ok']) || !$response['ok']) {
            throw new Exception($response['error']);
         }
         else {
            
            return in_array($user, $response['channel']['members']);
         }
    }

    /**
     * Returns a Slack 'User' object that has a matching phone number
     *
     * The phone number is stripped down to just digits, and also searched for via PHP strpos, meaning the country code
     * is optional.
     *
     * @param string $phone the phone number to search for
     *
     * @return Slack 'User' object
     */
    public function lookupUserByPhone($phone) {
        $response = $this->execute("users.list", [])->getBody();
        if (empty($response['ok']) || !$response['ok']) {
            throw new Exception($response['error']);
        }
        else {
            foreach ($response['members'] as $user) {
                if (!isset($user['profile']['phone']) || empty($user['profile']['phone']))
                    continue;
                else {
                    $p = preg_replace("/[^\d]/", "", $user['profile']['phone']);
                    if (empty($p)) continue;
                    else if (strpos($phone, $p) !== false) {
                        return $user;
                    }
                }
            }
        }
    
        return null;
    }

    public function sendDirectMessage($user, $message) {
        $response = $this->execute ("im.open", [ "user" => $user ])->getBody();

        if (empty($response['ok']) || !$response['ok']) {
            print_r($response, true);
            throw new Exception($response['error']);
        }

        $this->execute("chat.postMessage", [
            "channel" => $response['channel']['id'],
            "text" => $message
        ]);
    }
}
?>
