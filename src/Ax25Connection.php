<?php

namespace BinktermPhpAx25Kiss;

/**
 * AX.25 connected-mode (SABM/UA) session state machine for one remote station.
 *
 * Uses modulo-8 I-frame sequencing with a send window of K=1 (one outstanding
 * unacknowledged frame at a time) and a T1 retransmit timer.
 *
 * Usage:
 *   1. Instantiate after receiving and accepting a SABM (UA already sent by caller).
 *   2. Call handleFrame() for every subsequent frame from this callsign.
 *      Transmit all returned raw byte strings via the TNC.
 *   3. After handleFrame(), call getAndClearInfo() to retrieve any payload
 *      from an accepted I-frame.
 *   4. Call sendData() to queue outgoing text as I-frames.
 *   5. Call tick() on each main-loop iteration to handle timer expiry.
 *   6. Check isConnected() after every call; when false, remove the session.
 */
class Ax25Connection
{
    const STATE_CONNECTED     = 'connected';
    const STATE_DISCONNECTING = 'disconnecting';

    const MAX_RETRIES = 3;
    const T1_SECONDS  = 10.0;
    const MODULO      = 8;

    public readonly string $remoteCall;

    private string  $localCall;
    private string  $state;
    private int     $vs      = 0;   // next send sequence number
    private int     $vr      = 0;   // next expected receive sequence number
    private int     $va      = 0;   // sequence number of last acknowledged send
    private int     $retries = 0;
    private float   $t1Expires = 0.0;

    /** Data of the I-frame currently awaiting acknowledgment (null = window open). */
    private ?string $pendingData = null;

    /** Queue of text chunks waiting to be sent as I-frames. */
    private array $sendQueue = [];

    /** Info field of the most recently accepted I-frame, cleared by getAndClearInfo(). */
    private ?string $receivedInfo = null;

    private Logger $logger;

    public function __construct(string $remoteCall, string $localCall, Logger $logger)
    {
        $this->remoteCall = strtoupper($remoteCall);
        $this->localCall  = strtoupper($localCall);
        $this->state      = self::STATE_CONNECTED;
        $this->logger     = $logger;
    }

    // -------------------------------------------------------------------------
    // Public interface
    // -------------------------------------------------------------------------

    /**
     * Process an incoming frame for this connection.
     *
     * @return string[] Raw AX.25 byte strings to transmit.
     */
    public function handleFrame(Ax25Frame $frame): array
    {
        return match ($frame->type) {
            Ax25Frame::TYPE_I    => $this->onIFrame($frame),
            Ax25Frame::TYPE_RR   => $this->onRr($frame),
            Ax25Frame::TYPE_REJ  => $this->onRej($frame),
            Ax25Frame::TYPE_DISC => $this->onDisc($frame),
            Ax25Frame::TYPE_UA   => $this->onUa($frame),
            default              => [],
        };
    }

    /**
     * Queue text for transmission as I-frames.
     *
     * Returns any frames that can be sent immediately (window open).
     *
     * @return string[]
     */
    public function sendData(string $data): array
    {
        $this->sendQueue[] = $data;
        return $this->drainSendQueue();
    }

    /**
     * Periodic housekeeping. Call on every main-loop iteration.
     *
     * Retransmits unacknowledged frames on T1 expiry, or sends DISC after
     * MAX_RETRIES failures.
     *
     * @return string[]
     */
    public function tick(): array
    {
        if ($this->t1Expires === 0.0 || microtime(true) < $this->t1Expires) {
            return [];
        }

        if ($this->retries >= self::MAX_RETRIES) {
            $this->logger->warning("AX25 {$this->remoteCall}: T1 max retries — disconnecting");
            $this->state     = self::STATE_DISCONNECTING;
            $this->t1Expires = 0.0;
            return [Ax25Frame::buildDisc($this->localCall, $this->remoteCall)];
        }

        $this->retries++;
        $this->logger->debug("AX25 {$this->remoteCall}: T1 retransmit #{$this->retries}");
        return $this->retransmit();
    }

    /** True while the link is in the CONNECTED state. */
    public function isConnected(): bool
    {
        return $this->state === self::STATE_CONNECTED;
    }

