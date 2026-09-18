<?php

namespace App\Support\Security;

/**
 * Batch 67 — what a photo says about where it was taken, removed before anybody else
 * can download it.
 *
 * A phone camera writes the GPS position into every JPEG it takes (EXIF, APP1), and
 * `ImageOptimizer` only re-encodes — which is what drops EXIF — when a picture is
 * larger than 1600 px or 1.5 MB. A complaint photo, an errand receipt or an avatar that
 * the app had already shrunk passed through untouched, onto a **public** URL, carrying
 * the latitude and longitude of the customer's kitchen table. A food complaint is also
 * mirrored to the restaurant it is about (D-53).
 *
 * This removes the metadata **without re-encoding**, so the pixels are exactly the ones
 * that were uploaded:
 *
 *   * JPEG — every APP1 (EXIF, XMP), APP13 (IPTC / Photoshop) and COM segment, and the
 *     vendor APP3…APP12 / APP15 blocks cameras use for maker notes. APP0 (JFIF), APP2
 *     (the ICC colour profile) and APP14 (Adobe colour transform) stay, because
 *     removing them changes how the picture is drawn.
 *   * PNG — the `eXIf`, `tEXt`, `zTXt`, `iTXt` and `tIME` chunks.
 *   * WebP — the `EXIF` and `XMP ` chunks, with their two flags cleared in `VP8X`.
 *
 * A JPEG whose EXIF says it is rotated is reported back rather than stripped blindly:
 * the orientation lives in the very block being removed, so the caller has to bake the
 * rotation into the pixels first ({@see \App\Support\ImageOptimizer::optimise()} with
 * `force`) or the picture would be drawn on its side.
 */
final class ImageMetadata
{
    /**
     * Strip the file in place.
     *
     * @return array{stripped: bool, orientation: int} `orientation` is the EXIF value
     *                                                  that was found (1 = upright).
     */
    public static function strip(string $path): array
    {
        $bytes = @file_get_contents($path);

        if (! is_string($bytes) || $bytes === '') {
            return ['stripped' => false, 'orientation' => 1];
        }

        $result = match (true) {
            str_starts_with($bytes, "\xFF\xD8") => self::jpeg($bytes),
            str_starts_with($bytes, "\x89PNG\r\n\x1A\n") => self::png($bytes),
            str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => self::webp($bytes),
            default => null,
        };

        if ($result === null) {
            return ['stripped' => false, 'orientation' => 1];
        }

        [$clean, $orientation] = $result;

        if ($orientation !== 1) {
            // Leave the file alone; the caller re-encodes it upright, which drops every
            // block anyway.
            return ['stripped' => false, 'orientation' => $orientation];
        }

        if ($clean !== $bytes) {
            file_put_contents($path, $clean);
            clearstatcache(true, $path);

            return ['stripped' => true, 'orientation' => 1];
        }

        return ['stripped' => false, 'orientation' => 1];
    }

    /** Does this file still carry anything {@see strip()} removes? For tests and checks. */
    public static function hasMetadata(string $path): bool
    {
        $bytes = (string) @file_get_contents($path);
        $result = match (true) {
            str_starts_with($bytes, "\xFF\xD8") => self::jpeg($bytes),
            str_starts_with($bytes, "\x89PNG\r\n\x1A\n") => self::png($bytes),
            str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => self::webp($bytes),
            default => null,
        };

        return $result !== null && ($result[0] !== $bytes || $result[1] !== 1);
    }

    /** @return array{0: string, 1: int}|null */
    private static function jpeg(string $bytes): ?array
    {
        $out = "\xFF\xD8";
        $offset = 2;
        $length = strlen($bytes);
        $orientation = 1;

        while ($offset + 4 <= $length) {
            if ($bytes[$offset] !== "\xFF") {
                return null; // Not a well-formed marker stream; leave the file alone.
            }

            $marker = ord($bytes[$offset + 1]);

            // Fill bytes between markers.
            if ($marker === 0xFF) {
                $offset++;

                continue;
            }

            // Start of scan: everything after it is entropy-coded image data, copied as is.
            if ($marker === 0xDA) {
                $out .= substr($bytes, $offset);

                return [$out, $orientation];
            }

            // Markers without a length field.
            if ($marker === 0xD8 || $marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                $out .= substr($bytes, $offset, 2);
                $offset += 2;

                continue;
            }

            if ($marker === 0xD9) {
                $out .= substr($bytes, $offset, 2);

                return [$out, $orientation];
            }

            $segmentLength = (ord($bytes[$offset + 2]) << 8) | ord($bytes[$offset + 3]);

            if ($segmentLength < 2 || $offset + 2 + $segmentLength > $length) {
                return null;
            }

            $segment = substr($bytes, $offset, 2 + $segmentLength);

            if ($marker === 0xE1 && str_starts_with(substr($segment, 4), "Exif\0\0")) {
                $orientation = self::exifOrientation(substr($segment, 10)) ?? $orientation;
            }

            $drop = $marker === 0xE1               // EXIF, XMP
                || $marker === 0xED                  // IPTC / Photoshop IRB
                || $marker === 0xFE                  // COM
                || ($marker >= 0xE3 && $marker <= 0xEC) // APP3…APP12 maker notes
                || $marker === 0xEF;                 // APP15

            if (! $drop) {
                $out .= $segment;
            }

            $offset += 2 + $segmentLength;
        }

        return null;
    }

