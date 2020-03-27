<?php
/**
 * @file
 * Service that does not do anything. It exists to enable a vendor for the upload service.
 */

namespace App\Service\VendorService\UploadService;

use App\Service\VendorService\AbstractBaseVendorService;
use App\Service\VendorService\ProgressBarTrait;
use App\Utils\Message\VendorImportResultMessage;

/**
 * Class UploadServiceVendorService.
 */
class UploadServiceVendorService extends AbstractBaseVendorService
{
    use ProgressBarTrait;

    protected const VENDOR_ID = 12;

    /**
     * {@inheritdoc}
     */
    public function load(): VendorImportResultMessage
    {
        $this->progressStart('This vendor has no work');
        $this->progressFinish();

        return VendorImportResultMessage::success(0, 0, 0, 0);
    }
}
