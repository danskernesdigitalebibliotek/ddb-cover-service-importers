<?php
/**
 * @file
 * Service for updating data from 'eBook Central' xlsx spreadsheet.
 */

namespace App\Service\VendorService\EbookCentral;

use App\Exception\UnsupportedIdentifierTypeException;
use App\Service\VendorService\AbstractDataWellVendorService;
use App\Service\VendorService\VendorServiceSingleIdentifierInterface;
use App\Utils\CoverVendor\UnverifiedVendorImageItem;
use App\Utils\Types\IdentifierType;

/**
 * Class EbookCentralVendorService.
 */
class EbookCentralVendorService extends AbstractDataWellVendorService implements VendorServiceSingleIdentifierInterface
{
    protected const VENDOR_ID = 2;
    private const URL_PATTERN = 'https://syndetics.com/index.php?client=primo&isbn=%s/lc.jpg';

    protected array $datawellQueries = ['facet.acSource="ebook central', 'facet.acSource="ebook central plus'];

    /**
     * {@inheritDoc}
     */
    public function getUnverifiedVendorImageItem(string $identifier, string $type): ?UnverifiedVendorImageItem
    {
        if (!$this->supportsIdentifierType($type)) {
            throw new UnsupportedIdentifierTypeException('Unsupported single identifier type: '.$type);
        }

        $vendor = $this->vendorCoreService->getVendor(self::VENDOR_ID);

        $item = new UnverifiedVendorImageItem();
        $item->setIdentifier($identifier);
        $item->setIdentifierType($type);
        $item->setVendor($vendor);
        $item->setOriginalFile($this->getVendorImageUrl($identifier));

        return $item;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsIdentifierType(string $type): bool
    {
        return IdentifierType::ISBN === $type;
    }

    /**
     * {@inheritDoc}
     */
    protected function extractData(object $jsonContent): array
    {
        $pidObjectArray = $this->datawell->extractData($jsonContent);

        $pidArray = [];
        foreach ($pidObjectArray as $pid => $datawellObject) {
            $isbn = $this->datawell->extractIsbn($datawellObject);
            if (null !== $isbn) {
                $pidArray[$pid] = $this->getVendorImageUrl($isbn);
            }
        }

        return $pidArray;
    }

    /**
     * Get Vendors image URL from ISBN.
     *
     * @param string $isbn
     *
     * @return string
     */
    private function getVendorImageUrl(string $isbn): string
    {
        return \sprintf(self::URL_PATTERN, $isbn);
    }
}
