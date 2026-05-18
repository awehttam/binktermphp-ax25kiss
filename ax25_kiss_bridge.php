#!/usr/bin/env php
<?php

/*
 * BinktermPHP AX.25 KISS Bridge
 *
 * Connects to a KISS TNC (TCP or serial) and relays AX.25 UI frames to and
 * from the BinktermPHP PacketBBS HTTP API, giving packet radio stations
 * access to a BinktermPHP BBS over amateur radio.
 *
 * > **This adapter is experimental.** If you encounter issues, please
 * > [report them on GitHub](https://github.com/awehttam/binkterm-php/issues).
 *
 * Usage:
 *   php ax25_kiss_bridge.php --config=config.json [options]
 *
 * Options:
 *   --config=FILE     Path to JSON config file (default: config.json)
 *   --daemon          Detach from terminal and run as a background process
 *   --pid-file=FILE   Write daemon PID to FILE (default: ax25_kiss_bridge.pid)
 *   --echo-log        Print log output to stdout even when running as daemon
 *   --help            Show this help message and exit
 *
 * See config.example.json for all configuration options.
 *
 * This adapter is experimental. Report issues to GitHub:
 * https://github.com/awehttam/binkterm-php/issues
 */

require_once __DIR__ . '/vendor/autoload.php';

use BinktermPhpAx25Kiss\BridgeConfig;
use BinktermPhpAx25Kiss\KissBridge;
use BinktermPhpAx25Kiss\KissTnc;
use BinktermPhpAx25Kiss\Logger;

// ============================================================
// CLI helpers
// ============================================================

function parseArgs(array $argv): array
{
    $args = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--')) {
            if (str_contains($arg, '=')) {
                [$key, $val] = explode('=', substr($arg, 2), 2);
                $args[$key]  = $val;
            } else {
                $args[substr($arg, 2)] = true;
            }
        }
    }
    return $args;
}

function showHelp(): void
{
    echo <<<HELP
BinktermPHP AX.25 KISS Bridge

  THIS ADAPTER IS EXPERIMENTAL.
  Report issues to GitHub: https://github.com/awehttam/binkterm-php/issues

Usage:
  php ax25_kiss_bridge.php --config=config.json [options]

Options:
  --config=FILE     Path to JSON config file (default: config.json)
  --daemon          Detach from terminal and run as a background process
  --pid-file=FILE   Write daemon PID to FILE (default: ax25_kiss_bridge.pid)
  --echo-log        Print log output to stdout
  --help            Show this help message

See config.example.json for all configuration options.

HELP;
}

function daemonize(): void
{
    if (!function_exists('pcntl_fork')) {
        fwrite(STDERR, "Warning: pcntl extension not available; --daemon ignored\n");
        return;
    }

    $pid = pcntl_fork();
    if ($pid === -1) {
        fwrite(STDERR, "Error: pcntl_fork() failed\n");
        exit(1);
    }
    if ($pid > 0) {
        // Parent exits; child continues as the daemon.
        exit(0);
    }

    if (posix_setsid() === -1) {
        fwrite(STDERR, "Error: posix_setsid() failed\n");
        exit(1);
    }

    fclose(STDIN);
    fclose(STDOUT);
    fclose(STDERR);
}

function writePidFile(string $path): void
{
    file_put_contents($path, getmypid() . "\n");
}

// ============================================================
// Entry point
// ============================================================

$args = parseArgs($argv);

if (isset($args['help'])) {
    showHelp();
    exit(0);
}

$configPath = $args['config'] ?? 'config.json';
$pidFile    = $args['pid-file'] ?? 'ax25_kiss_bridge.pid';
$echoLog    = isset($args['echo-log']);
$daemon     = isset($args['daemon']);

// Load config before daemonizing so errors are visible on the terminal.
try {
    $cfg = BridgeConfig::fromFile($configPath);
} catch (\Exception $e) {
    fwrite(STDERR, "Config error: {$e->getMessage()}\n");
    exit(1);
}

if ($daemon) {
    daemonize();
}

writePidFile($pidFile);

$logLevel = Logger::levelFromString($cfg->logLevel);
$logger   = new Logger($cfg->logFile, $logLevel, $echoLog || !$daemon);

$logger->info('Starting BinktermPHP AX.25 KISS bridge');
$logger->info("Config: mycall={$cfg->mycall} bbs={$cfg->bbsUrl}");

// Connect to TNC with retry loop.
$reconnectDelay = 5;

while (true) {
    $tnc = new KissTnc($logger);

    try {
        if ($cfg->tncType === 'serial') {
            $tnc->connectSerial($cfg->serialDevice, $cfg->serialBaud);
        } else {
            $tnc->connectTcp($cfg->tncHost, $cfg->tncPort);
        }
    } catch (\RuntimeException $e) {
        $logger->error("TNC connect failed: {$e->getMessage()}");
        $logger->info("Retrying in {$reconnectDelay}s...");
        sleep($reconnectDelay);
        $reconnectDelay = min($reconnectDelay * 2, 60);
        continue;
    }

    $reconnectDelay = 5; // reset backoff after a successful connect

    $bridge = new KissBridge($tnc, $cfg, $logger);
    $bridge->run();

    $tnc->close();

    $logger->info("Reconnecting in {$reconnectDelay}s...");
    sleep($reconnectDelay);
}
