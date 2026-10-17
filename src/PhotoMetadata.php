<?php

declare(strict_types=1);

namespace RealAdultMedia\PhotoMetadata;

/**
 * Takes the metadata out of a photo without touching its pixels.
 *
 * A phone photo carries where it was taken — GPS to a few metres — plus the
 * camera, the time and sometimes the owner's name. Strip it before anyone
 * else sees the file.
 *
 * Lossless: the image data is copied byte for byte and only the metadata
 * blocks are dropped. A JPEG's rotation lives in the same block as its GPS, so
 * a one-tag EXIF block holding just the orientation is put back; without it a
 * portrait photo would turn on its side.
 *
 *  - JPEG: drops APP1 (EXIF, XMP), APP13 (IPTC) and comments.
 *  - PNG: drops eXIf and the text chunks.
 *  - WebP: drops the EXIF and XMP chunks and clears their flags.
 *
 * Anything it cannot parse comes back unchanged.
 */
final class PhotoMetadata
{
    public static function strip(string $bytes, string $mimeType): string
    {
        try {
            return match ($mimeType) {
                'image/jpeg', 'image/jpg', 'image/pjpeg' => self::jpeg($bytes),
                'image/png' => self::png($bytes),
                'image/webp' => self::webp($bytes),
                default => $bytes,
            } ?? $bytes;
        } catch (\Throwable) {
            return $bytes;
        }
    }

    public static function handles(string $mimeType): bool
    {
        return in_array($mimeType, ['image/jpeg', 'image/jpg', 'image/pjpeg', 'image/png', 'image/webp'], true);
    }

    private static function jpeg(string $bytes): ?string
    {
        $length = strlen($bytes);

        if ($length < 4 || substr($bytes, 0, 2) !== "\xFF\xD8") {
            return null;
        }

        $kept = [];
        $orientation = null;
        $offset = 2;

        while ($offset + 4 <= $length) {
            if ($bytes[$offset] !== "\xFF") {
                return null;
            }

            $marker = ord($bytes[$offset + 1]);

            // Padding between segments.
            if ($marker === 0xFF) {
                $offset++;

                continue;
            }

            // Start of scan: the image data runs to the end, copied as is.
            if ($marker === 0xDA) {
                $kept[] = substr($bytes, $offset);
                $offset = $length;

                break;
            }

            // Markers with no length.
            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                $kept[] = substr($bytes, $offset, 2);
                $offset += 2;

                continue;
            }

            $segmentLength = unpack('n', substr($bytes, $offset + 2, 2))[1];

            if ($segmentLength < 2 || $offset + 2 + $segmentLength > $length) {
                return null;
            }

            $segment = substr($bytes, $offset, 2 + $segmentLength);

            if ($marker === 0xE1 && str_starts_with(substr($segment, 4), "Exif\0\0")) {
                $orientation ??= self::orientation(substr($segment, 10));
            }

            if (! in_array($marker, [0xE1, 0xED, 0xFE], true)) {
                $kept[] = $segment;
            }

            $offset += 2 + $segmentLength;
        }

        if ($offset < $length || $kept === []) {
            return null;
        }

        $exif = $orientation !== null && $orientation > 1 && $orientation <= 8 ? self::orientationSegment($orientation) : '';

        // A JFIF header stays first; the orientation block follows it.
        if ($exif !== '' && str_starts_with($kept[0], "\xFF\xE0")) {
            return "\xFF\xD8".$kept[0].$exif.implode('', array_slice($kept, 1));
        }

        return "\xFF\xD8".$exif.implode('', $kept);
    }

    /**
     * The orientation tag (0x0112) from a TIFF block, or null.
     */
    private static function orientation(string $tiff): ?int
    {
        if (strlen($tiff) < 8) {
            return null;
        }

        $big = substr($tiff, 0, 2) === 'MM';
        $short = fn (int $at): int => unpack($big ? 'n' : 'v', substr($tiff, $at, 2))[1];
        $long = fn (int $at): int => unpack($big ? 'N' : 'V', substr($tiff, $at, 4))[1];

        $ifd = $long(4);

        if ($ifd + 2 > strlen($tiff)) {
            return null;
        }

        $count = $short($ifd);

        for ($i = 0; $i < $count; $i++) {
            $entry = $ifd + 2 + $i * 12;

            if ($entry + 12 > strlen($tiff)) {
                return null;
            }

            if ($short($entry) === 0x0112) {
                return $short($entry + 8);
            }
        }

        return null;
    }

    private static function orientationSegment(int $orientation): string
    {
        $tiff = 'MM'.pack('n', 42).pack('N', 8)
            .pack('n', 1)
            .pack('n', 0x0112).pack('n', 3).pack('N', 1).pack('n', $orientation)."\0\0"
            .pack('N', 0);

        $payload = "Exif\0\0".$tiff;

        return "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;
    }

    private static function png(string $bytes): ?string
    {
        if (strlen($bytes) < 8 || substr($bytes, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            return null;
        }

        $out = substr($bytes, 0, 8);
        $offset = 8;
        $length = strlen($bytes);

        while ($offset + 12 <= $length) {
            $chunkLength = unpack('N', substr($bytes, $offset, 4))[1];
            $type = substr($bytes, $offset + 4, 4);
            $total = 12 + $chunkLength;

            if ($offset + $total > $length) {
                return null;
            }

            if (! in_array($type, ['eXIf', 'tEXt', 'zTXt', 'iTXt', 'tIME'], true)) {
                $out .= substr($bytes, $offset, $total);
            }

            $offset += $total;

            if ($type === 'IEND') {
                break;
            }
        }

        return $out;
    }

    private static function webp(string $bytes): ?string
    {
        if (strlen($bytes) < 12 || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WEBP') {
            return null;
        }

        $chunks = '';
        $offset = 12;
        $length = strlen($bytes);

        while ($offset + 8 <= $length) {
            $type = substr($bytes, $offset, 4);
            $size = unpack('V', substr($bytes, $offset + 4, 4))[1];
            $total = 8 + $size + ($size % 2);

            if ($offset + 8 + $size > $length) {
                return null;
            }

            $chunk = substr($bytes, $offset, min($total, $length - $offset));

            if ($type === 'VP8X' && $size >= 1) {
                // Clear the "has EXIF" (0x08) and "has XMP" (0x04) flags.
                $chunk[8] = chr(ord($chunk[8]) & ~0x0C);
            }

            if (! in_array($type, ['EXIF', 'XMP '], true)) {
                $chunks .= $chunk;
            }

            $offset += $total;
        }

        return 'RIFF'.pack('V', 4 + strlen($chunks)).'WEBP'.$chunks;
    }
}
