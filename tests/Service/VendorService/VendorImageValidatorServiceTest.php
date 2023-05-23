<?php

/**
 * @file
 * Test cases for the Vendor image validation service.
 */

namespace Tests\Service\VendorService;

use App\Entity\Source;
use App\Service\VendorService\VendorImageValidatorService;
use App\Utils\CoverVendor\VendorImageItem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class VendorImageValidatorServiceTest extends TestCase
{
    private string $lastModified = 'Wed, 05 Dec 2018 07:28:00 GMT';
    private int $contentLength = 12345;
    private string $url = 'http://test.cover/image.jpg';

    /**
     * Test that remote image exists.
     */
    public function testValidateRemoteImage()
    {
        $client = new MockHttpClient([
            new MockResponse('', [
                'http_code' => 200,
                'response_headers' => [
                    'Content-Length' => $this->contentLength,
                    'Last-Modified' => $this->lastModified,
                ],
            ]),
        ]);

        $item = new VendorImageItem();
        $item->setOriginalFile($this->url);

        $service = new VendorImageValidatorService($client);
        $service->validateRemoteImage($item);

        $this->assertEquals(true, $item->isFound());
        $this->assertEquals($this->lastModified, $item->getOriginalLastModified()->format('D, d M Y H:i:s \G\M\T'));
        $this->assertEquals($this->contentLength, $item->getOriginalContentLength());
        $this->assertEquals($this->url, $item->getOriginalFile());
    }

    /**
     * Test that missing image is detected.
     */
    public function testValidateRemoteImageMissing()
    {
        $client = new MockHttpClient([
            new MockResponse('', [
                'http_code' => 404,
                'response_headers' => [],
            ]),
        ]);

        $item = new VendorImageItem();
        $item->setOriginalFile($this->url);

        $service = new VendorImageValidatorService($client);
        $service->validateRemoteImage($item);

        $this->assertEquals(false, $item->isFound());
    }

    /**
     * Test that image is not modified.
     */
    public function testIsRemoteImageUpdatedNotModified()
    {
        $client = new MockHttpClient([
            new MockResponse('', [
                'http_code' => 200,
                'response_headers' => [
                    'Content-Length' => $this->contentLength,
                    'Last-Modified' => $this->lastModified,
                ],
            ]),
        ]);

        $timezone = new \DateTimeZone('UTC');
        $lastModifiedDateTime = \DateTime::createFromFormat('D, d M Y H:i:s \G\M\T', $this->lastModified, $timezone);

        $item = new VendorImageItem();
        $item->setOriginalFile($this->url)
            ->setOriginalContentLength($this->contentLength)
            ->setOriginalLastModified($lastModifiedDateTime);

        $source = new Source();
        $source->setOriginalFile($this->url)
            ->setOriginalContentLength($this->contentLength)
            ->setOriginalLastModified($lastModifiedDateTime);

        $service = new VendorImageValidatorService($client);
        $service->isRemoteImageUpdated($item, $source);

        $this->assertEquals(false, $item->isUpdated());
    }

    /**
     * Test that changes in the image is detected.
     */
    public function testIsRemoteImageUpdatedModified()
    {
        $client = new MockHttpClient([
            new MockResponse('', [
                'http_code' => 200,
                'response_headers' => [
                    'Content-Length' => $this->contentLength,
                    'Last-Modified' => $this->lastModified,
                ],
            ]),
        ]);

        $timezone = new \DateTimeZone('UTC');
        $lastModifiedDateTime = \DateTime::createFromFormat('D, d M Y H:i:s \G\M\T', $this->lastModified, $timezone);

        $item = new VendorImageItem();
        $item->setOriginalFile($this->url)
            ->setOriginalContentLength($this->contentLength)
            ->setOriginalLastModified($lastModifiedDateTime);

        $source = new Source();
        $source->setOriginalFile($this->url)
            ->setOriginalContentLength($this->contentLength + 200)
            ->setOriginalLastModified($lastModifiedDateTime);

        $service = new VendorImageValidatorService($client);
        $service->isRemoteImageUpdated($item, $source);

        $this->assertEquals(true, $item->isFound());
        $this->assertEquals(true, $item->isUpdated());
    }

    /**
     * Test remoteImageHeader parser.
     */
    public function testRemoteImageHeader()
    {
        $client = new MockHttpClient([
            new MockResponse('', [
                'http_code' => 200,
                'response_headers' => [
                    'cf-polished' => 'origFmt=png, origSize=25272',
                ],
            ]),
        ]);

        $service = new VendorImageValidatorService($client);
        $headers = $service->remoteImageHeader('cf-polished', $this->url);

        $this->assertEquals(['origFmt=png, origSize=25272'], $headers);
    }
}
