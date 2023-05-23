<?php

namespace App\Service\VendorService;

use App\Entity\Source;
use App\Exception\ValidateRemoteImageException;
use App\Utils\CoverVendor\VendorImageItem;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Class VendorImageValidatorService.
 */
class VendorImageValidatorService
{
    /**
     * VendorImageValidatorService constructor.
     *
     * @param VendorImageDefaultValidator $defaultValidator
     * @param iterable<mixed, VendorImageValidatorInterface> $vendorImageValidators
     */
    public function __construct(
        private readonly VendorImageDefaultValidator $defaultValidator,
        private readonly iterable $vendorImageValidators
    ) {
    }

    /**
     * Validate that remote image exists.
     *
     * @param VendorImageItem $item
     *
     * @return ResponseInterface
     *
     * @throws ValidateRemoteImageException
     */
    public function validateRemoteImage(VendorImageItem $item): ResponseInterface
    {
        foreach ($this->vendorImageValidators as $vendorImageValidator) {
            if ($vendorImageValidator->supports($item)) {
                return $vendorImageValidator->validateRemoteImage($item);
            }
        }

        return $this->defaultValidator->validateRemoteImage($item);
    }

    /**
     * Check if a remote image has been updated since we fetched the source.
     *
     * @param VendorImageItem $item
     * @param Source $source
     *
     * @return void
     *
     * @throws ValidateRemoteImageException
     */
    public function isRemoteImageUpdated(VendorImageItem $item, Source $source): void
    {
        $this->validateRemoteImage($item);
        $item->setUpdated(false);

        if ($item->isFound()) {
            if (!empty($source->getETag()) && $source->getETag() !== $item->getETag()) {
                $item->setUpdated(true);
            } elseif (
                $item->getOriginalLastModified() != $source->getOriginalLastModified() ||
                $item->getOriginalContentLength() !== $source->getOriginalContentLength()) {
                $item->setUpdated(true);
            }
        }
    }
}
