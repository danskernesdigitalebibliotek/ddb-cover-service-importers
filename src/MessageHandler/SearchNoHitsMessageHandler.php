<?php

/**
 * @file
 * Handle no-hits queue processing.
 */

namespace App\MessageHandler;

use App\Entity\Search;
use App\Entity\Source;
use App\Exception\MaterialTypeException;
use App\Exception\OpenPlatformAuthException;
use App\Exception\OpenPlatformSearchException;
use App\Message\SearchMessage;
use App\Message\SearchNoHitsMessage;
use App\Message\VendorImageMessage;
use App\Service\OpenPlatform\SearchService;
use App\Service\VendorService\VendorImageValidatorService;
use App\Utils\CoverVendor\VendorImageItem;
use App\Utils\OpenPlatform\Material;
use App\Utils\Types\IdentifierType;
use App\Utils\Types\VendorState;
use Doctrine\DBAL\ConnectionException;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Exception\GuzzleException;
use ItkDev\MetricsBundle\Service\MetricsService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
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
        private readonly MetricsService $metricsService
    ) {
    }

    /**
     * @param SearchNoHitsMessage $message
     *
     * @throws MaterialTypeException
     * @throws OpenPlatformAuthException
     * @throws MaterialTypeException
     * @throws OpenPlatformAuthException
     * @throws OpenPlatformSearchException
     * @throws Exception
     */
    public function __invoke(SearchNoHitsMessage $message)
    {
        $identifier = $message->getIdentifier();

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

                        return;
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
        } else {
            // Try to search the data well and match source entity. This might work as there is a race between when
            // vendors have a given cover and when the material is indexed into the data-well.
            $type = $message->getIdentifierType();
            $material = $this->searchService->search($identifier, $type);
            $sourceRepos = $this->em->getRepository(Source::class);

            foreach ($material->getIdentifiers() as $is) {
                $source = $sourceRepos->findOneByVendorRank($is->getType(), $is->getId());

                // Found matches in source table based on the data well search, so create jobs to re-index the source
                // entities.
                if ($source instanceof Source) {
                    // Also check that the source record has an image from the vendor as not all do.
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
                    } elseif (is_null($source->getImage()) && !is_null($source->getOriginalFile())) {
                        // If the source image is null. It might have been made available since we asked the vendor for the
                        // cover.
                        $this->metricsService->counter('no_hit_without_image', 'No-hit source found without image', 1, ['type' => 'nohit']);

                        $item = new VendorImageItem();
                        $item->setOriginalFile($source->getOriginalFile());
                        try {
                            $this->validatorService->validateRemoteImage($item);
                        } catch (GuzzleException) {
                            // Just remove this job from the queue, on fetch errors. This will ensure that the job is not
                            // re-queue in infinity loop.
                            throw new UnrecoverableMessageHandlingException('Image fetch error in validation');
                        }

                        if ($item->isFound()) {
                            $this->metricsService->counter('no_hit_without_image_new', 'No-hit source found with new image', 1, ['type' => 'nohit']);

                            $message = new VendorImageMessage();
                            $message->setOperation(VendorState::UPDATE)
                                ->setIdentifier($source->getMatchId())
                                ->setVendorId($source->getVendor()->getId())
                                ->setIdentifierType($source->getMatchType());
                            $this->bus->dispatch($message);
                        }
                    }
                }
            }

            return;
        }

        $this->metricsService->counter('no_hit_failed', 'No-hit mapping not found', 1, ['type' => 'nohit']);

        // Log current not handled no-hit.
        $this->logger->info('No hit', [
            'service' => 'SearchNoHitsProcessor',
            'message' => 'No hit found and send to auto generate queue',
            'identifier' => $identifier,
        ]);
    }
}
