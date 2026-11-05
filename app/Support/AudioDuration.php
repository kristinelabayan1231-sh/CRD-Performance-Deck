<?php

namespace App\Support;

/**
 * A recording's length in seconds, read from the file itself: used when the phone doesn't send the call duration.
 * Reads the formats Android dialers save calls in: M4A/3GP (MP4), WAV, MP3 and AMR. Null when it can't tell.
 */
class AudioDuration
{
    /** MP3 bitrates (kbps) by header index: MPEG-1 Layer III, then MPEG-2/2.5 Layer III. */
    private const MP3_BITRATES = [
        1 => [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320],
        2 => [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160],
    ];

    /** MP3 sample rates by version bits (0 = MPEG-2.5, 2 = MPEG-2, 3 = MPEG-1). */
    private const MP3_SAMPLE_RATES = [0 => [11025, 12000, 8000], 2 => [22050, 24000, 16000], 3 => [44100, 48000, 32000]];

    /** AMR-NB frame sizes in bytes (after the 1-byte header) by mode; each frame is 20 ms. */
    private const AMR_FRAME_BYTES = [12, 13, 15, 17, 19, 20, 26, 31, 5, 0, 0, 0, 0, 0, 0, 0];

    public static function seconds(string $contents): ?int
    {
        $seconds = match (true) {
            str_starts_with($contents, "#!AMR\n") => self::amr($contents),
            str_starts_with($contents, 'RIFF') && substr($contents, 8, 4) === 'WAVE' => self::wav($contents),
            substr($contents, 4, 4) === 'ftyp' => self::mp4($contents),
            default => self::mp3($contents),
        };

        return $seconds !== null && $seconds > 0 ? (int) round($seconds) : null;
    }

    /**
     * The movie header (mvhd): its timescale and duration.
     */
    private static function mp4(string $contents): ?float
    {
        $at = strpos($contents, 'mvhd');

        if ($at === false || strlen($contents) < $at + 32) {
            return null;
        }

        $version = ord($contents[$at + 4]);
        [$timescale, $duration] = $version === 1
            ? [unpack('N', substr($contents, $at + 24, 4))[1], unpack('J', substr($contents, $at + 28, 8))[1]]
            : [unpack('N', substr($contents, $at + 16, 4))[1], unpack('N', substr($contents, $at + 20, 4))[1]];

        return $timescale > 0 ? $duration / $timescale : null;
    }

    /**
     * The data chunk's size over the fmt chunk's bytes per second.
     */
    private static function wav(string $contents): ?float
    {
        $byteRate = null;
        $offset = 12;

        while ($offset + 8 <= strlen($contents)) {
            $id = substr($contents, $offset, 4);
            $size = unpack('V', substr($contents, $offset + 4, 4))[1];

            if ($id === 'fmt ') {
                $byteRate = unpack('V', substr($contents, $offset + 16, 4))[1];
            } elseif ($id === 'data') {
                return $byteRate ? $size / $byteRate : null;
            }

            $offset += 8 + $size + ($size % 2);
        }

        return null;
    }

    /**
     * The Xing/Info frame count when there is one (variable bitrate), else the audio size over the first frame's bitrate.
     */
    private static function mp3(string $contents): ?float
    {
        $offset = 0;

        if (str_starts_with($contents, 'ID3') && strlen($contents) >= 10) {
            // The ID3 tag size is "syncsafe": 7 bits per byte.
            $bytes = array_map('ord', str_split(substr($contents, 6, 4)));
            $offset = 10 + (($bytes[0] << 21) | ($bytes[1] << 14) | ($bytes[2] << 7) | $bytes[3]);
        }

        // The first frame sync (11 set bits) within the first 64 KB after the tag.
        $limit = min(strlen($contents) - 4, $offset + 65536);
        while ($offset < $limit && ! (ord($contents[$offset]) === 0xFF && (ord($contents[$offset + 1]) & 0xE0) === 0xE0)) {
            $offset++;
        }

        if ($offset >= $limit) {
            return null;
        }

        $header = unpack('N', substr($contents, $offset, 4))[1];
        $version = ($header >> 19) & 0x3;
        $layer = ($header >> 17) & 0x3;
        $bitrateIndex = ($header >> 12) & 0xF;
        $rateIndex = ($header >> 10) & 0x3;

        // Layer III only (01), with a known bitrate and sample rate.
        if ($layer !== 1 || $version === 1 || $bitrateIndex === 0 || $bitrateIndex === 15 || $rateIndex === 3) {
            return null;
        }

        $sampleRate = self::MP3_SAMPLE_RATES[$version][$rateIndex];
        $samplesPerFrame = $version === 3 ? 1152 : 576;
        $xing = max(strpos(substr($contents, $offset, 200), 'Xing'), strpos(substr($contents, $offset, 200), 'Info'));

        if ($xing !== false && (unpack('N', substr($contents, $offset + $xing + 4, 4))[1] & 0x1)) {
            return unpack('N', substr($contents, $offset + $xing + 8, 4))[1] * $samplesPerFrame / $sampleRate;
        }

        $kbps = self::MP3_BITRATES[$version === 3 ? 1 : 2][$bitrateIndex];

        return (strlen($contents) - $offset) * 8 / ($kbps * 1000);
    }

    /**
     * Counts the 20 ms frames.
     */
    private static function amr(string $contents): ?float
    {
        $frames = 0;
        $offset = 6;
        $length = strlen($contents);

        while ($offset < $length) {
            $offset += 1 + self::AMR_FRAME_BYTES[(ord($contents[$offset]) >> 3) & 0x0F];
            $frames++;
        }

        return $frames * 0.02;
    }
}
