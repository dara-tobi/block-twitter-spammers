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
        $numberOfTagsToCombine = getenv('NUMBER_OF_TAGS_TO_COMBINE') ?: 4;
        $timeStart = microtime(true);
        $woeId = getenv('WOEID');
        $trends = static::getTrends($woeId);
        $numberOfTrendsToGet = getenv('NUMBER_OF_TRENDS_TO_GET') ?: 19;

        if (!$trends) {
            die('no trends for this location; nothing to see, do or block here');
        }

        $rateLimits = static::getRateLimits();
        $searchRateLimit = data_get($rateLimits, 'resources.search./search/tweets.remaining');

        printWithLineBreaks("Starting search for $searchRateLimit unique topic combinations");

        $topTrendingTopics = static::getTopTrendingTopics($trends, $numberOfTrendsToGet);
        $topicCombinationsArray = Combiner::getCombinations($topTrendingTopics, $numberOfTagsToCombine);
        $searchStringsDataArray = static::getStringsToSearchFor($topicCombinationsArray, $searchRateLimit);
        $spamTweetsData = static::getSpamTweetsFromTwitter($searchStringsDataArray, $searchRateLimit);

        $spamTweetsArray = $spamTweetsData['spamTweets'];
        $totalSpamTweets = $spamTweetsData['spamTweetsCount'];
        $uniqueSpammersCount = count($spamTweetsArray);

        $blockResults = static::blockSpammers($spamTweetsArray, $uniqueSpammersCount);

        $timeEnd = microtime(true);
        $executionTime = ($timeEnd - $timeStart)/60;

        printWithLineBreaks("Total currently spamming users :: ". $uniqueSpammersCount);
        printWithLineBreaks("Total spam tweets by those users :: ". $totalSpamTweets);
        printWithLineBreaks("Blocked ". $blockResults['total_blocked_users']. " user(s)");
        printWithLineBreaks("Skipped ". $blockResults['total_user_with_too_few_spam_tweets']. " user(s) with too few spam tweets");
        printWithLineBreaks("Already blocked ". $blockResults['total_already_blocked_users']. " user(s) before");
        //execution time of the script
        $executionTimeInMinutes = floor($executionTime);
        $executionTimeInSeconds = floor(($executionTime - $executionTimeInMinutes) * 60);
        printWithLineBreaks("Total Execution Time : $executionTimeInMinutes Mins $executionTimeInSeconds Secs");
    }

    public static function getTrends($woeId) {
        $trendsSearchResponse = Twitter::connect("get", "trends/place", ["id" => $woeId]); // Lagos WOEID is 1398823
        return data_get($trendsSearchResponse, "0.trends");
    }

    public static function getTopTrendingTopics($trends, $numberOfTrendsToGet) {
        $topTrendingTopics = [];

        for ($i = 0; $i <= $numberOfTrendsToGet; $i++) {
            $topTrendingTopics[] = data_get($trends[$i], "name");
        }

        return $topTrendingTopics;
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
                        // count spam tweets per user; ensure handles are unique
                        if (isset($spamTweets[$handle])) {
                            $spamTweets[$handle]['count']++;
                            $spamTweets[$handle]['links'][$spam['link']] = $spam['text'];
                        } else {
                            $spam['count'] = 1;
                            $spam['links'][$spam['link']] = $spam['text'];
                            $spamTweets[$handle] = $spam;
                        }
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
        $usersWithTooFewSpamTweets = 0;
        $alreadyBlockedUsers = 0;
        $spamLimit = getenv('SPAM_THRESHOLD') ?: 1;
        foreach ($spamTweets as $spam) {
            if ($spam['count'] < $spamLimit) {
                printWithLineBreaks("\033[97mSkipping https://twitter.com/" .$spam['handle']." :: ".$spam['link']." :: ".$spam['count']." spam tweet(s)\033[0m");
                $usersWithTooFewSpamTweets++;
                continue;
            }
            if (file_exists('blocked_ids.txt') && strpos(file_get_contents('blocked_ids.txt'), $spam['userId']) !== false) {
                printWithLineBreaks("\033[95mUser already blocked :: https://twitter.com/" .$spam['handle']." :: ".$spam['link']." :: ".$spam['count']." spam tweet(s)\033[0m");
                $alreadyBlockedUsers++;
                continue;
            }
            $delimitedTopicsString = static::getDelimitedTopicsString($spam['topicsArray']);

            printWithLineBreaks("\033[92mDoing block $blockCount of $uniqueSpammersCount: Blocking https://twitter.com/" .$spam['handle']);
            printWithLineBreaks("Topics :: " . $delimitedTopicsString . "\033[0m");
            printWithLineBreaks("\033[94mTotal spam tweet(s) :: " . $spam['count'] . "\033[0m");
            try {
                $delimitedLInksString = static::getDelimitedLinksString($spam['links']);
                printWithLineBreaks("\033[0mSpam tweets :: \n" . $delimitedLInksString . "\033[0m");
            } catch (\Exception $e) {
                printWithLineBreaks("\033[91mError getting spam links\033[0m");
            }

            $blockResponse = Twitter::connect('post', 'blocks/create', ['id' => $spam['userId'], 'skip_status' => true, 'include_entities' => false]);

            if (data_get($blockResponse, "back_off")) {
                break;
            }

            $blockCount++;
            $today = date("Y-m-d");
            $file = fopen('blocked_users_log_' . $today . '.txt', 'a');
            fwrite($file, json_encode($blockResponse) . "\n");
            fclose($file);
            $idsFile = fopen('blocked_ids.txt', 'a');
            fwrite($idsFile, $spam['userId'] . "\n");
            fclose($idsFile);
        }
        return [
            'total_user_with_too_few_spam_tweets' => $usersWithTooFewSpamTweets,
            'total_already_blocked_users' => $alreadyBlockedUsers,
            'total_blocked_users' => $blockCount - 1,
        ];
    }

    public static function getDelimitedTopicsString($topicsArray) {
        $last = end($topicsArray);
        array_pop($topicsArray);

        $string = "'";
        $string .= implode("', '", $topicsArray);

        return $string .= "' and '$last'";
    }

    public static function getDelimitedLinksString($linksArray) {
        $number = 1;
        if (count($linksArray) == 1) {
            return $number.". ". current($linksArray)." ". "(".key($linksArray).") \n\n";
        }
        $lastValue = end($linksArray);
        $lastKey = key($linksArray);
        array_pop($linksArray);
        $string = "";

        foreach ($linksArray as $link => $text) {
            $string .= "$number. $text ($link) \n\n";
            $number++;
        }

        $last = $number.". ".$lastValue." (".$lastKey.") \n\n";

        return $string . $last;
    }
}
