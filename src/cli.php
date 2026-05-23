<?php

declare(strict_types=1);

/**
 * Parse CLI arguments into a configuration array.
 *
 * Supports:
 * - --web-dir PATH: Set the web root directory
 * - --workers-count N: Set worker process count (must be >= 1)
 * - --cache-enabled true|false: Enable or disable Cache-Control response header
 * - --no-cache: Alias for --cache-enabled false
 *
 * @param array $argv Command-line arguments
 *
 * @return array{web_dir: string|null, workers_count: int|null, cache_enabled: bool|null}
 */
function parse_cli_arguments(array $argv): array
{
    $config = [
        'web_dir' => null,
        'workers_count' => null,
        'cache_enabled' => null,
    ];

    foreach ($argv as $i => $arg) {
        if ($arg === '--web-dir' && isset($argv[$i + 1])) {
            $config['web_dir'] = $argv[$i + 1];
        }

        if ($arg === '--workers-count' && isset($argv[$i + 1])) {
            $workers = (int) $argv[$i + 1];
            if ($workers < 1) {
                echo "Error: workers count must be >= 1\n";
                exit(1);
            }
            $config['workers_count'] = $workers;
        }

        if ($arg === '--cache-enabled' && isset($argv[$i + 1])) {
            $config['cache_enabled'] = normalize_boolean($argv[$i + 1]);
        }
    }

    return $config;
}

/**
 * Merge environment variables and CLI arguments into final configuration.
 * CLI arguments take precedence over environment variables.
 *
 * Supported environment variables:
 * - BASE_WEB_DIR: Root directory for serving files
 * - WORKERS_COUNT: Number of worker processes
 * - CACHE_ENABLED: Enable/disable Cache-Control response header
 *
 * @param array $argv Command-line arguments
 * @param string|null $default_web_dir Default web directory if neither env nor CLI provides one
 *
 * @return array{web_dir: string, worker_count: int|null, cache_enabled: bool}
 */
function load_config(array $argv, ?string $default_web_dir = null): array
{
    $cli_config = parse_cli_arguments($argv);

    // Base web directory: env -> CLI -> default
    $web_dir = $_ENV['BASE_WEB_DIR'] ?? $_SERVER['BASE_WEB_DIR'] ?? null;
    if ($cli_config['web_dir'] !== null) {
        $web_dir = $cli_config['web_dir'];
    }
    if ($web_dir === null) {
        $web_dir = $default_web_dir ?? __DIR__ . '/../..';
    }

    // Worker count: env -> CLI -> null (will use default calculation)
    $worker_count = null;
    if (isset($_ENV['WORKERS_COUNT'])) {
        $worker_count = (int) $_ENV['WORKERS_COUNT'];
    } elseif (isset($_SERVER['WORKERS_COUNT'])) {
        $worker_count = (int) $_SERVER['WORKERS_COUNT'];
    }
    if ($cli_config['workers_count'] !== null) {
        $worker_count = $cli_config['workers_count'];
    }

    // Cache-Control switch: env -> CLI (CLI takes precedence)
    $cache_enabled = true;
    $cache_enabled_env = $_ENV['CACHE_ENABLED'] ?? $_SERVER['CACHE_ENABLED'] ?? null;
    if ($cache_enabled_env !== null) {
        $cache_enabled = normalize_boolean($cache_enabled_env);
    }
    if ($cli_config['cache_enabled'] !== null) {
        $cache_enabled = $cli_config['cache_enabled'];
    }

    return [
        'web_dir' => $web_dir,
        'worker_count' => $worker_count,
        'cache_enabled' => $cache_enabled,
    ];
}

/**
 * Normalize common truthy values from env/CLI string inputs.
 */
function normalize_boolean(mixed $value): bool
{
    $normalized = strtolower(trim((string) $value));

    return in_array($normalized, ['1', 'true', 'ture', 'yes', 'on'], true);
}
