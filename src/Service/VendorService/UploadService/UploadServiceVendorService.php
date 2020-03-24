<?php
/**
 * @file
 * Service that don't do anything. It exists to enable an vendor for the upload service.
 */

namespace App\Service\VendorService\UploadService;

use App\Service\VendorService\AbstractBaseVendorService;
use App\Service\VendorService\ProgressBarTrait;
use App\Utils\Message\VendorImportResultMessage;

/**
 * Class BogPortalenVendorService.
 */
class UploadServiceVendorService extends AbstractBaseVendorService
{
    use ProgressBarTrait;

    protected const VENDOR_ID = 11;

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
