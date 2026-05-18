<?php

namespace BinktermPhpAx25Kiss;

/**
 * AX.25 frame parser and builder.
 *
 * Supports UI (connectionless) and connected-mode frames: I-frames,
 * supervisory frames (RR, REJ), and unnumbered frames (SABM, UA, DM, DISC).
 *
 * Address encoding: each callsign occupies 7 bytes. The first 6 are ASCII
 * characters shifted left by one bit (padded to 6 chars with spaces). The
 * 7th byte carries the C/R bit (bit 7), reserved bits (bits 6-5), SSID
 * (bits 4-1), and the end-of-address flag (bit 0).
 *
 * Sequence numbers use modulo-8 arithmetic.
 */
class Ax25Frame
{
    // Frame type identifiers
    const TYPE_I     = 'I';      // Information (connected data)
    const TYPE_RR    = 'RR';     // Receive Ready (supervisory ack)
    const TYPE_REJ   = 'REJ';    // Reject (supervisory nak — request retransmit)
    const TYPE_UI    = 'UI';     // Unnumbered Information (connectionless)
    const TYPE_SABM  = 'SABM';   // Set Async Balanced Mode (connect request)
    const TYPE_UA    = 'UA';     // Unnumbered Acknowledge
    const TYPE_DM    = 'DM';     // Disconnected Mode (reject connect or signal busy)
    const TYPE_DISC  = 'DISC';   // Disconnect
    const TYPE_OTHER = 'OTHER';  // Unsupported / unknown

    /** PID byte for plain text / no layer 3 protocol. */
    const PID_NO_L3 = 0xF0;

    // Frame properties
    public string $type      = self::TYPE_OTHER;
    public string $dest      = '';
    public string $src       = '';
    /** @var string[] Digipeater callsigns (usually empty). */
    public array  $repeaters = [];
    public string $info      = '';   // payload (UI and I-frames)
    public int    $ns        = 0;    // send sequence N(S) — I-frames only
    public int    $nr        = 0;    // receive sequence N(R) — I/S-frames
    public bool   $pf        = false; // poll (commands) / final (responses) bit
    public bool   $isCommand = false; // true = command frame, false = response

    // -------------------------------------------------------------------------
    // Parser
    // -------------------------------------------------------------------------

    /**
     * Parse a raw AX.25 frame of any type.
     *
     * Returns null only when the frame is structurally invalid. Frames with
     * unrecognised control bytes are returned with type TYPE_OTHER.
     */
    public static function parse(string $raw): ?self
    {
        if (strlen($raw) < 15) {
            return null;
        }

        $offset = 0;

        // Destination address (7 bytes) — C bit in dest SSID byte signals a command.
        $dest      = self::decodeCallsign(substr($raw, 0, 7));
        $isCommand = (bool)((ord($raw[6]) >> 7) & 0x01);
        $offset    = 7;

        // Source address (7 bytes)
        if ($offset + 7 > strlen($raw)) {
            return null;
        }
        $src       = self::decodeCallsign(substr($raw, $offset, 7));
        $srcEndBit = ord($raw[$offset + 6]) & 0x01;
        $offset   += 7;

        // Optional digipeater addresses
        $repeaters = [];
        if (!$srcEndBit) {
            while ($offset + 7 <= strlen($raw)) {
                $repeaters[] = self::decodeCallsign(substr($raw, $offset, 7));
                $digiEndBit  = ord($raw[$offset + 6]) & 0x01;
                $offset     += 7;
                if ($digiEndBit) {
                    break;
                }
            }
        }

        if ($offset >= strlen($raw)) {
            return null;
        }

        $ctrl   = ord($raw[$offset]);
        $offset++;

        $frame            = new self();
        $frame->dest      = $dest;
        $frame->src       = $src;
        $frame->repeaters = $repeaters;
        $frame->isCommand = $isCommand;

        if (($ctrl & 0x01) === 0) {
            // I-frame: bit 0 = 0
            $frame->type = self::TYPE_I;
            $frame->ns   = ($ctrl >> 1) & 0x07;
            $frame->pf   = (bool)(($ctrl >> 4) & 0x01);
            $frame->nr   = ($ctrl >> 5) & 0x07;
            if ($offset < strlen($raw)) {
                $frame->info = substr($raw, $offset + 1); // skip PID byte
            }
        } elseif (($ctrl & 0x03) === 0x01) {
            // S-frame: bits 1-0 = 01
            $frame->pf = (bool)(($ctrl >> 4) & 0x01);
            $frame->nr = ($ctrl >> 5) & 0x07;
            $frame->type = match (($ctrl >> 2) & 0x03) {
                0x00    => self::TYPE_RR,
                0x02    => self::TYPE_REJ,
                default => self::TYPE_OTHER,
            };
        } else {
            // U-frame: bits 1-0 = 11
            $frame->pf   = (bool)(($ctrl >> 4) & 0x01);
            $frame->type = match ($ctrl & 0xEF) { // mask out P/F bit
                0x03    => self::TYPE_UI,
                0x2F    => self::TYPE_SABM,
                0x63    => self::TYPE_UA,
                0x0F    => self::TYPE_DM,
                0x43    => self::TYPE_DISC,
                default => self::TYPE_OTHER,
            };
            if ($frame->type === self::TYPE_UI && $offset < strlen($raw)) {
                $frame->info = substr($raw, $offset + 1); // skip PID byte
            }
        }

        return $frame;
    }

