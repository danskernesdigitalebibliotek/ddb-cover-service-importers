<?php

/**
 * @file
 * 'Publizon' vendor import service
 */

namespace App\Service\VendorService\Publizon;

use App\Service\VendorService\ProgressBarTrait;
use App\Service\VendorService\VendorServiceInterface;
use App\Service\VendorService\VendorServiceTrait;
use App\Utils\Message\VendorImportResultMessage;
use App\Utils\Types\IdentifierType;
use App\Utils\Types\OnixOutputDefinition;
use App\Utils\Types\VendorStatus;

/**
 * Class PublizonVendorService.
 */
class PublizonVendorService implements VendorServiceInterface
{
    use ProgressBarTrait;
    use VendorServiceTrait;

    protected const VENDOR_ID = 5;

    private PublizonXmlReaderService $xmlReader;

    private string $apiEndpoint;
    private string $apiServiceKey;

    /**
     * PublizonVendorService constructor.
     *
     * @param PublizonXmlReaderService $xmlReader
     *   XML reader service to Publizon API
     */
    public function __construct(PublizonXmlReaderService $xmlReader)
    {
        $this->xmlReader = $xmlReader;
    }

    /**
     * {@inheritdoc}
     *
     * @throws \App\Exception\XmlReaderException
     */
    public function load(): VendorImportResultMessage
    {
        if (!$this->vendorCoreService->acquireLock($this->getVendorId(), $this->ignoreLock)) {
            return VendorImportResultMessage::error(self::ERROR_RUNNING);
        }

        $this->loadConfig();

        $status = new VendorStatus();
        $this->progressStart('Opening xml resource stream from: '.$this->apiEndpoint);

        $this->xmlReader->open($this->apiServiceKey, $this->apiEndpoint);

        $totalProducts = 0;
        $isbnArray = [];

        /*
         * We're streaming a large (approx. 500 million characters) xml document containing
         * a list of <Product> tags of the following structure:
         *
         *   <Product datestamp="20181126T10:29:40Z">
         *     ...
         *     <ProductIdentifier>
         *       <ProductIDType>15</ProductIDType>
         *       <IDValue>9788771650792</IDValue>
         *     </ProductIdentifier>
         *     ...
         *     <CollateralDetail>
         *       ...
         *       <SupportingResource>
         *         <ResourceContentType>01</ResourceContentType>
         *         <ContentAudience>00</ContentAudience>
         *         <ResourceMode>03</ResourceMode>
         *         <ResourceVersion>
         *           <ResourceForm>01</ResourceForm>
         *           <ResourceLink>https://images.pubhub.dk/originals/b5766a8e-f79a-473d-a9b6-28efb0536a10.jpg</ResourceLink>
         *         </ResourceVersion>
         *       </SupportingResource>
         *     </CollateralDetail>
         *     ...
         *   </Product>
         *
         * Given that we're streaming we can't use normal XML -> Object functions because we never
         * hold a complete element in memory.
         */

        try {
            while ($this->xmlReader->read()) {
                $productIDType = $idValue = null;
                $resourceContentType = $resourceMode = $resourceForm = $resourceLink = null;

                if ($this->xmlReader->isAtElementStart('Product')) {
                    while ($this->xmlReader->readUntilElementEnd('Product')) {
                        if ($this->xmlReader->isAtElementStart('ProductIdentifier')) {
                            while ($this->xmlReader->readUntilElementEnd('ProductIdentifier')) {
                                if ($this->xmlReader->isAtElementStart('ProductIDType')) {
                                    $productIDType = $this->xmlReader->getNextElementValue();
                                }

                                if ($this->xmlReader->isAtElementStart('IDValue')) {
                                    $idValue = $this->xmlReader->getNextElementValue();
                                }
                            }
                        }

                        if ($this->xmlReader->isAtElementStart('CollateralDetail')) {
                            while ($this->xmlReader->readUntilElementEnd('CollateralDetail')) {
                                if ($this->xmlReader->isAtElementStart('SupportingResource')) {
                                    while ($this->xmlReader->readUntilElementEnd('SupportingResource')) {
                                        if ($this->xmlReader->isAtElementStart('ResourceContentType')) {
                                            $resourceContentType = $this->xmlReader->getNextElementValue();
                                        }

                                        if ($this->xmlReader->isAtElementStart('ResourceMode')) {
                                            $resourceMode = $this->xmlReader->getNextElementValue();
                                        }

                                        if ($this->xmlReader->isAtElementStart('ResourceVersion')) {
                                            while ($this->xmlReader->readUntilElementEnd('ResourceVersion')) {
                                                if ($this->xmlReader->isAtElementStart('ResourceForm')) {
                                                    $resourceForm = $this->xmlReader->getNextElementValue();
                                                }

                                                if ($this->xmlReader->isAtElementStart('ResourceLink')) {
                                                    $resourceLink = $this->xmlReader->getNextElementValue();
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }

                    // Check if we have found an ISBN and a matching front cover.
                    if (OnixOutputDefinition::ISBN_13 === $productIDType && OnixOutputDefinition::FRONT_COVER === $resourceContentType
                        && OnixOutputDefinition::LINKABLE_RESOURCE === $resourceForm && OnixOutputDefinition::IMAGE === $resourceMode
                        && !is_null($idValue)) {
                        $isbnArray[$idValue] = $resourceLink;
                    }
                    ++$totalProducts;
                }

                if ($this->limit && $totalProducts >= $this->limit) {
                    break;
                }

                if (0 === $totalProducts % 100) {
                    $this->vendorCoreService->updateOrInsertMaterials($status, $isbnArray, IdentifierType::ISBN, $this->getVendorId(), $this->withUpdatesDate, $this->withoutQueue, self::BATCH_SIZE);

                    $isbnArray = [];

                    $this->progressMessageFormatted($status);
                    $this->progressAdvance();
                }
            }

            $this->vendorCoreService->updateOrInsertMaterials($status, $isbnArray, IdentifierType::ISBN, $this->getVendorId(), $this->withUpdatesDate, $this->withoutQueue, self::BATCH_SIZE);
        } catch (\Exception $e) {
            $this->xmlReader->close();
            return VendorImportResultMessage::error($e->getMessage());
        }

        $this->xmlReader->close();
        $this->progressFinish();

        $this->vendorCoreService->releaseLock($this->getVendorId());

        return VendorImportResultMessage::success($status);
    }

    /**
     * Set config from service from DB vendor object.
     */
    private function loadConfig(): void
    {
        $vendor = $this->vendorCoreService->getVendor($this->getVendorId());

        if (!empty($vendor->getDataServerPassword()) && !empty($vendor->getDataServerURI())) {
            $this->apiServiceKey = (string) $vendor->getDataServerPassword();
            $this->apiEndpoint = (string) $vendor->getDataServerURI();
        } else {
            throw new \InvalidArgumentException('Vendor api keu and end-point need to be set');
        }
    }
}