    /**
     * Retrieve and clear the payload of the most recently accepted I-frame.
     *
     * Returns null when no new data has arrived since the last call.
     */
    public function getAndClearInfo(): ?string
    {
        $info               = $this->receivedInfo;
        $this->receivedInfo = null;
        return $info;
    }

    /**
     * Initiate a local disconnect. Returns DISC frame bytes to transmit.
     *
     * After calling this, isConnected() returns false.
     *
     * @return string[]
     */
    public function initiateDisconnect(): array
    {
        $this->state     = self::STATE_DISCONNECTING;
        $this->t1Expires = 0.0;
        return [Ax25Frame::buildDisc($this->localCall, $this->remoteCall)];
    }

    // -------------------------------------------------------------------------
    // Frame handlers
    // -------------------------------------------------------------------------

    /** @return string[] */
    private function onIFrame(Ax25Frame $frame): array
    {
        if ($this->state !== self::STATE_CONNECTED) {
            return [Ax25Frame::buildDm($this->localCall, $this->remoteCall)];
        }

        $this->acknowledgeThrough($frame->nr);

        if ($frame->ns !== $this->vr) {
            // Out-of-sequence — request retransmit from the remote.
            $this->logger->debug(
                "AX25 {$this->remoteCall}: out-of-seq N(S)={$frame->ns} expected {$this->vr}"
            );
            return [Ax25Frame::buildRr($this->localCall, $this->remoteCall, $this->vr, true)];
        }

        $this->vr           = ($this->vr + 1) % self::MODULO;
        $this->receivedInfo = $frame->info;

        $out = [Ax25Frame::buildRr($this->localCall, $this->remoteCall, $this->vr)];
        return array_merge($out, $this->drainSendQueue());
    }

    /** @return string[] */
    private function onRr(Ax25Frame $frame): array
    {
        $this->acknowledgeThrough($frame->nr);
        return $this->drainSendQueue();
    }

    /** @return string[] */
    private function onRej(Ax25Frame $frame): array
    {
        $this->acknowledgeThrough($frame->nr);
        return $this->retransmit();
    }

    /** @return string[] */
    private function onDisc(Ax25Frame $frame): array
    {
        $this->logger->info("AX25 {$this->remoteCall}: DISC received");
        $this->state     = self::STATE_DISCONNECTING;
        $this->t1Expires = 0.0;
        return [Ax25Frame::buildUa($this->localCall, $this->remoteCall, $frame->pf)];
    }

    /** @return string[] */
    private function onUa(Ax25Frame $frame): array
    {
        // UA in response to our DISC — link is now fully down.
        if ($this->state === self::STATE_DISCONNECTING) {
            $this->logger->info("AX25 {$this->remoteCall}: disconnect confirmed by UA");
        }
        return [];
    }

    // -------------------------------------------------------------------------
    // Sequence / send-queue helpers
    // -------------------------------------------------------------------------

    private function acknowledgeThrough(int $nr): void
    {
        if ($nr === $this->va) {
            return;
        }
        $this->va          = $nr;
        $this->retries     = 0;
        $this->t1Expires   = 0.0;
        $this->pendingData = null;
    }

    /**
     * Send the next queued chunk if the window is open (K=1: no pending frame).
     *
     * @return string[]
     */
    private function drainSendQueue(): array
    {
        if ($this->pendingData !== null || empty($this->sendQueue)) {
            return [];
        }

        $data              = array_shift($this->sendQueue);
        $this->pendingData = $data;

        $frame = Ax25Frame::buildIFrame(
            $this->localCall,
            $this->remoteCall,
            $this->vs,
            $this->vr,
            $data
        );

        $this->vs        = ($this->vs + 1) % self::MODULO;
        $this->retries   = 0;
        $this->t1Expires = microtime(true) + self::T1_SECONDS;

        return [$frame];
    }

    /**
     * Retransmit the pending (unacknowledged) I-frame.
     *
     * The pending frame's N(S) equals VA (the sequence number that was
     * sent but not yet acknowledged).
     *
     * @return string[]
     */
    private function retransmit(): array
    {
        if ($this->pendingData === null) {
            return [];
        }

        $frame = Ax25Frame::buildIFrame(
            $this->localCall,
            $this->remoteCall,
            $this->va,
            $this->vr,
            $this->pendingData
        );

        $this->t1Expires = microtime(true) + self::T1_SECONDS;
        return [$frame];
    }
}
