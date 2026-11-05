<?php

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
return [
    'default' => env('LOG_CHANNEL', 'stack'),
    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => false,
    ],
    'channels' => [
        'stack' => ['driver' => 'stack', 'channels' => ['single'], 'ignore_exceptions' => false],
        'single' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'with' => ['stream' => storage_path('logs/laravel.log')],
        ],
        'null' => ['driver' => 'monolog', 'handler' => NullHandler::class],
    ],
];
