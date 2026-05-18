<?php

namespace BinktermPhpAx25Kiss;

/**
 * KISS (Keep It Simple, Stupid) TNC protocol framing.
 *
 * KISS is a thin framing layer used between a host and a TNC to exchange raw
 * AX.25 frames over a byte-stream connection (serial or TCP).
 *
 * Reference: https://www.ax25.net/kiss.aspx
 */
class KissProtocol
{
    /** Frame end/start delimiter. */
    const FEND  = "\xC0";

    /** Escape byte. */
    const FESC  = "\xDB";

    /** Transposed FEND — used inside the payload in place of a literal FEND. */
    const TFEND = "\xDC";

    /** Transposed FESC — used inside the payload in place of a literal FESC. */
    const TFESC = "\xDD";

    /**
     * Encode a raw AX.25 frame as a KISS data packet for the given TNC port.
     *
     * @param string $ax25Frame Raw AX.25 frame bytes
     * @param int    $port      TNC port number (0-7, default 0)
     */
    public static function encode(string $ax25Frame, int $port = 0): string
    {
        $cmdByte = chr(($port & 0x0F) << 4); // upper nibble = port, lower nibble = 0 (data)
        return self::FEND . $cmdByte . self::escapeData($ax25Frame) . self::FEND;
    }

    /**
     * Extract all complete AX.25 frames from a streaming receive buffer.
     *
     * Consumes processed bytes from $buffer in-place. Incomplete frames at the
     * end of the buffer are left for the next call.
     *
     * @param string $buffer Reference to the raw byte accumulation buffer
     * @return string[]      Unescaped AX.25 frame payloads (command byte stripped)
     */
    public static function extractFrames(string &$buffer): array
    {
        $frames = [];

        while (true) {
            $start = strpos($buffer, self::FEND);
            if ($start === false) {
                // No delimiter in buffer yet — discard leading garbage and wait.
                $buffer = '';
                break;
            }

            $end = strpos($buffer, self::FEND, $start + 1);
            if ($end === false) {
                // Trim leading garbage before first FEND; wait for the closing FEND.
                $buffer = substr($buffer, $start);
                break;
            }

            if ($end === $start + 1) {
                // Two consecutive FENDs — empty inter-frame boundary, skip.
                $buffer = substr($buffer, $end);
                continue;
            }

            $raw    = substr($buffer, $start + 1, $end - $start - 1);
            $buffer = substr($buffer, $end); // leave the trailing FEND for the next iteration

            if (strlen($raw) < 2) {
                continue;
            }

            $cmdByte = ord($raw[0]);
            $type    = $cmdByte & 0x0F;

            // Only pass through data frames (type 0x00). Ignore SetHardware etc.
            if ($type !== 0x00) {
                continue;
            }

            $frames[] = self::unescapeData(substr($raw, 1));
        }

        return $frames;
    }

    private static function escapeData(string $data): string
    {
        $out = '';
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            if ($data[$i] === self::FEND) {
                $out .= self::FESC . self::TFEND;
            } elseif ($data[$i] === self::FESC) {
                $out .= self::FESC . self::TFESC;
            } else {
                $out .= $data[$i];
            }
        }
        return $out;
    }

    private static function unescapeData(string $data): string
    {
        $out = '';
        $len = strlen($data);
        $i   = 0;
        while ($i < $len) {
            if ($data[$i] === self::FESC && $i + 1 < $len) {
                $i++;
                if ($data[$i] === self::TFEND) {
                    $out .= self::FEND;
                } elseif ($data[$i] === self::TFESC) {
                    $out .= self::FESC;
                }
                // Malformed escape sequences are silently dropped.
            } else {
                $out .= $data[$i];
            }
            $i++;
        }
        return $out;
    }
}
