<?php

declare(strict_types=1);

/**
 * Parse CLI arguments into a configuration array.
 *
 * Supports:
 * - -h, --help: Show usage help and exit
 * - --web-dir PATH: Set the web root directory
 * - --workers-count N: Set worker process count (must be >= 1)
 *
 * @param array $argv Command-line arguments
 *
 * @return array{web_dir: string|null, workers_count: int|null, show_help: bool}
 */
function parse_cli_arguments(array $argv): array
{
    $config = [
        'web_dir' => null,
        'workers_count' => null,
        'show_help' => false,
    ];

    foreach ($argv as $i => $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $config['show_help'] = true;
        }

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

    }

    return $config;
}

/**
 * Build CLI usage text.
 */
function cli_help_text(): string
{
    return "Usage: php server.php [options]\n"
        . "\n"
        . "Options:\n"
        . "  -h, --help                    Show this help and exit\n"
        . "  --web-dir PATH                Set root directory for serving files\n"
        . "  --workers-count N             Set worker process count (must be >= 1)\n"
        . "\n"
        . "Environment variables:\n"
        . "  BASE_WEB_DIR                  Root directory for serving files\n"
        . "  WORKERS_COUNT                 Worker process count\n"
        . "\n"
        . "Precedence: CLI arguments override environment variables.\n";
}

/**
 * Merge environment variables and CLI arguments into final configuration.
 * CLI arguments take precedence over environment variables.
 *
 * Supported environment variables:
 * - BASE_WEB_DIR: Root directory for serving files
 * - WORKERS_COUNT: Number of worker processes
 *
 * @param array $argv Command-line arguments
 * @param string|null $default_web_dir Default web directory if neither env nor CLI provides one
 *
 * @return array{web_dir: string, worker_count: int|null, show_help: bool}
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

    return [
        'web_dir' => $web_dir,
        'worker_count' => $worker_count,
        'show_help' => $cli_config['show_help'],
    ];
}
