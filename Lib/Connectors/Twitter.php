<?php
namespace Lib\Connectors;

use Exception;
use Abraham\TwitterOAuth\TwitterOAuth;

class Twitter {

	public $twitterUserConnection;

	public static function getTwitterConnection() {
        $consumer_key = getenv('CONSUMER_KEY');
        $consumer_secret = getenv('CONSUMER_SECRET');
        $access_token_key = getenv('ACCESS_TOKEN_KEY');
        $access_token_secret = getenv('ACCESS_TOKEN_SECRET');

		$twitterUserConnection = new TwitterOAuth($consumer_key, $consumer_secret, $access_token_key, $access_token_secret);
		$twitterUserConnection->setTimeouts(35, 35);

		return $twitterUserConnection;
	}

	public static function connect($httpVerb, $endpoint, $options = null) {
		$twitterUserConnection = static::getTwitterConnection();

		try {
			$response = $twitterUserConnection->$httpVerb($endpoint, $options);
			$errors = data_get($response, "errors");

			if (!empty($errors)) {
				printWithLineBreaks("\033[31mThe following errors occurred while making a request to Twitter's API\033[0m");
				foreach ($errors as $error) {
					printWithLineBreaks("\033[31mMessage: ". $error->message." Code: ". $error->code."\033[0m");
				}

				if ($error->message == 'Rate limit exceeded' && $error->code == 88) {
					printWithLineBreaks("\033[31mYou've reached the API request limit for the $endpoint endpoint\033[0m");
					return ['back_off' => true];
				}

				throw new Exception("Twitter API request failed");
			}

			return $response;
		} catch (\Exception $e) {
			if (strstr($e->getMessage(), 'timed out')) {
				printWithLineBreaks("\033[31mThe API request timed out the $endpoint endpoint\033[0m");
				return ['back_off' => true];
			}
		}
	}
}