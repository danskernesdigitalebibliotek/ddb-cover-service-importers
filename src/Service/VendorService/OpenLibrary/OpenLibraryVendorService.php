<?php

namespace App\Service\VendorService\OpenLibrary;

use App\Exception\UnsupportedIdentifierTypeException;
use App\Service\VendorService\VendorServiceSingleIdentifierInterface;
use App\Service\VendorService\VendorServiceTrait;
use App\Utils\CoverVendor\UnverifiedVendorImageItem;
use App\Utils\Types\IdentifierType;

class OpenLibraryVendorService implements VendorServiceSingleIdentifierInterface
{
    use VendorServiceTrait;

    public const VENDOR_ID = 20;

    /**
     * Example: https://covers.openlibrary.org/b/isbn/9780385472579-L.jpg.
     *
     * @see https://openlibrary.org/dev/docs/api/covers
     */
    private const COVER_URL_FORMAT = 'https://covers.openlibrary.org/b/isbn/%s-L.jpg?default=false';

    /**
     * {@inheritDoc}
     */
    public function getUnverifiedVendorImageItem(string $identifier, string $type): ?UnverifiedVendorImageItem
    {
        if (!$this->supportsIdentifier($identifier, $type)) {
            throw new UnsupportedIdentifierTypeException(\sprinf('Unsupported single identifier: %s (%s)', $identifier, $type));
        }

        $vendor = $this->vendorCoreService->getVendor(self::VENDOR_ID);

        $item = new UnverifiedVendorImageItem($this->getVendorImageUrl($identifier), $vendor);
        $item->setIdentifier($identifier);
        $item->setIdentifierType($type);

        return $item;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsIdentifier(string $identifier, string $type): bool
    {
        return IdentifierType::ISBN === $type;
    }

    /**
     * Get Vendors image URL from ISBN.
     */
    private function getVendorImageUrl(string $isbn): string
    {
        return \sprintf(self::COVER_URL_FORMAT, $isbn);
    }
}
