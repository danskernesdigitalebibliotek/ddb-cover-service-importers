<?php

/**
 * @file
 * Test cases for the upload service.
 */

namespace Tests;

use App\Service\CoverStore\CoverStoreInterface;
use App\Service\VendorService\UploadService\UploadServiceVendorService;
use App\Utils\Types\IdentifierType;
use Doctrine\ORM\EntityManagerInterface;
use Enqueue\Client\ProducerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Class UploadServiceVendorServiceTest.
 */
class UploadServiceVendorServiceTest extends TestCase
{
    /**
     * Test extraction of filename.
     *
     * @throws \ReflectionException
     */
    public function testExtractFilename()
    {
        $service = $this->getUploadServiceVendorService();

        // Have to use full namespace in reaction class or it will fail.
        $ref = new \ReflectionClass('\App\Service\VendorService\UploadService\UploadServiceVendorService');
        $method = $ref->getMethod('extractFilename');
        $method->setAccessible(true);

        $this->assertEquals($method->invoke($service, 'BulkUpload/870970-basis%3A06390072'), '870970-basis%3A06390072');
        $this->assertEquals($method->invoke($service, 'BulkUpload/775100/870970-basis%3A06390072'), '870970-basis%3A06390072');
    }

    /**
     * Test filename to identifier.
     *
     * @throws \ReflectionException
     */
    public function testFilenameToIdentifier()
    {
        $service = $this->getUploadServiceVendorService();

        // Have to use full namespace in reaction class or it will fail.
        $ref = new \ReflectionClass('\App\Service\VendorService\UploadService\UploadServiceVendorService');
        $method = $ref->getMethod('filenameToIdentifier');
        $method->setAccessible(true);

        $this->assertEquals($method->invoke($service, '870970-basis%3A06390072'), '870970-basis:06390072');
        $this->assertEquals($method->invoke($service, '870970-basis%3A06390072.jpg'), '870970-basis:06390072');
        $this->assertEquals($method->invoke($service, '870970-basis%3A06390072.png'), '870970-basis:06390072');
    }

    /**
     * Test detection of type (PID, ISBN) from identifier.
     *
     * @throws \ReflectionException
     */
    public function testIdentifierToType()
    {
        $service = $this->getUploadServiceVendorService();

        // Have to use full namespace in reaction class or it will fail.
        $ref = new \ReflectionClass('\App\Service\VendorService\UploadService\UploadServiceVendorService');
        $method = $ref->getMethod('identifierToType');
        $method->setAccessible(true);

        $this->assertEquals($method->invoke($service, '870970-basis:06390072'), IdentifierType::PID);
        $this->assertEquals($method->invoke($service, '9788702284799'), IdentifierType::ISBN);
    }

    /**
     * Helper class to mock service for UploadService.
     *
     * @return uploadServiceVendorService
     *   The mocked UploadService vendor object
     */
    private function getUploadServiceVendorService(): UploadServiceVendorService
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $store = $this->createMock(CoverStoreInterface::class);
        $producer = $this->createMock(ProducerInterface::class);

        return new UploadServiceVendorService($dispatcher, $entityManager, $logger, $store, $producer);
    }
}
