#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/src/server_lib.php';

$web_dir = getenv('BASE_WEB_DIR') ?: __DIR__;

run_server($web_dir);
