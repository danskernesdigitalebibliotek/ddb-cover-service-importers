<?php

/**
 * @file
 * Handle no-hits queue processing.
 */

namespace App\MessageHandler;

use App\Entity\Search;
use App\Entity\Source;
use App\Exception\MaterialConversionException;
use App\Exception\UnknownVendorServiceException;
use App\Exception\UnsupportedIdentifierTypeException;
use App\Message\SearchMessage;
use App\Message\SearchNoHitsMessage;
use App\Message\VendorImageMessage;
use App\Service\OpenPlatform\SearchService;
use App\Service\VendorService\VendorImageValidatorService;
use App\Service\VendorService\VendorServiceSingleIdentifierInterface;
use App\Utils\CoverVendor\UnverifiedVendorImageItem;
use App\Utils\CoverVendor\VendorImageItem;
use App\Utils\OpenPlatform\Material;
use App\Utils\Types\IdentifierType;
use App\Utils\Types\VendorState;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\ORM\EntityManagerInterface;
use ItkDev\MetricsBundle\Service\MetricsService;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Class SearchNoHitsMessageHandler.
 */
class SearchNoHitsMessageHandler implements MessageHandlerInterface
{
    final public const VENDOR = 'Unknown';

    /**
     * SearchNoHitsMessageHandler constructor.
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
        private readonly SearchService $searchService,
        private readonly VendorImageValidatorService $validatorService,
        private readonly MetricsService $metricsService,
        private readonly CacheItemPoolInterface $noHitsSingleCoverCache,
        private readonly iterable $singleIdentifierVendors
    ) {
    }

    /**
     * Invoke handler.
     *
     * @param SearchNoHitsMessage $message
     */
    public function __invoke(SearchNoHitsMessage $message): void
    {
        try {
            $identifier = $message->getIdentifier();
            $type = $message->getIdentifierType();

            // If this is katalog post, then one should be able to extract agency from
            // the pid and search in that datawell.
            $agency = $message->getAgency();
            if (IdentifierType::PID === $type && strpos($identifier, '-katalog:')) {
                try {
                    $agency = 'DK-'.Material::getAgencyFromKatalog($identifier);
                } catch (MaterialConversionException $e) {
                    // Don't do anything. The search later on in the code may still
                    // yield something.
                }
            }

            // Try to search the data well and match source entity. If the 'source'
            // have been added by the vendor before it's available in the datawell
            // it will not have been indexed.
            $material = $this->searchService->search($identifier, $type, $agency, $message->getProfile());
            $found = $this->mapDatawellSearch($material, $agency, $message->getProfile());

            if (!$found && $this->cacheCheckSingleCoverVendors($message)) {
                // Some vendors can return a potential URL for the cover given an identifier.
                // Get a list of these from the matched identifiers in the datawell.
                $unverifiedVendorImageItems = $this->getUnverifiedVendorImageItems($material);

                $found = $this->processUnverifiedImageItems($unverifiedVendorImageItems);
            }

            if (!$found) {
                $this->metricsService->counter('no_hit_failed', 'No-hit mapping not found', 1, ['type' => 'nohit']);
            }
        } catch (\Throwable $exception) {
            $this->metricsService->counter('no_hit_katelog_error', 'No-hit katelog error', 1, ['type' => 'nohit']);

            $this->logger->error('Exception: '.$exception::class, [
                'service' => self::class,
                'message' => $exception->getMessage(),
                'identifier' => $message->getIdentifier(),
            ]);
        }
    }

    /**
     * Check if the cache pool for the messages' identifier + type.
     *
     * @param SearchNoHitsMessage $message
     *
     * @return bool
     *
     * @throws InvalidArgumentException
     */
    private function cacheCheckSingleCoverVendors(SearchNoHitsMessage $message): bool
    {
        $key = $this->getValidCacheKey($message->getIdentifierType(), $message->getIdentifier());
        $item = $this->noHitsSingleCoverCache->getItem($key);

        if ($item->isHit()) {
            return false;
        }

        $item->set(true);
        $this->noHitsSingleCoverCache->save($item);

        return true;
    }

    /**
     * Get unverified image items from vendors for a given material.
     *
     * @param Material $material
     *
     * @return UnverifiedVendorImageItem[]
     *
     * @throws UnknownVendorServiceException
     * @throws UnsupportedIdentifierTypeException
     */
    private function getUnverifiedVendorImageItems(Material $material): array
    {
        $items = [];

        foreach ($material->getIdentifiers() as $identifier) {
            /** @var VendorServiceSingleIdentifierInterface $vendor */
            foreach ($this->singleIdentifierVendors as $vendor) {
                if ($vendor->supportsIdentifier($identifier->getId(), $identifier->getType())) {
                    $item = $vendor->getUnverifiedVendorImageItem($identifier->getId(), $identifier->getType());
                    if (null !== $item) {
                        $items[] = $item;
                    }
                }
            }
        }

        return $items;
    }

    /**
     * Process and verify image items.
     *
     * @param UnverifiedVendorImageItem[] $unverifiedImageItems
     *
     * @return bool
     *
     * @throws DBALException
     */
    private function processUnverifiedImageItems(array $unverifiedImageItems): bool
    {
        $found = false;

        foreach ($unverifiedImageItems as $unverifiedImageItem) {
            $this->validatorService->validateRemoteImage($unverifiedImageItem);

            if ($unverifiedImageItem->isFound()) {
                $source = $this->persistSource($unverifiedImageItem);

                $this->dispatchVendorImageMessage($source, VendorState::INSERT);

                $found = true;
            }
        }

        // Log if a new record was created or not.
        if ($found) {
            $this->metricsService->counter('no_hit_single_identifier_mapped', 'No-hit mapped from single identifier vendor', 1, ['type' => 'nohit']);
        } else {
            $this->metricsService->counter('no_hit_single_identifier_not_mapped', 'No-hit not mapped from single identifier vendor', 1, ['type' => 'nohit']);
        }

        return $found;
    }

