#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/src/server_lib.php';

$web_dir = getenv('BASE_WEB_DIR') ?: __DIR__;
$content_types = get_default_content_types();

run_server($web_dir, $content_types);
