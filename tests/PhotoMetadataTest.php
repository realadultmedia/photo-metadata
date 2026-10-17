<?php

declare(strict_types=1);

namespace RealAdultMedia\PhotoMetadata\Tests;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RealAdultMedia\PhotoMetadata\PhotoMetadata;

#[RequiresPhpExtension('gd')]
final class PhotoMetadataTest extends TestCase
{
    /**
     * A JPEG with an EXIF block holding an orientation and a GPS marker string,
     * the way a phone writes one.
     */
    private static function jpegWithExif(int $orientation): string
    {
        $image = imagecreatetruecolor(8, 4);
        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = (string) ob_get_clean();

        $tiff = 'II'.pack('v', 42).pack('V', 8)
            .pack('v', 2)
            .pack('v', 0x0112).pack('v', 3).pack('V', 1).pack('v', $orientation)."\0\0"
            .pack('v', 0x8825).pack('v', 4).pack('V', 1).pack('V', 38)
            .pack('V', 0)
            .'GPS 51.5007N 0.1246W';
        $payload = "Exif\0\0".$tiff;
        $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        return "\xFF\xD8".$app1.substr($jpeg, 2);
    }

    #[RequiresPhpExtension('exif')]
    public function test_it_takes_gps_out_of_a_jpeg_and_keeps_its_rotation_and_pixels(): void
    {
        $stripped = PhotoMetadata::strip(self::jpegWithExif(6), 'image/jpeg');

        $this->assertStringNotContainsString('GPS 51.5007N', $stripped);
        $this->assertNotFalse(imagecreatefromstring($stripped));

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($stripped));

        $this->assertSame(6, $exif['Orientation'] ?? null);
    }

    public function test_it_leaves_an_upright_jpeg_with_no_exif_block_at_all(): void
    {
        $stripped = PhotoMetadata::strip(self::jpegWithExif(1), 'image/jpeg');

        $this->assertStringNotContainsString('Exif', $stripped);
        $this->assertNotFalse(imagecreatefromstring($stripped));
    }

    public function test_it_drops_text_chunks_from_a_png(): void
    {
        $image = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        $text = 'tEXt'.'Location'."\0".'51.5007,-0.1246';
        $chunk = pack('N', strlen($text) - 4).$text.pack('N', crc32($text));
        $withText = substr($png, 0, 33).$chunk.substr($png, 33);

        $stripped = PhotoMetadata::strip($withText, 'image/png');

        $this->assertStringNotContainsString('51.5007', $stripped);
        $this->assertNotFalse(imagecreatefromstring($stripped));
    }

    public function test_it_gives_back_anything_it_cannot_parse_unchanged(): void
    {
        $this->assertSame('not an image', PhotoMetadata::strip('not an image', 'image/jpeg'));
        $this->assertSame('anything', PhotoMetadata::strip('anything', 'video/mp4'));
    }

    public function test_it_says_which_types_it_handles(): void
    {
        $this->assertTrue(PhotoMetadata::handles('image/jpeg'));
        $this->assertTrue(PhotoMetadata::handles('image/png'));
        $this->assertTrue(PhotoMetadata::handles('image/webp'));
        $this->assertFalse(PhotoMetadata::handles('image/gif'));
    }
}
