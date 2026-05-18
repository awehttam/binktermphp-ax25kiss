<?php

namespace BinktermPhpAx25Kiss;

/**
 * TNC connection manager for KISS over TCP or serial.
 *
 * TCP mode is used with software TNCs such as Direwolf (default port 8001).
 * Serial mode is used with hardware TNCs connected via RS-232 or USB-serial.
 */
class KissTnc
{
    /** @var resource|null */
    private $stream = null;

    private string $recvBuffer = '';
    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Open a TCP KISS connection to a software TNC (e.g. Direwolf).
     *
     * @throws \RuntimeException on connection failure
     */
    public function connectTcp(string $host, int $port, int $timeoutSec = 10): void
    {
        $uri    = "tcp://{$host}:{$port}";
        $stream = @stream_socket_client($uri, $errno, $errstr, $timeoutSec);
        if (!$stream) {
            throw new \RuntimeException("KISS TCP connect failed ({$uri}): {$errstr} [{$errno}]");
        }

        stream_set_blocking($stream, false);
        $this->stream = $stream;
        $this->logger->info("TNC connected via TCP {$host}:{$port}");
    }

    /**
     * Open a serial KISS connection to a hardware TNC.
     *
     * Applies baud rate and raw mode via stty before opening the device.
     * Requires the stty command available on the system PATH.
     *
     * @throws \RuntimeException on open or configuration failure
     */
    public function connectSerial(string $device, int $baud = 1200): void
    {
        exec(
            'stty -F ' . escapeshellarg($device) . " {$baud} raw cs8 -cstopb -parenb -echo 2>&1",
            $out,
            $rc
        );
        if ($rc !== 0) {
            throw new \RuntimeException("stty failed for {$device}: " . implode(' ', $out));
        }

        $stream = @fopen($device, 'r+b');
        if (!$stream) {
            throw new \RuntimeException("Cannot open serial device: {$device}");
        }

        stream_set_blocking($stream, false);
        $this->stream = $stream;
        $this->logger->info("TNC connected via serial {$device} at {$baud} baud");
    }

    /**
     * Read available bytes and return any complete AX.25 frames decoded from KISS.
     *
     * Non-blocking; returns an empty array when no data is available.
     *
     * @return string[] Raw AX.25 frame payloads
     */
    public function readFrames(): array
    {
        if ($this->stream === null) {
            return [];
        }

        $data = @fread($this->stream, 4096);
        if ($data !== false && $data !== '') {
            $this->recvBuffer .= $data;
        }

        return KissProtocol::extractFrames($this->recvBuffer);
    }

    /**
     * Transmit a raw AX.25 frame wrapped in KISS encoding.
     *
     * @param string $ax25Frame Raw AX.25 frame bytes
     * @param int    $port      TNC port number (default 0)
     */
    public function sendFrame(string $ax25Frame, int $port = 0): void
    {
        if ($this->stream === null) {
            return;
        }

        $packet = KissProtocol::encode($ax25Frame, $port);
        @fwrite($this->stream, $packet);
        @fflush($this->stream);
    }

    public function isConnected(): bool
    {
        return $this->stream !== null && !feof($this->stream);
    }

    public function close(): void
    {
        if ($this->stream !== null) {
            @fclose($this->stream);
            $this->stream = null;
        }
        $this->recvBuffer = '';
        $this->logger->info('TNC connection closed');
    }
}