    // -------------------------------------------------------------------------
    // Frame builders
    // -------------------------------------------------------------------------

    /** Connectionless UI frame. */
    public static function buildUi(string $src, string $dest, string $info): string
    {
        return self::addr($dest, false, false)
            . self::addr($src, true, false)
            . chr(0x03)
            . chr(self::PID_NO_L3)
            . $info;
    }

    /**
     * UA — unnumbered acknowledge (always a response).
     *
     * Sent in reply to SABM (accept connect) or DISC (confirm disconnect).
     */
    public static function buildUa(string $src, string $dest, bool $final = true): string
    {
        return self::addr($dest, false, false)  // dest C=0 (response)
            . self::addr($src, true, true)       // src  C=1 (response)
            . chr($final ? 0x73 : 0x63);
    }

    /**
     * DM — disconnected mode (always a response).
     *
     * Sent to reject a connect attempt or to signal the link is busy.
     */
    public static function buildDm(string $src, string $dest, bool $final = true): string
    {
        return self::addr($dest, false, false)
            . self::addr($src, true, true)
            . chr($final ? 0x1F : 0x0F);
    }

    /**
     * DISC — disconnect (always a command).
     *
     * Initiates link teardown. The remote responds with UA.
     */
    public static function buildDisc(string $src, string $dest, bool $poll = true): string
    {
        return self::addr($dest, false, true)   // dest C=1 (command)
            . self::addr($src, true, false)      // src  C=0 (command)
            . chr($poll ? 0x53 : 0x43);
    }

    /**
     * RR — receive ready / acknowledgment.
     *
     * Sent as a response by default ($command = false). Can also be sent
     * as a command (supervisory poll) by passing $command = true.
     */
    public static function buildRr(string $src, string $dest, int $nr, bool $pf = false, bool $command = false): string
    {
        $ctrl = (($nr & 0x07) << 5) | ($pf ? 0x10 : 0x00) | 0x01;
        return self::addr($dest, false, $command)
            . self::addr($src, true, !$command)
            . chr($ctrl);
    }

    /**
     * I-frame — connected information transfer (always a command).
     *
     * @param int    $ns   Send sequence number N(S) for this frame.
     * @param int    $nr   Receive sequence number N(R) acknowledging inbound frames.
     * @param string $info Payload text.
     * @param bool   $poll Set the Poll bit (rarely needed for BBS use).
     */
    public static function buildIFrame(string $src, string $dest, int $ns, int $nr, string $info, bool $poll = false): string
    {
        $ctrl = (($nr & 0x07) << 5) | ($poll ? 0x10 : 0x00) | (($ns & 0x07) << 1);
        return self::addr($dest, false, true)   // dest C=1 (command)
            . self::addr($src, true, false)      // src  C=0 (command)
            . chr($ctrl)
            . chr(self::PID_NO_L3)
            . $info;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private static function decodeCallsign(string $bytes): string
    {
        $call = '';
        for ($i = 0; $i < 6; $i++) {
            $char = chr(ord($bytes[$i]) >> 1);
            if ($char !== ' ') {
                $call .= $char;
            }
        }
        $ssid = (ord($bytes[6]) >> 1) & 0x0F;
        if ($ssid > 0) {
            $call .= '-' . $ssid;
        }
        return $call;
    }

    /**
     * Encode a callsign into a 7-byte AX.25 address field.
     *
     * @param bool $last True when this is the last address in the header.
     * @param bool $cBit True to set the C/R bit (bit 7 of the SSID byte).
     */
    private static function addr(string $callsign, bool $last, bool $cBit): string
    {
        $ssid     = 0;
        $callsign = strtoupper($callsign);

        if (($dash = strpos($callsign, '-')) !== false) {
            $ssid     = (int)substr($callsign, $dash + 1);
            $callsign = substr($callsign, 0, $dash);
        }

        $callsign = str_pad(substr($callsign, 0, 6), 6, ' ');
        $bytes    = '';
        for ($i = 0; $i < 6; $i++) {
            $bytes .= chr(ord($callsign[$i]) << 1);
        }

        $ssidByte = 0x60                        // bits 6-5: reserved, set per spec
            | ($cBit ? 0x80 : 0x00)             // bit 7: C/R
            | (($ssid & 0x0F) << 1)             // bits 4-1: SSID
            | ($last ? 0x01 : 0x00);            // bit 0: end-of-address

        return $bytes . chr($ssidByte);
    }
}
