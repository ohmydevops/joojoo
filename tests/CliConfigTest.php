<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/cli.php';

final class CliConfigTest extends TestCase
{
    public function test_parse_cli_arguments_no_args(): void
    {
        $argv = ['server.php'];
        $config = parse_cli_arguments($argv);

        $this->assertNull($config['web_dir']);
        $this->assertNull($config['workers_count']);
        $this->assertFalse($config['show_help']);
    }

    public function test_parse_cli_arguments_help_long_flag(): void
    {
        $argv = ['server.php', '--help'];
        $config = parse_cli_arguments($argv);

        $this->assertTrue($config['show_help']);
    }

    public function test_parse_cli_arguments_help_short_flag(): void
    {
        $argv = ['server.php', '-h'];
        $config = parse_cli_arguments($argv);

        $this->assertTrue($config['show_help']);
    }

    public function test_parse_cli_arguments_web_dir(): void
    {
        $argv = ['server.php', '--web-dir', '/path/to/site'];
        $config = parse_cli_arguments($argv);

        $this->assertSame('/path/to/site', $config['web_dir']);
        $this->assertNull($config['workers_count']);
    }

    public function test_parse_cli_arguments_workers_count(): void
    {
        $argv = ['server.php', '--workers-count', '8'];
        $config = parse_cli_arguments($argv);

        $this->assertNull($config['web_dir']);
        $this->assertSame(8, $config['workers_count']);
    }

    public function test_parse_cli_arguments_both_options(): void
    {
        $argv = ['server.php', '--web-dir', 'docs', '--workers-count', '4'];
        $config = parse_cli_arguments($argv);

        $this->assertSame('docs', $config['web_dir']);
        $this->assertSame(4, $config['workers_count']);
    }

    public function test_parse_cli_arguments_invalid_workers_count(): void
    {
        $this->markTestSkipped('Exit code testing requires process isolation');
    }

    public function test_parse_cli_arguments_missing_value(): void
    {
        $argv = ['server.php', '--web-dir'];
        $config = parse_cli_arguments($argv);

        $this->assertNull($config['web_dir']);
    }

    public function test_load_config_defaults(): void
    {
        $argv = ['server.php'];
        $default_dir = '/default/path';

        $config = load_config($argv, $default_dir);

        $this->assertSame($default_dir, $config['web_dir']);
        $this->assertNull($config['worker_count']);
    }

    public function test_load_config_cli_args_override_defaults(): void
    {
        $argv = ['server.php', '--web-dir', 'docs', '--workers-count', '6'];
        $default_dir = '/default/path';

        $config = load_config($argv, $default_dir);

        $this->assertSame('docs', $config['web_dir']);
        $this->assertSame(6, $config['worker_count']);
    }

    public function test_load_config_env_var_precedence(): void
    {
        $_ENV['BASE_WEB_DIR'] = '/env/web';
        $_ENV['WORKERS_COUNT'] = 12;

        $argv = ['server.php'];

        $config = load_config($argv, '/default');

        $this->assertSame('/env/web', $config['web_dir']);
        $this->assertSame(12, $config['worker_count']);

        // Cleanup
        unset($_ENV['BASE_WEB_DIR']);
        unset($_ENV['WORKERS_COUNT']);
    }

    public function test_load_config_cli_overrides_env(): void
    {
        $_ENV['BASE_WEB_DIR'] = '/env/web';
        $_ENV['WORKERS_COUNT'] = 12;

        $argv = ['server.php', '--web-dir', '/cli/web', '--workers-count', '4'];

        $config = load_config($argv, '/default');

        $this->assertSame('/cli/web', $config['web_dir']);
        $this->assertSame(4, $config['worker_count']);

        // Cleanup
        unset($_ENV['BASE_WEB_DIR']);
        unset($_ENV['WORKERS_COUNT']);
    }

    public function test_load_config_cli_overrides_env_partially(): void
    {
        $_ENV['BASE_WEB_DIR'] = '/env/web';
        $_ENV['WORKERS_COUNT'] = 12;

        $argv = ['server.php', '--workers-count', '3'];

        $config = load_config($argv, '/default');

        $this->assertSame('/env/web', $config['web_dir']);
        $this->assertSame(3, $config['worker_count']);

        // Cleanup
        unset($_ENV['BASE_WEB_DIR']);
        unset($_ENV['WORKERS_COUNT']);
    }

    public function test_cli_help_text_contains_main_options(): void
    {
        $help = cli_help_text();

        $this->assertStringContainsString('--help', $help);
        $this->assertStringContainsString('--web-dir', $help);
        $this->assertStringContainsString('--workers-count', $help);
    }
}
