<?php

/**
 * @file
 * Test cases for the upload service.
 */

namespace Tests;

use App\Repository\SourceRepository;
use App\Service\CoverStore\CoverStoreInterface;
use App\Service\VendorService\UploadService\UploadServiceVendorService;
use App\Utils\Types\IdentifierType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\MessageBusInterface;

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

        // Have to use full namespace in reflection class or it will fail.
        $ref = new \ReflectionClass('\App\Service\VendorService\UploadService\UploadServiceVendorService');
        $method = $ref->getMethod('extractFilename');
        $method->setAccessible(true);

        $this->assertEquals($method->invoke($service, 'BulkUpload/870970-basis%3A06390072'), '870970-basis%3A06390072');
        $this->assertEquals($method->invoke($service, 'BulkUpload/775100/870970-basis%3A06390072'), '870970-basis%3A06390072');
    }

    /**
     * Test filename validation.
     *
     * @throws \ReflectionException
     */
    public function testValidateFilename()
    {
        $service = $this->getUploadServiceVendorService();

        // Have to use full namespace in reflection class or it will fail.
        $ref = new \ReflectionClass('\App\Service\VendorService\UploadService\UploadServiceVendorService');
        $method = $ref->getMethod('isValidFilename');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($service, '870970-basis%3A06390072'));
        $this->assertTrue($method->invoke($service, '150061-ebog:ODN0004246103'));
        $this->assertTrue($method->invoke($service, '9788775145454'));

        $this->assertFalse($method->invoke($service, '870970-basis%3A0639_0072'));
        $this->assertFalse($method->invoke($service, '870970-ba1234%3A06390072'));
        $this->assertFalse($method->invoke($service, '150061ebog:ODN0004246103'));
        $this->assertFalse($method->invoke($service, 'basic-9788775145454'));
        $this->assertFalse($method->invoke($service, '978877Test:5145454'));
        $this->assertFalse($method->invoke($service, '978877Test5145454'));
    }

    /**
     * Test filename to identifier.
     *
     * @throws \ReflectionException
     */
    public function testFilenameToIdentifier()
    {
        $service = $this->getUploadServiceVendorService();

        // Have to use full namespace in reflection class or it will fail.
        $ref = new \ReflectionClass('\App\Service\VendorService\UploadService\UploadServiceVendorService');
        $method = $ref->getMethod('filenameToIdentifier');
        $method->setAccessible(true);

        $this->assertEquals($method->invoke($service, '870970-basis%3A06390072'), '870970-basis:06390072');
        $this->assertEquals($method->invoke($service, '870970-basis%3A06390072.jpg'), '870970-basis:06390072');
        $this->assertEquals($method->invoke($service, '870970-basis%3A06390072.png'), '870970-basis:06390072');
        $this->assertEquals($method->invoke($service, '870970-basis_3A06390072'), '870970-basis:06390072');
        $this->assertEquals($method->invoke($service, '870970-basis_06390072'), '870970-basis:06390072');
        $this->assertEquals($method->invoke($service, '150061-ebog%3AODN0004246103'), '150061-ebog:ODN0004246103');
    }

    /**
     * Test detection of type (PID, ISBN) from identifier.
     *
     * @throws \ReflectionException
     */
    public function testIdentifierToType()
    {
        $service = $this->getUploadServiceVendorService();

        // Have to use full namespace in reflection class or it will fail.
        $ref = new \ReflectionClass('\App\Service\VendorService\UploadService\UploadServiceVendorService');
        $method = $ref->getMethod('identifierToType');
        $method->setAccessible(true);

        $this->assertEquals($method->invoke($service, '870970-basis:06390072'), IdentifierType::PID);
        $this->assertEquals($method->invoke($service, '9788702284799'), IdentifierType::ISBN);
    }

    /**
     * Helper class to mock service for UploadService.
     *
     * @return UploadServiceVendorService
     *   The mocked UploadService vendor object
     */
    private function getUploadServiceVendorService(): UploadServiceVendorService
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $store = $this->createMock(CoverStoreInterface::class);
        $bus = $this->createMock(MessageBusInterface::class);
        $repos = $this->createMock(SourceRepository::class);

        return new UploadServiceVendorService($bus, $entityManager, $store, $repos);
    }
}
