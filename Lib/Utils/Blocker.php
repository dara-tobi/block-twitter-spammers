<?php
namespace Lib\Utils;

use Lib\Connectors\Twitter;
use Lib\Utils\Combiner;
use Dotenv\Dotenv;

class Blocker
{
    public static function getRateLimits($resources = 'search') {
        $rateLimitsResponse = Twitter::connect('get', 'application/rate_limit_status', ['resources' => $resources]);

        return $rateLimitsResponse;
    }

    public static function run() {
        $timeStart = microtime(true);
        $woeId = getenv('WOEID');
        $trends = static::getTrends($woeId);

        if (!$trends) {
            die('no trends for this location; nothing to see');
        }

        $rateLimits = static::getRateLimits();
        $searchRateLimit = data_get($rateLimits, 'resources.search./search/tweets.remaining');

        printWithLineBreaks("Starting search for $searchRateLimit unique topic combinations");

        $topTwelveTopics = static::getTopTwelveTrendingTopics($trends);
        $topicCombinationsArray = Combiner::getCombinations($topTwelveTopics, 3);
        $searchStringsDataArray = static::getStringsToSearchFor($topicCombinationsArray, $searchRateLimit);
        $spamTweetsData = static::getSpamTweetsFromTwitter($searchStringsDataArray, $searchRateLimit);

        $spamTweetsArray = $spamTweetsData['spamTweets'];
        $totalSpamTweets = $spamTweetsData['spamTweetsCount'];
        $uniqueSpammersCount = count($spamTweetsArray);

        static::blockSpammers($spamTweetsArray, $uniqueSpammersCount);

        $timeEnd = microtime(true);
        $executionTime = ($timeEnd - $timeStart)/60;

        printWithLineBreaks("Total currently spamming users :: ". $uniqueSpammersCount);
        printWithLineBreaks("Total spam tweets by those users :: ". $totalSpamTweets);

        //execution time of the script
        printWithLineBreaks("Total Execution Time : $executionTime Mins");
    }

    public static function getTrends($woeId) {
        $trendsSearchResponse = Twitter::connect("get", "trends/place", ["id" => $woeId]); // Lagos WOEID is 1398823
        return data_get($trendsSearchResponse, "0.trends");
    }

    public static function getTopTwelveTrendingTopics($trends) {
        $topTwelveTopics = [];

        for ($i = 0; $i < 12; $i++) {
            $topTwelveTopics[] = data_get($trends[$i], "name");
        }

        return $topTwelveTopics;
    }

    public static function getStringsToSearchFor($topicCombinationsArray, $limit) {
        $searchStrings = [];
        $expectedSearchesCount = 0;

        foreach ($topicCombinationsArray as $combination) {
            if ($expectedSearchesCount == $limit) { // don't go past 180; the first 11 trends combine to give 165, and the first 12 to give 220. Search api rate limit is 180 for user auth
                break;
            }

            $searchStrings[trim(implode(' ', $combination), ' ')] = [
                'searchString' => trim(implode(' ', $combination), ' '),
                'itemsInSearchString' => $combination
            ];

            $expectedSearchesCount++;
        }

        return $searchStrings;
    }
    
    public static function getSpamTweetsFromTwitter($allSearchStringArray, $limit) {
        $spamTweets = [];
        $spamTweetsCount = 0;
        $searchCount = 1;

        foreach ($allSearchStringArray as $searchStringData) {
            $query = $searchStringData['searchString'];
            $searchStringArray = $searchStringData['itemsInSearchString'];
            $delimitedTopicsString = static::getDelimitedTopicsString($searchStringArray);

            $spamTweetsSearchResponse = Twitter::connect('get', 'search/tweets', ['q' => $query, 'result_type' => 'latest', 'count' => 100, 'tweet_mode' => 'extended']);

            if (data_get($spamTweetsSearchResponse, "back_off")) {
                return ['spamTweets' => $spamTweets, 'spamTweetsCount' => $spamTweetsCount];
            }

            $statuses = data_get($spamTweetsSearchResponse, "statuses");

            printWithLineBreaks("search $searchCount of $limit. Gotten " . count($statuses) . " spam Tweet(s) for $delimitedTopicsString");

            if (!empty($statuses)) {
                foreach ($statuses as $status) {
                    $spamTweetsCount++;
                    if (!data_get($status, 'retweeted_status')) {
                        $spam = [];
                        $statusId = data_get($status, "id_str");
                        $userId =  data_get($status, "user.id_str");
                        $handle = data_get($status, "user.screen_name");
                        $name = data_get($status, "user.name");
                        $text = data_get($status, "text");
                        $extendedText = data_get($status, "full_text");

                        if ($extendedText) {
                            $text = $extendedText;
                        }

                        $spam['handle'] = $handle;
                        $spam['text'] = $text;
                        $spam['userId'] = $userId;
                        $spam['topics'] = $query;
                        $spam['topicsArray'] = $searchStringArray;
                        $spam['link'] = "https://twitter.com/$handle/status/$statusId";
                        $spamTweets[$handle] = $spam; // ensure that users in the list are unique
                        $spamTweetsCount++;
                    }
                }
            }
            $searchCount++;
        }

        return ['spamTweets' => $spamTweets, 'spamTweetsCount' => $spamTweetsCount];
    }

    public static function blockSpammers($spamTweets, $uniqueSpammersCount) {
        $blockCount = 1;
        foreach ($spamTweets as $spam) {
            $delimitedTopicsString = static::getDelimitedTopicsString($spam['topicsArray']);
            printWithLineBreaks("\033[92mDoing block $blockCount of $uniqueSpammersCount: Blocking https://twitter.com/" .$spam['handle']." for spamming topics :: " . $delimitedTopicsString. " with Tweet ::\033[93m\n". $spam['text']."\033[0m");
            $blockResponse = Twitter::connect('post', 'blocks/create', ['id' => $spam['userId'], 'skip_status' => true, 'include_entities' => false]);

            if (data_get($blockResponse, "back_off")) {
                break;
            }

            $blockCount++;
            $file = fopen('blocked_users_log.txt', 'a');
            fwrite($file, json_encode($blockResponse) . "\n");
            fclose($file);
        }
    }

    public static function getDelimitedTopicsString($topicsArray) {
        $last = end($topicsArray);
        array_pop($topicsArray);

        $string = "'";
        $string .= implode("', '", $topicsArray);

        return $string .= "' and '$last'";
    }
}
