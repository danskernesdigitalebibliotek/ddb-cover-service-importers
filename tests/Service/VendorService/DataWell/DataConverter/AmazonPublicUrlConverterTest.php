<?php
/**
 * @file
 * Test cases for the 'Iverse' image url converter.
 */

namespace Tests\Service\VendorService\DataWell\DataConverter;

use App\Service\VendorService\DataWell\DataConverter\AmazonPublicUrlConverter;
use PHPUnit\Framework\TestCase;

class AmazonPublicUrlConverterTest extends TestCase
{
    /**
     * Test that array of urls are changed correctly.
     */
    public function testConvertArrayValues(): void
    {
        // Convert 'Iverse' urls correctly
        $list = [
            '9788799783205' => 'https://s3.amazonaws.com/iverse_public/store/cover/padmedium/7775000045.png',
            '9788799997831' => 'https://s3.amazonaws.com/iverse_public/store/cover/padmedium/7775000045.png',
            '9788799996438' => 'https://s3.amazonaws.com/iverse_public/store/cover/padmedium/7775000045.png',
        ];

        $expected = [
            '9788799783205' => 'https://s3.amazonaws.com/iverse_public/store/cover/padlarge/7775000045.png',
            '9788799997831' => 'https://s3.amazonaws.com/iverse_public/store/cover/padlarge/7775000045.png',
            '9788799996438' => 'https://s3.amazonaws.com/iverse_public/store/cover/padlarge/7775000045.png',
        ];

        AmazonPublicUrlConverter::convertArrayValues($list);
        $this->assertEquals($expected, $list);

        // Non 'Iverse' urls should remain unchanged
        $list = [
            '9788799783205' => 'https://imgcdn.saxo.com/_9788799997831/0x0',
            '9788799997831' => 'https://images.bogportalen.dk/images/9789991866147.jpg',
            '9788799996438' => 'https://images.pubhub.dk/originals/ffe622ff-b267-40ea-a3ae-d1f2461075db.jpg',
        ];

        $expected = [
            '9788799783205' => 'https://imgcdn.saxo.com/_9788799997831/0x0',
            '9788799997831' => 'https://images.bogportalen.dk/images/9789991866147.jpg',
            '9788799996438' => 'https://images.pubhub.dk/originals/ffe622ff-b267-40ea-a3ae-d1f2461075db.jpg',
        ];

        AmazonPublicUrlConverter::convertArrayValues($list);
        $this->assertEquals($expected, $list);
    }

    /**
     * Test that single urls are changed correctly.
     */
    public function testConvertSingleUrl(): void
    {
        // Convert 'Iverse' urls correctly
        $original = 'https://s3.amazonaws.com/iverse_public/store/cover/padmedium/7775000045.png';
        $converted = AmazonPublicUrlConverter::convertSingleUrl($original);
        $this->assertEquals('https://s3.amazonaws.com/iverse_public/store/cover/padlarge/7775000045.png', $converted);

        $original = 'https://s3.amazonaws.com/iverse_public/store/cover/padmedium/0000000792.jpg';
        $converted = AmazonPublicUrlConverter::convertSingleUrl($original);
        $this->assertEquals('https://s3.amazonaws.com/iverse_public/store/cover/padlarge/0000000792.jpg', $converted);

        // Non 'Iverse' urls should remain unchanged
        $original = 'http://ebookcentral.proquest.com/covers/1752705-l.jpg';
        $converted = AmazonPublicUrlConverter::convertSingleUrl($original);
        $this->assertEquals($original, $converted);

        $original = 'https://images.bogportalen.dk/images/9788799783205.jpg';
        $converted = AmazonPublicUrlConverter::convertSingleUrl($original);
        $this->assertEquals($original, $converted);

        $original = 'https://imgcdn.saxo.com/_9788799997831/0x0';
        $converted = AmazonPublicUrlConverter::convertSingleUrl($original);
        $this->assertEquals($original, $converted);
    }
}
