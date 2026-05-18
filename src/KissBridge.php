<?php

namespace BinktermPhpAx25Kiss;

/**
 * AX.25/KISS to BinktermPHP PacketBBS bridge.
 *
 * Listens for UI frames addressed to the BBS callsign, forwards the text
 * payload to the PacketBBS HTTP API, and transmits the response back over air.
 *
 * Each AX.25 source callsign is treated as a distinct PacketBBS node_id. The
 * bridge itself authenticates to the API using a single bearer token.
 */
class KissBridge
{
    private KissTnc      $tnc;
    private BridgeConfig $cfg;
    private Logger       $logger;

    /** @var array<string, int> callsign => last_seen_unix_timestamp */
    private array $activeCallsigns = [];

    private int $lastPollAt   = 0;
    private int $lastBeaconAt = 0;

    /** @var bool Set to false by signal handlers to exit the main loop cleanly. */
    private bool $running = true;

    public function __construct(KissTnc $tnc, BridgeConfig $cfg, Logger $logger)
    {
        $this->tnc    = $tnc;
        $this->cfg    = $cfg;
        $this->logger = $logger;
    }

    /**
     * Run the main bridge event loop.
     *
     * Blocks indefinitely until the TNC disconnects or a signal is received.
     */
    public function run(): void
    {
        $this->logger->info("AX.25 KISS bridge running — mycall={$this->cfg->mycall}");

        $this->sendBeacon(); // beacon on connect

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, fn() => $this->running = false);
            pcntl_signal(SIGINT,  fn() => $this->running = false);
        }

        while ($this->running) {
            if (!$this->tnc->isConnected()) {
                $this->logger->warning('TNC disconnected; exiting loop');
                break;
            }

            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            foreach ($this->tnc->readFrames() as $raw) {
                $this->handleRawFrame($raw);
            }

            $this->maybePollOutbound();
            $this->maybeBeacon();

            usleep(50_000); // 50 ms — yield CPU without busy-spinning
        }

        $this->logger->info('Bridge loop stopped');
    }

    // -------------------------------------------------------------------------

    private function handleRawFrame(string $raw): void
    {
        $frame = Ax25Frame::parse($raw);
        if ($frame === null) {
            return;
        }

        // Ignore frames not addressed to our callsign.
        if (strcasecmp($frame->dest, $this->cfg->mycall) !== 0) {
            return;
        }

        $callsign = strtoupper($frame->src);
        $text     = trim($frame->info);

        if ($text === '') {
            return;
        }

        $this->logger->info("RX {$callsign} > {$this->cfg->mycall}: " . substr($text, 0, 80));
        $this->activeCallsigns[$callsign] = time();

        $response = $this->sendCommand($callsign, $text);
        if ($response !== null && $response !== '') {
            $this->transmitReply($callsign, $response);
        }
    }

    private function sendCommand(string $nodeId, string $command): ?string
    {
        $url  = $this->cfg->bbsUrl . '/api/packetbbs/command';
        $body = json_encode([
            'node_id'        => $nodeId,
            'interface'      => 'tnc',
            'command'        => $command,
            'bridge_node_id' => $this->cfg->bridgeNodeId,
        ]);

        $response = $this->apiPost($url, $body);
        if ($response === null) {
            $this->logger->warning("No response from BBS for command from {$nodeId}");
        }
        return $response;
    }

    private function maybePollOutbound(): void
    {
        if (time() - $this->lastPollAt < $this->cfg->pollIntervalSec) {
            return;
        }
        $this->lastPollAt = time();

        // Drop callsigns that have been idle beyond the active TTL.
        $cutoff = time() - $this->cfg->activeTtlSec;
        foreach ($this->activeCallsigns as $call => $ts) {
            if ($ts < $cutoff) {
                unset($this->activeCallsigns[$call]);
            }
        }

        foreach (array_keys($this->activeCallsigns) as $callsign) {
            $this->pollOutboundFor($callsign);
        }
    }

    private function pollOutboundFor(string $callsign): void
    {
        $url = $this->cfg->bbsUrl
            . '/api/packetbbs/pending?node_id=' . urlencode($callsign)
            . '&bridge_node_id=' . urlencode($this->cfg->bridgeNodeId);

        $response = $this->apiGet($url);
        if ($response === null) {
            return;
        }

        $data = json_decode($response, true);
        if (!is_array($data) || empty($data['messages'])) {
            return;
        }

        foreach ($data['messages'] as $msg) {
            $text = $msg['payload'] ?? '';
            if ($text !== '') {
                $this->logger->info("OUTBOUND for {$callsign}: " . substr($text, 0, 60));
                $this->transmitReply($callsign, $text);
            }
        }
    }

    /**
     * Transmit a text reply to a callsign, splitting across multiple frames if
     * the response exceeds the configured maximum frame info size.
     */
    private function transmitReply(string $destCallsign, string $text): void
    {
        $lines = explode("\n", $text);
        $chunk = '';

        foreach ($lines as $line) {
            $candidate = $chunk === '' ? $line : $chunk . "\n" . $line;

            if (strlen($candidate) > $this->cfg->maxFrameInfo) {
                if ($chunk !== '') {
                    $this->sendUiFrame($destCallsign, $chunk);
                }
                // Hard-split any single line that still exceeds the limit.
                while (strlen($line) > $this->cfg->maxFrameInfo) {
                    $this->sendUiFrame($destCallsign, substr($line, 0, $this->cfg->maxFrameInfo));
                    $line = substr($line, $this->cfg->maxFrameInfo);
                }
                $chunk = $line;
            } else {
                $chunk = $candidate;
            }
        }

        if ($chunk !== '') {
            $this->sendUiFrame($destCallsign, $chunk);
        }
    }

    private function maybeBeacon(): void
    {
        if (!$this->cfg->beaconEnabled) {
            return;
        }
        if (time() - $this->lastBeaconAt < $this->cfg->beaconIntervalSec) {
            return;
        }
        $this->sendBeacon();
    }

    private function sendBeacon(): void
    {
        if (!$this->cfg->beaconEnabled) {
            return;
        }
        $this->lastBeaconAt = time();
        $frame = Ax25Frame::buildUi($this->cfg->mycall, $this->cfg->beaconDest, $this->cfg->beaconText);
        $this->tnc->sendFrame($frame);
        $this->logger->info("BEACON > {$this->cfg->beaconDest}: {$this->cfg->beaconText}");
    }

    private function sendUiFrame(string $destCallsign, string $info): void
    {
        $frame = Ax25Frame::buildUi($this->cfg->mycall, $destCallsign, $info);
        $this->tnc->sendFrame($frame);
        $this->logger->debug("TX {$this->cfg->mycall} > {$destCallsign}: " . substr($info, 0, 80));
    }

    // -------------------------------------------------------------------------
    // HTTP helpers
    // -------------------------------------------------------------------------

    private function apiPost(string $url, string $jsonBody): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonBody,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->cfg->apiKey,
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->logger->error("API POST curl error: {$error}");
            return null;
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            $this->logger->error("API POST HTTP {$httpCode} for {$url}");
            return null;
        }

        return $response ?: null;
    }

    private function apiGet(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->cfg->apiKey,
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->logger->error("API GET curl error: {$error}");
            return null;
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            $this->logger->error("API GET HTTP {$httpCode} for {$url}");
            return null;
        }

        return $response ?: null;
    }
}
