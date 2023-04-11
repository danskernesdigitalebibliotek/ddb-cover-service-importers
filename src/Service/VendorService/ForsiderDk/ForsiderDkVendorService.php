<?php

namespace App\Service\VendorService\ForsiderDk;

use App\Exception\UnsupportedIdentifierTypeException;
use App\Service\VendorService\VendorServiceSingleIdentifierInterface;
use App\Service\VendorService\VendorServiceTrait;
use App\Utils\CoverVendor\UnverifiedVendorImageItem;
use App\Utils\Types\IdentifierType;

class ForsiderDkVendorService implements VendorServiceSingleIdentifierInterface
{
    use VendorServiceTrait;

    public const VENDOR_ID = 23;

    /**
     * Example: https://data.forsider.dk/law/covers/00/150010-master:47931700.jpg.
     *
     * @see https://solsort.dk/webdav-forside-server
     */
    private const COVER_URL_FORMAT = 'https://data.forsider.dk/law/covers/%s/%s.jpg';

    /**
     * Forsider.dk only supplies covers for materials from the EBSCO masterfile.
     */
    private const PID_PREFIX = '150010-master';

    /**
     * {@inheritDoc}
     */
    public function getUnverifiedVendorImageItem(string $identifier, string $type): ?UnverifiedVendorImageItem
    {
        if (!$this->supportsIdentifier($identifier, $type)) {
            throw new UnsupportedIdentifierTypeException(\sprintf('Unsupported single identifier: %s (%s)', $identifier, $type));
        }

        $vendor = $this->vendorCoreService->getVendor(self::VENDOR_ID);

        $item = new UnverifiedVendorImageItem($this->getVendorImageUrl($identifier), $vendor);
        $item->setIdentifier($identifier);
        $item->setIdentifierType($type);

        $item->setGenericCover(true);

        return $item;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsIdentifier(string $identifier, string $type): bool
    {
        return IdentifierType::PID === $type && \str_starts_with($identifier, self::PID_PREFIX);
    }

    /**
     * Get Vendors image URL from PID.
     */
    private function getVendorImageUrl(string $pid): string
    {
        // 00/, 01/, 02/, ..., 99/, og other/ – mapper der indeholder forsiderne.
        // Mappen for forsiden er de sidste to cifre i ting-objektets id, eller
        // "other" hvis id'et ikke slutter på cifre.
        // @see https://solsort.dk/webdav-forside-server
        $endChars = \substr($pid, -2);
        $dir = \is_numeric($endChars) ? $endChars : 'other';

        return \sprintf(self::COVER_URL_FORMAT, $dir, $pid);
    }
}
