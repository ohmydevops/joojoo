#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/src/server_lib.php';
require_once __DIR__ . '/src/cli.php';

$config = load_config($argv, __DIR__);

if ($config['show_help']) {
    echo cli_help_text();
    exit(0);
}

$serverConfig = new ServerConfig(
    webDir: $config['web_dir'],
    keepAliveMaxRequests: KEEP_ALIVE_MAX_REQUESTS,
    keepAliveTimeout: KEEP_ALIVE_TIMEOUT,
);

run_server(
    config: $serverConfig,
    worker_count:$config['worker_count']
);
