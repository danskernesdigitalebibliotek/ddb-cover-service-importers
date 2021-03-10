<?php
/**
 * @file
 * Trait adding progressbar support
 */

namespace App\Service\VendorService;

use App\Utils\Types\VendorStatus;
use Symfony\Component\Console\Helper\ProgressBar;

/**
 * Trait ProgressBarTrait.
 */
trait ProgressBarTrait
{
    /** @var ProgressBar $progressBar */
    private $progressBar;

    /**
     * Set the progressbar to write to.
     *
     * @param ProgressBar $progressBar
     */
    public function setProgressBar(ProgressBar $progressBar): void
    {
        $this->progressBar = $progressBar;
    }

    /**
     * Start progress.
     *
     * @param string $message
     */
    private function progressStart(string $message): void
    {
        if ($this->progressBar) {
            $this->progressMessage($message);
            $this->progressBar->start();
            $this->progressBar->advance();
        }
    }

    /**
     * Advance progress.
     */
    private function progressAdvance(): void
    {
        if ($this->progressBar) {
            $this->progressBar->advance();
        }
    }

    /**
     * Set progress bar message.
     *
     * @param string $message
     */
    private function progressMessage(string $message): void
    {
        $name = method_exists($this, 'getVendor') ? $this->getVendor()->getName().': ' : '';

        if ($this->progressBar) {
            $this->progressBar->setMessage($name.$message);
        }
    }

    /**
     * Set a formatted progress message.
     *
     * @param int $updated
     * @param int $inserted
     * @param int $records
     */
    private function progressMessageFormatted(VendorStatus $status): void
    {
        if ($this->progressBar) {
            $updatedFormatted = number_format($status->updated, 0, ',', '.');
            $insertedFormatted = number_format($status->inserted, 0, ',', '.');
            $recordsFormatted = number_format($status->records, 0, ',', '.');
            $message = sprintf('Updating DB: %s/%s identifications updated/inserted from %s records.', $updatedFormatted, $insertedFormatted, $recordsFormatted);

            $this->progressMessage($message);
        }
    }

    /**
     * Finish progress.
     */
    private function progressFinish(): void
    {
        if ($this->progressBar) {
            $this->progressBar->finish();
        }
    }
}
