<?php

namespace App\DataFixtures\Faker\Provider;

use Faker\Provider\Base as BaseProvider;
use Faker\Provider\Miscellaneous;

final class ImageProvider extends BaseProvider
{
    private const FORMAT_PROVIDER = ['JPEG', 'GIF', 'PNG', 'TIFF', 'RAW', 'BMP'];
    private const COVER_STORE_URL_PROVIDER = ['https://images.bogportalen.dk/images/'];

    public function orginalFile($format): string
    {
        switch ($format) {
            case 'JPEG':
                $fileExt = 'jpg';
                break;
            case 'TIFF':
                $fileExt = 'tif';
                break;
            default:
                $fileExt = strtolower((string) $format);
        }

        return Miscellaneous::sha1().'.'.$fileExt;
    }

    public function originalImageFormat(): string
    {
        return strtolower((string) self::randomElement(self::FORMAT_PROVIDER));
    }

    public function size(int $width, int $height): int
    {
        return self::numberBetween(128, 10000);
    }

    public function height(): int
    {
        return self::numberBetween(128, 10000);
    }

    public function width(): int
    {
        return self::numberBetween(128, 10000);
    }

    public function coverStoreURL(): string
    {
        return self::randomElement(self::COVER_STORE_URL_PROVIDER).Miscellaneous::sha1().'.jpg';
    }
}
