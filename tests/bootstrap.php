<?php

declare(strict_types=1);

// Unit tests run without WordPress: the few WordPress functions the tested classes call are
// stubbed in tests/wp-stubs.php and record what they receive.

define('ABSPATH', __DIR__ . '/');

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor-prefixed/autoload.php';
require __DIR__ . '/wp-stubs.php';
