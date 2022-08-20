<?php

namespace App\Service\VendorService\OpenLibrary;

use App\Exception\UnsupportedIdentifierTypeException;
use App\Service\VendorService\VendorServiceSingleIdentifierInterface;
use App\Service\VendorService\VendorServiceTrait;
use App\Utils\CoverVendor\UnverifiedVendorImageItem;
use App\Utils\Types\IdentifierType;

class OpenLibraryVendor implements VendorServiceSingleIdentifierInterface
{
    use VendorServiceTrait;

    private const VENDOR_ID = 20;

    /**
     * Example: https://covers.openlibrary.org/b/isbn/9780385472579-L.jpg.
     *
     * @see https://openlibrary.org/dev/docs/api/covers
     */
    private const COVER_URL_FORMAT = 'https://covers.openlibrary.org/b/isbn/%s-L.jpg?default=false';

    /**
     * {@inheritDoc}
     */
    public function getUnverifiedVendorImageItem(string $identifier, string $type): UnverifiedVendorImageItem
    {
        if (!$this->supportsIdentifierType($type)) {
            throw new UnsupportedIdentifierTypeException('Unsupported single identifier type: '.$type);
        }

        $item = new UnverifiedVendorImageItem();
        $item->setIdentifier($identifier);
        $item->setIdentifierType($type);
        $item->setVendor($this->vendorCoreService->getVendor(self::VENDOR_ID));
        $item->setOriginalFile($this->getVendorsImageUrl($identifier));

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
     * Get Vendors image URL from ISBN.
     */
    private function getVendorsImageUrl(string $isbn): string
    {
        return sprintf(self::COVER_URL_FORMAT, $isbn);
    }
}
