<?php
/**
 * @file
 * End user upload images.
 */

namespace App\Service\VendorService\UserUpload;

use App\Service\VendorService\ProgressBarTrait;
use App\Service\VendorService\VendorServiceInterface;
use App\Service\VendorService\VendorServiceTrait;
use App\Utils\Message\VendorImportResultMessage;

/**
 * Class UserUploadVendorService.
 */
class UserUploadVendorService implements VendorServiceInterface
{
    use ProgressBarTrait;
    use VendorServiceTrait;

    protected const VENDOR_ID = 15;

    /**
     * {@inheritdoc}
     *
     * Note: this is not placed in the vendor service traits as it can not have const.
     */
    public function getVendorId(): int
    {
        return self::VENDOR_ID;
    }

    /**
     * {@inheritdoc}
     */
    public function getVendorName(): string
    {
        return 'UserUpload';
    }

    /**
     * {@inheritdoc}
     */
    public function load(): VendorImportResultMessage
    {
        throw new \RuntimeException('This vendor is not runnable');
    }
}