    /**
     * Dispatch VendorImageMessage.
     *
     * @param Source $source
     * @param string $operation
     *
     * @return void
     */
    private function dispatchVendorImageMessage(Source $source, string $operation): void
    {
        $message = new VendorImageMessage();
        $message->setOperation($operation)
            ->setIdentifier($source->getMatchId())
            ->setVendorId($source->getVendor()->getId())
            ->setIdentifierType($source->getMatchType());
        $this->bus->dispatch($message);
    }

    /**
     * Persist a matching "Source" from an UnverifiedVendorImageItem.
     *
     * @param UnverifiedVendorImageItem $item
     *
     * @return Source
     *
     * @throws DBALException
     */
    private function persistSource(UnverifiedVendorImageItem $item): Source
    {
        if (!$item->isFound()) {
            throw new \InvalidArgumentException('A "Source" should not be persisted from UnverifiedVendorImageItem that is not "found"');
        }

        $sourceRepository = $this->em->getRepository(Source::class);

        // There may exist a race condition when multiple queues are
        // running. To ensure we don't insert duplicates we need to
        // wrap our search/update/insert in a transaction.
        $this->em->getConnection()->beginTransaction();

        try {
            /** @var Source $source */
            $source = $sourceRepository->findOneBy([
                'matchId' => $item->getIdentifier(),
                'matchType' => $item->getIdentifierType(),
                'vendor' => $item->getVendor(),
            ]);

            if (is_null($source)) {
                $source = new Source();
                $source->setVendor($item->getVendor());
                $source->setMatchId($item->getIdentifier());
                $source->setMatchType($item->getIdentifierType());
                $source->setDate(new \DateTime());

                $this->em->persist($source);
            }

            $source->setOriginalFile($item->getOriginalFile());
            $source->setOriginalContentLength($item->getOriginalContentLength());
            $source->setOriginalLastModified($item->getOriginalLastModified());

            $this->em->flush();
            $this->em->getConnection()->commit();

            return $source;
        } catch (DBALException $dbalException) {
            $this->em->getConnection()->rollBack();

            throw $dbalException;
        }
    }

    /**
     * Try to search the datawell and match source entity.
     *
     * @param Material $material
     * @param string $agency
     * @param string $profile
     *
     * @return bool
     */
    private function mapDatawellSearch(Material $material, string $agency, string $profile): bool
    {
        $found = false;

        $sourceRepository = $this->em->getRepository(Source::class);

        foreach ($material->getIdentifiers() as $is) {
            $source = $sourceRepository->findOneByVendorRank($is->getType(), $is->getId());

            // If we have a 'source' that match the material from the datawell we create the relevant jobs
            // to re-index the source entities.
            if ($source instanceof Source) {
                // A 'source' is not guaranteed to have a valid image in the CDN.
                // If it doesn't it should not be indexed.
                if (!is_null($source->getImage())) {
                    $this->metricsService->counter('no_hit_source_found', 'No-hit source found', 1, ['type' => 'nohit']);

                    $message = new SearchMessage();
                    $message->setIdentifier($source->getMatchId())
                        ->setOperation(VendorState::UPDATE)
                        ->setIdentifierType($source->getMatchType())
                        ->setVendorId($source->getVendor()->getId())
                        ->setImageId($source->getImage()->getId())
                        ->setAgency($agency)
                        ->setProfile($profile)
                        ->setUseSearchCache(true);
                    $this->bus->dispatch($message);

                    $found = true;
                }

                // The 'source' may have had an original image added after last process.
                // In that case it will not have a CDN image but will have an original file.
                elseif (is_null($source->getImage()) && !is_null($source->getOriginalFile())) {
                    $this->metricsService->counter('no_hit_without_image', 'No-hit source found without image', 1, ['type' => 'nohit']);

                    $item = new VendorImageItem($source->getOriginalFile(), $source->getVendor());
                    $this->validatorService->validateRemoteImage($item);

                    if ($item->isFound()) {
                        $this->metricsService->counter('no_hit_without_image_new', 'No-hit source found with new image', 1, ['type' => 'nohit']);

                        $this->dispatchVendorImageMessage($source, VendorState::UPDATE);

                        $found = true;
                    }
                }
            }
        }

        return $found;
    }

    /**
     * Get a valid cache keys for the identifier of given type.
     *
     * Keys should only contain letters (A-Z, a-z), numbers (0-9) and the _ and . symbols.
     *
     * @see https://www.php-fig.org/psr/psr-6/
     * @see https://symfony.com/doc/current/components/cache/cache_items.html#cache-item-keys-and-values
     *
     * @param string $type
     *   The identifier type
     * @param string $identifier
     *   The identifier
     *
     * @return string
     *   The cache key
     */
    private function getValidCacheKey(string $type, string $identifier): string
    {
        if (IdentifierType::PID === $type) {
            $identifier = str_replace(':', '_', $identifier);
            $identifier = str_replace('-', '_', $identifier);
        }

        return $type.'.'.$identifier;
    }
}
