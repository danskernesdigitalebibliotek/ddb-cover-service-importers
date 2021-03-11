<?php

/**
 * @file
 * 'Publizon' vendor import service
 */

namespace App\Service\VendorService\Publizon;

use App\Exception\IllegalVendorServiceException;
use App\Exception\UnknownVendorServiceException;
use App\Service\VendorService\AbstractBaseVendorService;
use App\Service\VendorService\ProgressBarTrait;
use App\Service\VendorService\VendorCoreService;
use App\Utils\Message\VendorImportResultMessage;
use App\Utils\Types\IdentifierType;
use App\Utils\Types\OnixOutputDefinition;
use App\Utils\Types\VendorStatus;

/**
 * Class PublizonVendorService.
 */
class PublizonVendorService extends AbstractBaseVendorService
{
    use ProgressBarTrait;

    protected const VENDOR_ID = 5;

    private $xmlReader;

    private $apiEndpoint;
    private $apiServiceKey;

    /**
     * PublizonVendorService constructor.
     *
     * @param vendorCoreService $vendorCoreService
     *   Service with shared vendor functions
     * @param publizonXmlReaderService $xmlReader
     *   XML reader service to Publizon API
     */
    public function __construct(VendorCoreService $vendorCoreService, PublizonXmlReaderService $xmlReader)
    {
        parent::__construct($vendorCoreService);

        $this->xmlReader = $xmlReader;
    }

    /**
     * {@inheritdoc}
     */
    public function load(): VendorImportResultMessage
    {
        if (!$this->acquireLock()) {
            return VendorImportResultMessage::error(parent::ERROR_RUNNING);
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

                // Check if the we have found an ISBN number and a matching front cover
                if (OnixOutputDefinition::ISBN_13 === $productIDType && OnixOutputDefinition::FRONT_COVER === $resourceContentType
                    && OnixOutputDefinition::LINKABLE_RESOURCE === $resourceForm && OnixOutputDefinition::IMAGE === $resourceMode) {
                    $isbnArray[$idValue] = $resourceLink;
                }
                ++$totalProducts;
            }

            if ($this->limit && $totalProducts >= $this->limit) {
                break;
            }

            if (0 === $totalProducts % 100) {
                $this->updateOrInsertMaterials($status, $isbnArray, IdentifierType::ISBN);

                $isbnArray = [];

                $this->progressMessageFormatted($status);
                $this->progressAdvance();
            }
        }

        $this->updateOrInsertMaterials($status, $isbnArray, IdentifierType::ISBN);

        $this->progressFinish();

        return VendorImportResultMessage::success($status);
    }

    /**
     * Set config from service from DB vendor object.
     *
     * @throws UnknownVendorServiceException
     * @throws IllegalVendorServiceException
     */
    private function loadConfig(): void
    {
        $this->apiServiceKey = $this->getVendor()->getDataServerPassword();
        $this->apiEndpoint = $this->getVendor()->getDataServerURI();
    }
}
