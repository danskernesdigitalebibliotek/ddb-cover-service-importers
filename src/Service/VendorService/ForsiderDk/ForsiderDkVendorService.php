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
    private const COVER_URL_FORMAT = 'https://data.forsider.dk/%s/covers/%s/%s.jpg';

    /**
     * @var array|string[]
     */
    private array $subFolder = [
        'business',
        'business2',
        'culture',
        'economics',
        'hospitality',
        'industries',
        'law',
        'literature2',
        'medicine',
        'politics',
        'technology',
    ];

    /**
     * Forsider.dk only supplies covers for materials from the EBSCO master file.
     */
    private const PID_PREFIX = '150010-master';

    /**
     * {@inheritDoc}
     */
    public function getUnverifiedVendorImageItems(string $identifier, string $type): \Generator
    {
        if (!$this->supportsIdentifier($identifier, $type)) {
            throw new UnsupportedIdentifierTypeException(\sprintf('Unsupported single identifier: %s (%s)', $identifier, $type));
        }

        $vendor = $this->vendorCoreService->getVendor(self::VENDOR_ID);

        foreach ($this->subFolder as $folder) {
            $item = new UnverifiedVendorImageItem($this->getVendorImageUrl($identifier, $folder), $vendor);
            $item->setIdentifier($identifier);
            $item->setIdentifierType($type);
            $item->setGenericCover(true);

            yield $item;
        }
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
     *
     * @see https://solsort.dk/webdav-forside-server
     *   For more information about the image URL formats.
     */
    private function getVendorImageUrl(string $pid, string $folder): string
    {
        $endChars = \substr($pid, -2);
        $dir = \is_numeric($endChars) ? $endChars : 'other';

        return \sprintf(self::COVER_URL_FORMAT, $folder, $dir, $pid);
    }
}
