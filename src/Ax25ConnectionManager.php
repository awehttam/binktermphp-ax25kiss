<?php

namespace BinktermPhpAx25Kiss;

/**
 * Manages all active AX.25 connected-mode sessions.
 *
 * Responsibilities:
 *   - Accept incoming SABM frames and create new Ax25Connection instances.
 *   - Route non-UI frames to the correct connection.
 *   - Surface received I-frame payloads to the BBS command handler.
 *   - Deliver BBS responses back to the connected station as I-frames.
 *   - Tick all connections for T1 timer management.
 *   - Send DM to reject frames from unknown stations.
 */
class Ax25ConnectionManager
{
    /** @var array<string, Ax25Connection> uppercase callsign => connection */
    private array $connections = [];

    private string   $localCall;
    private KissTnc  $tnc;
    private Logger   $logger;
    private int      $maxFrameInfo;

    /**
     * Called when an I-frame payload arrives from a connected station.
     * Signature: fn(string $nodeId, string $command): ?string
     *
     * @var \Closure
     */
    private \Closure $commandHandler;

    public function __construct(
        string   $localCall,
        KissTnc  $tnc,
        Logger   $logger,
        int      $maxFrameInfo,
        \Closure $commandHandler
    ) {
        $this->localCall      = strtoupper($localCall);
        $this->tnc            = $tnc;
        $this->logger         = $logger;
        $this->maxFrameInfo   = $maxFrameInfo;
        $this->commandHandler = $commandHandler;
    }

    // -------------------------------------------------------------------------
    // Public interface
    // -------------------------------------------------------------------------

    /**
     * Handle an incoming non-UI frame.
     *
     * Creates a new connection on SABM; routes all other frames to the
     * matching existing connection or sends DM if no session exists.
     */
    public function handleFrame(Ax25Frame $frame): void
    {
        $remote = strtoupper($frame->src);

        if ($frame->type === Ax25Frame::TYPE_SABM) {
            $this->handleSabm($frame, $remote);
            return;
        }

        $conn = $this->connections[$remote] ?? null;
        if ($conn === null) {
            $this->tnc->sendFrame(Ax25Frame::buildDm($this->localCall, $remote));
            return;
        }

        foreach ($conn->handleFrame($frame) as $raw) {
            $this->tnc->sendFrame($raw);
        }

        $info = $conn->getAndClearInfo();
        if ($info !== null) {
            $text = trim($info);
            if ($text !== '') {
                $this->logger->debug("CONN RX {$remote}: " . substr($text, 0, 80));
                $response = ($this->commandHandler)($remote, $text);
                if ($response !== null && $response !== '') {
                    $this->deliverToConnection($conn, $response);
                }
            }
        }

        $this->removeIfDone($remote, $conn);
    }

    /**
     * Periodic tick — call from the main bridge loop on every iteration.
     */
    public function tick(): void
    {
        foreach ($this->connections as $remote => $conn) {
            foreach ($conn->tick() as $raw) {
                $this->tnc->sendFrame($raw);
            }
            $this->removeIfDone($remote, $conn);
        }
    }

    /** True when the given callsign has an active connected session. */
    public function hasConnection(string $callsign): bool
    {
        return isset($this->connections[strtoupper($callsign)]);
    }

    /**
     * Send text to an existing connected session via I-frames.
     *
     * Silently ignored when no session exists for the callsign.
     */
    public function sendData(string $callsign, string $text): void
    {
        $conn = $this->connections[strtoupper($callsign)] ?? null;
        if ($conn !== null) {
            $this->deliverToConnection($conn, $text);
        }
    }

    /** Return the callsigns of all currently active connected sessions. */
    public function connectedCallsigns(): array
    {
        return array_keys($this->connections);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function handleSabm(Ax25Frame $frame, string $remote): void
    {
        if (isset($this->connections[$remote])) {
            // Re-connect from an already-connected station — reset the session.
            $this->logger->info("AX25 SABM from {$remote} (re-connect, resetting session)");
            unset($this->connections[$remote]);
        } else {
            $this->logger->info("AX25 CONNECT from {$remote}");
        }

        $conn = new Ax25Connection($remote, $this->localCall, $this->logger);
        $this->connections[$remote] = $conn;

        // Accept the connection.
        $this->tnc->sendFrame(Ax25Frame::buildUa($this->localCall, $remote, $frame->pf));

        // Send a welcome banner before the operator types anything.
        $welcome = "[{$this->localCall}] BinktermPHP PacketBBS. Send HELP for commands. QUIT to disconnect.";
        $this->deliverToConnection($conn, $welcome);
    }

    /**
     * Split text into chunks that fit within the maximum I-frame info size
     * and queue them on the connection's send queue.
     */
    private function deliverToConnection(Ax25Connection $conn, string $text): void
    {
        $lines = explode("\n", $text);
        $chunk = '';

        $flush = function (string $c) use ($conn): void {
            foreach ($conn->sendData($c) as $raw) {
                $this->tnc->sendFrame($raw);
            }
        };

        foreach ($lines as $line) {
            $candidate = $chunk === '' ? $line : $chunk . "\n" . $line;
            if (strlen($candidate) > $this->maxFrameInfo) {
                if ($chunk !== '') {
                    $flush($chunk);
                }
                while (strlen($line) > $this->maxFrameInfo) {
                    $flush(substr($line, 0, $this->maxFrameInfo));
                    $line = substr($line, $this->maxFrameInfo);
                }
                $chunk = $line;
            } else {
                $chunk = $candidate;
            }
        }

        if ($chunk !== '') {
            $flush($chunk);
        }
    }

    private function removeIfDone(string $remote, Ax25Connection $conn): void
    {
        if (!$conn->isConnected()) {
            unset($this->connections[$remote]);
            $this->logger->info("AX25 session removed: {$remote}");
        }
    }
}
