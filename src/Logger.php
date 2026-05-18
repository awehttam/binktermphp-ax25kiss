<?php

namespace BinktermPhpAx25Kiss;

/**
 * Minimal levelled logger that writes to a file and optionally to stdout.
 */
class Logger
{
    const LEVEL_DEBUG   = 0;
    const LEVEL_INFO    = 1;
    const LEVEL_WARNING = 2;
    const LEVEL_ERROR   = 3;

    private static array $levelNames = [
        self::LEVEL_DEBUG   => 'DEBUG',
        self::LEVEL_INFO    => 'INFO',
        self::LEVEL_WARNING => 'WARNING',
        self::LEVEL_ERROR   => 'ERROR',
    ];

    private int    $minLevel;
    private string $logFile;
    private bool   $echo;

    /**
     * @param string $logFile  Path to log file, or empty string to disable file logging
     * @param int    $minLevel Minimum level to log (use LEVEL_* constants)
     * @param bool   $echo     Also print to stdout
     */
    public function __construct(string $logFile, int $minLevel = self::LEVEL_INFO, bool $echo = false)
    {
        $this->logFile  = $logFile;
        $this->minLevel = $minLevel;
        $this->echo     = $echo;
    }

    public static function levelFromString(string $name): int
    {
        return match (strtoupper($name)) {
            'DEBUG'   => self::LEVEL_DEBUG,
            'WARNING' => self::LEVEL_WARNING,
            'ERROR'   => self::LEVEL_ERROR,
            default   => self::LEVEL_INFO,
        };
    }

    public function debug(string $msg): void   { $this->log(self::LEVEL_DEBUG, $msg); }
    public function info(string $msg): void    { $this->log(self::LEVEL_INFO, $msg); }
    public function warning(string $msg): void { $this->log(self::LEVEL_WARNING, $msg); }
    public function error(string $msg): void   { $this->log(self::LEVEL_ERROR, $msg); }

    private function log(int $level, string $msg): void
    {
        if ($level < $this->minLevel) {
            return;
        }

        $line = sprintf(
            "[%s] [%s] %s\n",
            date('Y-m-d H:i:s'),
            self::$levelNames[$level] ?? 'LOG',
            $msg
        );

        if ($this->logFile !== '') {
            error_log($line, 3, $this->logFile);
        }

        if ($this->echo) {
            echo $line;
        }
    }
}