    /** The Orientation tag (0x0112) out of a TIFF-structured EXIF block. */
    private static function exifOrientation(string $tiff): ?int
    {
        if (strlen($tiff) < 8) {
            return null;
        }

        $order = substr($tiff, 0, 2);
        $little = $order === 'II';

        if (! $little && $order !== 'MM') {
            return null;
        }

        $u16 = fn (int $at): int => $little
            ? (ord($tiff[$at]) | (ord($tiff[$at + 1]) << 8))
            : ((ord($tiff[$at]) << 8) | ord($tiff[$at + 1]));
        $u32 = fn (int $at): int => $little
            ? (ord($tiff[$at]) | (ord($tiff[$at + 1]) << 8) | (ord($tiff[$at + 2]) << 16) | (ord($tiff[$at + 3]) << 24))
            : ((ord($tiff[$at]) << 24) | (ord($tiff[$at + 1]) << 16) | (ord($tiff[$at + 2]) << 8) | ord($tiff[$at + 3]));

        $ifd = $u32(4);

        if ($ifd + 2 > strlen($tiff)) {
            return null;
        }

        $entries = $u16($ifd);

        for ($i = 0; $i < $entries; $i++) {
            $entry = $ifd + 2 + $i * 12;

            if ($entry + 12 > strlen($tiff)) {
                return null;
            }

            if ($u16($entry) === 0x0112) {
                $value = $u16($entry + 8);

                return $value >= 1 && $value <= 8 ? $value : 1;
            }
        }

        return null;
    }

    /** @return array{0: string, 1: int}|null */
    private static function png(string $bytes): ?array
    {
        $drop = ['eXIf', 'tEXt', 'zTXt', 'iTXt', 'tIME'];
        $out = substr($bytes, 0, 8);
        $offset = 8;
        $length = strlen($bytes);

        while ($offset + 12 <= $length) {
            $size = unpack('N', substr($bytes, $offset, 4))[1];
            $type = substr($bytes, $offset + 4, 4);

            if ($offset + 12 + $size > $length) {
                return null;
            }

            if (! in_array($type, $drop, true)) {
                $out .= substr($bytes, $offset, 12 + $size);
            }

            $offset += 12 + $size;

            if ($type === 'IEND') {
                return [$out, 1];
            }
        }

        return null;
    }

    /** @return array{0: string, 1: int}|null */
    private static function webp(string $bytes): ?array
    {
        $length = strlen($bytes);
        $chunks = '';
        $offset = 12;
        $vp8xAt = null;

        while ($offset + 8 <= $length) {
            $type = substr($bytes, $offset, 4);
            $size = unpack('V', substr($bytes, $offset + 4, 4))[1];
            $padded = $size + ($size % 2);

            if ($offset + 8 + $size > $length) {
                return null;
            }

            if ($type !== 'EXIF' && $type !== 'XMP ') {
                if ($type === 'VP8X') {
                    $vp8xAt = strlen($chunks);
                }

                $chunks .= substr($bytes, $offset, 8 + $padded);
            }

            $offset += 8 + $padded;
        }

        if ($vp8xAt !== null && strlen($chunks) > $vp8xAt + 8) {
            // Flags byte: bit 3 = EXIF present, bit 2 = XMP present.
            $flags = ord($chunks[$vp8xAt + 8]) & ~0x0C;
            $chunks[$vp8xAt + 8] = chr($flags);
        }

        $riff = 'RIFF'.pack('V', 4 + strlen($chunks)).'WEBP'.$chunks;

        return [$riff, 1];
    }
}
