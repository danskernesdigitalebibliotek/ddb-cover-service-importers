<?php

/**
 * @file
 * Handle no-hits queue processing.
 */

namespace App\MessageHandler;

use App\Entity\Search;
use App\Entity\Source;
use App\Exception\MaterialTypeException;
use App\Exception\OpenPlatformSearchException;
use App\Message\SearchMessage;
use App\Message\SearchNoHitsMessage;
use App\Message\VendorImageMessage;
use App\Service\OpenPlatform\SearchService;
use App\Service\VendorService\SupportsSingleIdentifierInterface;
use App\Service\VendorService\VendorImageValidatorService;
use App\Utils\CoverVendor\UnverifiedVendorImageItem;
use App\Utils\CoverVendor\VendorImageItem;
use App\Utils\OpenPlatform\Material;
use App\Utils\Types\IdentifierType;
use App\Utils\Types\VendorState;
use Doctrine\DBAL\ConnectionException;
use Doctrine\ORM\EntityManagerInterface;
use http\Exception\InvalidArgumentException;
use ItkDev\MetricsBundle\Service\MetricsService;
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
        private readonly iterable $singleIdentifierVendors
    ) {
    }

    /**
     * Invoke handler.
     *
     * @param SearchNoHitsMessage $message
     *
     * @throws MaterialTypeException
     * @throws OpenPlatformSearchException
     * @throws \Doctrine\DBAL\Exception
     */
    public function __invoke(SearchNoHitsMessage $message): void
    {
        $identifier = $message->getIdentifier();
        $found = false;

        if (IdentifierType::PID === $message->getIdentifierType()) {
            // For PID's of type '-katalog' we might not have access to them
            // in the datawell through our search profile. We try to guess the
            // faust from the PID and search by that to instead.
            $found = $this->mapCatalogIdentifier($identifier);
        }

        if (!$found) {
            // Try to search the data well and match source entity. If the 'source'
            // have been added by the vendor before it's available in the datawell
            // it will not have been indexed.
            $material = $this->searchService->search($identifier, $message->getIdentifierType());
            $found = $this->mapDatawellSearch($material);
        }

        if (!$found && isset($material)) {
            // Some vendors can return a potential URL for the cover given an identifier.
            // Get a list of these from the matched identifiers in the datawell.
            $unverifiedVendorImageItems = $this->getUnverifiedVendorImageItems($material);

            $found = $this->processUnverifiedImageItems($unverifiedVendorImageItems);
        }

        if (!$found) {
            $this->metricsService->counter('no_hit_failed', 'No-hit mapping not found', 1, ['type' => 'nohit']);

            // Log current not handled no-hit.
            $this->logger->info('No hit', [
                'service' => 'SearchNoHitsProcessor',
                'message' => 'No hit found and send to auto generate queue',
                'identifier' => $identifier,
            ]);
        }
    }

    /**
     * Get unverified image items from vendors for a given material.
     *
     * @param Material $material
     *
     * @return UnverifiedVendorImageItem[]
     */
    private function getUnverifiedVendorImageItems(Material $material): array
    {
        $items = [];

        foreach ($material->getIdentifiers() as $identifier) {
            /** @var SupportsSingleIdentifierInterface $vendor */
            foreach ($this->singleIdentifierVendors as $vendor) {
                if ($vendor->supportsIdentifierType($identifier->getType())) {
                    $items[] = $vendor->getUnverifiedVendorImageItem($identifier->getId(), $identifier->getType());
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
     */
    private function persistSource(UnverifiedVendorImageItem $item): Source
    {
        if (!$item->isFound()) {
            throw new InvalidArgumentException('A "Source" should not be persisted from UnverifiedVendorImageItem that is not "found"');
        }

        $sourceRepository = $this->em->getRepository(Source::class);

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

            $this->em->persist($source);
        }

        $source->setOriginalFile($item->getOriginalFile());
        $source->setOriginalContentLength($item->getOriginalContentLength());
        $source->setOriginalLastModified($item->getOriginalLastModified());

        $this->em->flush();

        return $source;
    }

    /**
     * Try to search the datawell and match source entity.
     *
     * @param Material $material
     *
     * @return bool
     *
     * @throws MaterialTypeException
     * @throws OpenPlatformSearchException
     */
    private function mapDatawellSearch(Material $material): bool
    {
        $found = false;

        $sourceRepository = $this->em->getRepository(Source::class);

        foreach ($material->getIdentifiers() as $is) {
            $source = $sourceRepository->findOneByVendorRank($is->getType(), $is->getId());

            // If we have a 'source' that match the material from the datawell we create the relevant jobs
            // to re-index the source entities
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
                        ->setUseSearchCache(true);
                    $this->bus->dispatch($message);

                    $found = true;
                }

                // The 'source' may have had an original image added after last process.
                // In that case it will not have a CDN image but will have an original file.
                elseif (is_null($source->getImage()) && !is_null($source->getOriginalFile())) {
                    $this->metricsService->counter('no_hit_without_image', 'No-hit source found without image', 1, ['type' => 'nohit']);

                    $item = new VendorImageItem();
                    $item->setOriginalFile($source->getOriginalFile());
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
     * Map 'katalog' identifier to 'basis' identifier.
     *
     * @param string $identifier
     *
     * @return bool
     *
     * @throws \Doctrine\DBAL\Exception
     */
    private function mapCatalogIdentifier(string $identifier): bool
    {
        // If it's a "katalog" identifier, we will try to check if a matching
        // "faust" identifier exits and create the mapping.
        if (strpos($identifier, '-katalog:')) {
            $searchRepos = $this->em->getRepository(Search::class);
            $faust = null;

            try {
                // Try to get basic pid.
                $faust = Material::translatePidToFaust($identifier);

                // There may exist a race condition when multiple queues are
                // running. To ensure we don't insert duplicates we need to
                // wrap our search/update/insert in a transaction.
                $this->em->getConnection()->beginTransaction();

                try {
                    /* @var Search $search */
                    $search = $searchRepos->findOneBy([
                        'isIdentifier' => $faust,
                        'isType' => IdentifierType::FAUST,
                    ]);

                    if (!empty($search)) {
                        $newSearch = new Search();
                        $newSearch->setIsType(IdentifierType::PID)
                            ->setIsIdentifier($identifier)
                            ->setSource($search->getSource())
                            ->setImageUrl((string) $search->getImageUrl())
                            ->setImageFormat((string) $search->getImageFormat())
                            ->setWidth($search->getWidth())
                            ->setHeight($search->getHeight());
                        $this->em->persist($newSearch);

                        $this->em->flush();
                        $this->em->getConnection()->commit();

                        // Log that a new record was created.
                        $this->metricsService->counter('no_hit_katelog_mapped', 'No-hit katelog was mapped', 1, ['type' => 'nohit']);
                        $this->logger->info('Katalog recorded have been generated', [
                            'service' => 'SearchNoHitsProcessor',
                            'message' => 'New katalog search record have been generated',
                            'identifier' => $identifier,
                            'source' => $faust,
                        ]);

                        return true;
                    } else {
                        $this->metricsService->counter('no_hit_katelog_not_mapped', 'No-hit katelog not mapped', 1, ['type' => 'nohit']);
                    }
                } catch (\Exception $exception) {
                    $this->em->getConnection()->rollBack();

                    $this->metricsService->counter('no_hit_katelog_error', 'No-hit katelog error', 1, ['type' => 'nohit']);
                    $this->logger->error('Database exception: '.$exception::class, [
                        'service' => 'SearchNoHitsProcessor',
                        'message' => $exception->getMessage(),
                        'identifier' => $identifier,
                        'source' => $faust,
                    ]);
                }
            } catch (ConnectionException $exception) {
                $this->metricsService->counter('no_hit_katelog_error', 'No-hit katelog error', 1, ['type' => 'nohit']);
                $this->logger->error('Database Connection Exception', [
                    'service' => 'SearchNoHitsProcessor',
                    'message' => $exception->getMessage(),
                    'identifier' => $identifier,
                    'source' => $faust ?: 'unknown',
                ]);
            }
        }

        return false;
    }
}
