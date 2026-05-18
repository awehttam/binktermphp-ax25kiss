<?php

namespace BinktermPhpAx25Kiss;

/**
 * AX.25 UI frame parser and builder.
 *
 * Each AX.25 address is encoded as a 7-byte field: 6 bytes of callsign
 * characters each shifted left by one bit, followed by one byte containing
 * the SSID (bits 4-1) and the end-of-address flag (bit 0).
 *
 * Only unnumbered information (UI) frames are handled here, as packet BBS
 * communication over KISS is connectionless.
 */
class Ax25Frame
{
    /** AX.25 UI frame control byte. */
    const CTRL_UI = 0x03;

    /** PID for "no layer 3" — plain text payloads. */
    const PID_NO_L3 = 0xF0;

    public string $dest;
    public string $src;
    /** @var string[] Digipeater callsigns (may be empty). */
    public array  $repeaters = [];
    public string $info      = '';

    /**
     * Parse a raw AX.25 frame.
     *
     * Returns null when the frame is malformed or is not a UI frame.
     */
    public static function parse(string $raw): ?self
    {
        // Minimum: dest(7) + src(7) + ctrl(1) + pid(1) = 16 bytes
        if (strlen($raw) < 16) {
            return null;
        }

        $offset = 0;

        $dest   = self::decodeCallsign(substr($raw, 0, 7));
        $offset = 7;

        $src        = self::decodeCallsign(substr($raw, $offset, 7));
        $srcEndBit  = ord($raw[$offset + 6]) & 0x01;
        $offset    += 7;

        $repeaters = [];
        if (!$srcEndBit) {
            // Digipeater addresses follow until the end-of-address bit is set.
            while ($offset + 7 <= strlen($raw)) {
                $repeaters[]  = self::decodeCallsign(substr($raw, $offset, 7));
                $digiEndBit   = ord($raw[$offset + 6]) & 0x01;
                $offset      += 7;
                if ($digiEndBit) {
                    break;
                }
            }
        }

        if ($offset + 2 > strlen($raw)) {
            return null;
        }

        $ctrl = ord($raw[$offset]);
        $info = substr($raw, $offset + 2); // skip ctrl + pid

        // Only handle UI frames.
        if ($ctrl !== self::CTRL_UI) {
            return null;
        }

        $frame            = new self();
        $frame->dest      = $dest;
        $frame->src       = $src;
        $frame->repeaters = $repeaters;
        $frame->info      = $info;

        return $frame;
    }

    /**
     * Build a raw AX.25 UI frame.
     *
     * @param string $src  Source callsign (e.g. "N0BBS-1")
     * @param string $dest Destination callsign (e.g. "W1AW-5")
     * @param string $info Information field payload
     */
    public static function buildUi(string $src, string $dest, string $info): string
    {
        return self::encodeCallsign($dest, false)
            . self::encodeCallsign($src, true)
            . chr(self::CTRL_UI)
            . chr(self::PID_NO_L3)
            . $info;
    }

    /**
     * Decode a 7-byte AX.25 address field into a printable callsign string.
     */
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
     * Encode a printable callsign into a 7-byte AX.25 address field.
     *
     * @param bool $last True when this is the last address in the frame header.
     */
    private static function encodeCallsign(string $callsign, bool $last): string
    {
        $ssid     = 0;
        $callsign = strtoupper($callsign);

        if (($dash = strpos($callsign, '-')) !== false) {
            $ssid     = (int)substr($callsign, $dash + 1);
            $callsign = substr($callsign, 0, $dash);
        }

        // Pad or truncate to exactly 6 characters; shift each byte left by one.
        $callsign = str_pad(substr($callsign, 0, 6), 6, ' ');
        $bytes    = '';
        for ($i = 0; $i < 6; $i++) {
            $bytes .= chr(ord($callsign[$i]) << 1);
        }

        // SSID byte: bits 7-6 set per AX.25 spec (0x60), SSID in bits 4-1,
        // end-of-address flag in bit 0.
        $bytes .= chr(0x60 | (($ssid & 0x0F) << 1) | ($last ? 0x01 : 0x00));

        return $bytes;
    }
}
