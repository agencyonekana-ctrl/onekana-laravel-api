<?php

use Onekana\Api\App;
use Onekana\Api\Http\Request;

define('ONEKANA_BASE_PATH', dirname(__DIR__));

require ONEKANA_BASE_PATH.'/vendor/autoload.php';

(new App(ONEKANA_BASE_PATH))->handle(Request::capture())->send();
