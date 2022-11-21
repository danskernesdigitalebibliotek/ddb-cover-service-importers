<?php

namespace App\Command\Vendors;

use App\Message\DeleteMessage;
use App\Repository\SourceRepository;
use App\Utils\Types\VendorState;
use Doctrine\ORM\Query;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:vendor:remove-by-url-pattern',
    description: 'Add a short description for your command',
)]
class VendorRemoveByUrlPatternCommand extends Command
{
    private const LIMIT = 100;

    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly SourceRepository $sourceRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDefinition(
            new InputDefinition([
                new InputOption('vendor-id', null, InputOption::VALUE_REQUIRED, 'Vendor id found in the database', 0),
                new InputOption('pattern', null, InputOption::VALUE_REQUIRED, 'Original image file pattern', '%{00000000-0000-0000-0000-000%'),
            ])
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $vendorId = (int) $input->getOption('vendor-id');
        $pattern = $input->getOption('pattern');

        $progressBar = new ProgressBar($output);
        $progressBar->setFormat('debug');
        $progressBar->start();

        $query = $this->getSources($vendorId, $pattern);

        foreach ($query->toIterable() as $source) {
            $message = new DeleteMessage();
            $message->setOperation(VendorState::DELETE)
                ->setIdentifier($source->getMatchId())
                ->setIdentifierType($source->getMatchType())
                ->setVendorId($source->getVendor()->getId());

            $this->bus->dispatch($message);
            $progressBar->advance();
        }

        $progressBar->finish();

        // Send CLI cursor to empty line.
        $output->writeln('');

        return Command::SUCCESS;
    }

    /**
     * Get Sources with pattern in original filename limit to vendor.
     *
     * @param int $vendorId
     * @param string $pattern
     *
     * @return Query
     */
    public function getSources(int $vendorId, string $pattern): Query
    {
        $queryBuilder = $this->sourceRepository->createQueryBuilder('s')
            ->where('s.vendor = :vendorId')
            ->andWhere('s.originalFile LIKE :pattern')
            ->orderBy('s.originalFile', 'DESC')
            ->setParameter('vendorId', $vendorId)
            ->setParameter('pattern', $pattern);

        return $queryBuilder->getQuery();
    }
}
