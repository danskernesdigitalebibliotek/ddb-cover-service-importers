<?php
/**
 * @file
 * Data model class to hold vendor import success information.
 */

namespace App\Utils\Message;

use App\Utils\Types\VendorStatus;

/**
 * Class VendorImportResultMessage.
 */
class VendorImportResultMessage
{
    private $isSuccess;
    private $message;

    private $totalRecords;
    private $updated;
    private $inserted;
    private $deleted;

    /**
     * VendorImportResultMessage constructor.
     *
     * @param $success
     */
    private function __construct($success)
    {
        $this->isSuccess = $success;
    }

    /**
     * VendorImportResultMessage toString.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->message;
    }

    /**
     * Create success message.
     *
     * @param vendorStatus $status
     *   Counts for the changes made be the vendor
     *
     * @return VendorImportResultMessage
     */
    public static function success(VendorStatus $status): self
    {
        $resultMessage = new VendorImportResultMessage(true);
        $resultMessage->totalRecords = $status->records;
        $resultMessage->updated = $status->updated;
        $resultMessage->inserted = $status->inserted;
        $resultMessage->deleted = $status->deleted;

        $resultMessage->message = sprintf('%d vendor ISBNs processed. %d updated / %d inserted / %d deleted', $status->records, $status->updated, $status->inserted, $status->deleted);

        return $resultMessage;
    }

    /**
     * Create error message.
     *
     * @param string $message
     *
     * @return VendorImportResultMessage
     */
    public static function error(string $message): self
    {
        $resultMessage = new VendorImportResultMessage(false);
        $resultMessage->message = $message;

        return $resultMessage;
    }

    /**
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->isSuccess;
    }

    /**
     * @return string
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * @return int
     */
    public function getTotalRecords(): int
    {
        return $this->totalRecords;
    }

    /**
     * @return int
     */
    public function getUpdated(): int
    {
        return $this->updated;
    }

    /**
     * @return int
     */
    public function getInserted(): int
    {
        return $this->inserted;
    }

    /**
     * @return int
     */
    public function getDeleted(): int
    {
        return $this->deleted;
    }
}
