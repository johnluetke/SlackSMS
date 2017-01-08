<?php
define("SLACK_OAUTH_URL",     "https://slack.com/oauth/authorize");
define("SLACK_CLIENT_ID",     getenv('SLACK_CLIENT_ID'));
define("SLACK_CLIENT_SECRET", getenv('SLACK_CLIENT_SECRET'));
define("SLACK_COMMAND_TOKEN", getenv('SLACK_COMMAND_TOKEN'));
define("COUCHDB_URL",         "http://couchdb.apps.johnluetke.com:5984/slack-sms/%s");
define("COUCHDB_USERNAME",    getenv('COUCHDB_USERNAME'));
define("COUCHDB_PASSWORD",    getenv('COUCHDB_PASSWORD'));
?>
