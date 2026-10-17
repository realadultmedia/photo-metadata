# photo-metadata

Strip location, camera and owner data from photos without touching a pixel.

A phone photo can carry where it was taken to within a few metres, plus the camera, the time and sometimes the owner's name. Re-encoding the image drops all of that, but it costs quality and time. `photo-metadata` copies the image data byte for byte and removes only the metadata.

```php
use RealAdultMedia\PhotoMetadata\PhotoMetadata;

$clean = PhotoMetadata::strip($bytes, 'image/jpeg');
```

## Install

```bash
composer require realadultmedia/photo-metadata
```

PHP 8.2 or newer. No extensions and no dependencies.

## What it removes

| Format | Removed |
| --- | --- |
| JPEG | APP1 (EXIF, XMP), APP13 (IPTC) and comments |
| PNG | the `eXIf` chunk, the `tEXt`, `zTXt` and `iTXt` text chunks, and `tIME` |
| WebP | the `EXIF` and `XMP ` chunks, and their flags in the `VP8X` header |

## The rotation catch

A JPEG keeps its orientation in the same EXIF block as its GPS. Drop the block and every portrait photo turns on its side. So when the original had a rotation, `strip()` puts back a tiny EXIF block that holds only the orientation tag. Upright photos come back with no EXIF block at all.

## When it can't parse a file

`strip()` never throws. Anything it doesn't recognise, or can't parse, comes back exactly as it went in. Use `PhotoMetadata::handles($mimeType)` to check whether a type is supported before you rely on the result.

## Tests

```bash
composer install
composer test
```

The tests use the `gd` and `exif` extensions to build and read sample images. The library itself needs neither.

## Security

Please report security issues privately. See [SECURITY.md](https://github.com/realadultmedia/.github/blob/main/SECURITY.md).

## License

MIT. Built and used in production by [Real Adult Media](https://www.realadult.media).
