<?php
/**
 * @file
 * Service for updating data from 'eBook Central' xlsx spreadsheet.
 */

namespace App\Service\VendorService\EbookCentral;

use App\Exception\UnsupportedIdentifierTypeException;
use App\Service\DataWell\DataWellClient;
use App\Service\VendorService\AbstractDataWellVendorService;
use App\Service\VendorService\VendorServiceSingleIdentifierInterface;
use App\Utils\CoverVendor\UnverifiedVendorImageItem;
use App\Utils\Types\IdentifierType;
use Nicebooks\Isbn\IsbnTools;

/**
 * Class EbookCentralVendorService.
 */
class EbookCentralVendorService extends AbstractDataWellVendorService implements VendorServiceSingleIdentifierInterface
{
    public const VENDOR_ID = 2;
    private const URL_PATTERN = 'https://syndetics.com/index.php?client=primo&isbn=%s/lc.jpg';

    protected array $datawellQueries = ['facet.acSource="ebook central', 'facet.acSource="ebook central plus'];

    private IsbnTools $tools;

    /**
     * {@inheritDoc}
     */
    public function __construct(
        protected readonly DataWellClient $datawell
    ) {
        $this->tools = new IsbnTools();
    }

    /**
     * {@inheritDoc}
     */
    public function getUnverifiedVendorImageItem(string $identifier, string $type): ?UnverifiedVendorImageItem
    {
        if (!$this->supportsIdentifierType($type)) {
            throw new UnsupportedIdentifierTypeException('Unsupported single identifier type: '.$type);
        }

        if (!$this->tools->isValidIsbn13($identifier)) {
            // EbookCentral supports both ISBN10 and ISBN13. We only process ISBN13
            // to avoid duplicates. We depend on the datawell to map ISBN13 to ISBN10
            // to ensure our search index has entries for both.
            return null;
        }

        $item = new UnverifiedVendorImageItem();
        $item->setIdentifier($identifier);
        $item->setIdentifierType($type);
        $item->setVendor($this->getVendor());
        $item->setOriginalFile($this->getVendorImageUrl($identifier));

        return $item;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsIdentifierType(string $type): bool
    {
        return IdentifierType::ISBN === $type;
    }

    /**
     * {@inheritDoc}
     */
    protected function extractData(object $jsonContent): array
    {
        $pidObjectArray = $this->datawell->extractData($jsonContent);

        $pidArray = [];
        foreach ($pidObjectArray as $pid => $datawellObject) {
            $isbn = $this->datawell->extractIsbn($datawellObject);
            if (null !== $isbn) {
                $pidArray[$pid] = $this->getVendorImageUrl($isbn);
            }
        }

        return $pidArray;
    }

    /**
     * Get Vendors image URL from ISBN.
     *
     * @param string $isbn
     *
     * @return string
     */
    private function getVendorImageUrl(string $isbn): string
    {
        return \sprintf(self::URL_PATTERN, $isbn);
    }
}
