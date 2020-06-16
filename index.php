<?php

require_once(__DIR__.'/vendor/autoload.php');

use Dotenv\Dotenv;
use Lib\Utils\Blocker;

$dotenv = Dotenv::createUnsafeMutable(__DIR__);
$dotenv->load();

Blocker::run();

