#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/src/server_lib.php';
require_once __DIR__ . '/src/cli.php';

$config = load_config($argv, __DIR__);

run_server(
    web_dir:$config['web_dir'],
    worker_count:$config['worker_count'],
    cache_enabled:$config['cache_enabled']
);
