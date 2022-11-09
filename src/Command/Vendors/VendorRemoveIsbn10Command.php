<?php

namespace App\Command\Vendors;

use App\Entity\Image;
use App\Entity\Source;
use App\Message\DeleteMessage;
use App\Repository\VendorRepository;
use App\Utils\Types\VendorState;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\ResultSetMappingBuilder;
use ItkDev\MetricsBundle\Service\MetricsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:vendor:remove-isbn10',
    description: 'Remove ISBN10 duplicate covers',
)]
class VendorRemoveIsbn10Command extends Command
{
    private const LIMIT = 100;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
        private readonly MetricsService $metricsService,
        private readonly VendorRepository $vendorRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('vendorId', InputArgument::REQUIRED, 'Vendor id found in the database');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $progressBar = new ProgressBar($output);
        $progressBar->setFormat('debug');

        $vendorId = (int) $input->getArgument('vendorId');
        $vendor = $this->vendorRepository->find($vendorId);
        $offset = 0;

        if (null === $vendor) {
            $io->error('Unknown vendor id');

            return Command::FAILURE;
        }

        $labels = [
            'type' => 'vendor',
            'vendorName' => $vendor->getName(),
            'vendorId' => $vendor->getId(),
        ];

        $progressBar->start();
        do {
            $sources = $this->getIsbn10Sources($vendorId, self::LIMIT, $offset);
            $count = count($sources);

            foreach ($sources as $source) {
                $message = new DeleteMessage();
                $message->setOperation(VendorState::DELETE)
                    ->setIdentifier($source->getMatchId())
                    ->setIdentifierType($source->getMatchType())
                    ->setVendorId($source->getVendor()->getId());

                $this->bus->dispatch($message);
            }

            $this->entityManager->clear();

            $offset += $count;
            $progressBar->advance($count);

            $this->metricsService->counter('vendor_deleted_total', 'Number of deleted records', $count, $labels);
        } while (0 < $count);

        $progressBar->finish();

        return Command::SUCCESS;
    }

    /**
     * Get Sources with ISBN10 identifier from vendor.
     *
     * @param int $vendorId
     * @param int $limit
     * @param int $offset
     *
     * @return Source[]
     */
    public function getIsbn10Sources(int $vendorId, int $limit, int $offset): array
    {
        $rsm = new ResultSetMappingBuilder($this->entityManager);
        $rsm->addRootEntityFromClassMetadata(Source::class, 's');
        $rsm->addJoinedEntityFromClassMetadata(Image::class, 'i', 's', 'image', [], ResultSetMappingBuilder::COLUMN_RENAMING_INCREMENT);

        $select = $rsm->generateSelectClause();
        $sql = sprintf("SELECT %s FROM source s LEFT JOIN image i ON s.image_id = i.id WHERE match_type = 'isbn' AND vendor_id = ? AND CHAR_LENGTH(match_id)<=10 ORDER BY id ASC LIMIT ? OFFSET ?", $select);
        $query = $this->entityManager->createNativeQuery($sql, $rsm);
        $query->setParameter(1, $vendorId);
        $query->setParameter(2, $limit);
        $query->setParameter(3, $offset);

        return $query->getResult();
    }
}
