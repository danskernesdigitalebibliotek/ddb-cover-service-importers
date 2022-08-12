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
class VendorImportResultMessage implements \Stringable
{
    private string $message;

    private int $totalRecords;
    private int $updated;
    private int $inserted;
    private int $deleted;

    /**
     * VendorImportResultMessage constructor.
     *
     * @param bool $isSuccess
     */
    private function __construct(
        private readonly bool $isSuccess
    ) {
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
     */
    public static function error(string $message): self
    {
        $resultMessage = new VendorImportResultMessage(false);
        $resultMessage->message = $message;

        return $resultMessage;
    }

    public function isSuccess(): bool
    {
        return $this->isSuccess;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getTotalRecords(): int
    {
        return $this->totalRecords;
    }

    public function getUpdated(): int
    {
        return $this->updated;
    }

    public function getInserted(): int
    {
        return $this->inserted;
    }

    public function getDeleted(): int
    {
        return $this->deleted;
    }
}
