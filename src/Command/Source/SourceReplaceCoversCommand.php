<?php

/**
 * @file
 * Helper command to search the source table for potential new images.
 */

namespace App\Command\Source;

use App\Entity\Source;
use App\Entity\Vendor;
use App\Message\VendorImageMessage;
use App\Repository\SourceRepository;
use App\Repository\VendorRepository;
use App\Utils\Types\VendorState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Class SourceReplaceCoversCommand.
 */
#[AsCommand(name: 'app:source:replace')]
class SourceReplaceCoversCommand extends Command
{
    /**
     * SourceDownloadCoversCommand constructor.
     *
     * @param EntityManagerInterface $em
     *   The entity manager to access that database
     * @param MessageBusInterface $bus
     *   Message bus to send messages (jobs)
     * @param SourceRepository $sourceRepository
     *   Source repository class to access source entities
     * @param VendorRepository $vendorRepository
     *   Vendor repository class to access vendor entities
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly SourceRepository $sourceRepository,
        private readonly VendorRepository $vendorRepository,
    ) {
        parent::__construct();
    }

    /**
     * Define the command.
     */
    protected function configure(): void
    {
        $this->setDescription('Replace cover for a given identifier')
            ->addOption('vendor-id', null, InputOption::VALUE_REQUIRED, 'Vendor to replace cover for')
            ->addOption('identifier', null, InputOption::VALUE_REQUIRED, 'Identifier to replace cover for')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'URL to the new cover to use in replacement');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $vendorId = $input->getOption('vendor-id');
        $identifier = $input->getOption('identifier');
        $url = $input->getOption('url');

        /** @var Vendor $vendor */
        $vendor = $this->vendorRepository->findOneBy(['id' => $vendorId]);
        /** @var Source $source */
        $source = $this->sourceRepository->findOneBy(['matchId' => $identifier, 'vendor' => $vendor]);

        // Replace image in source entity.
        $source->setOriginalFile($url);
        $source->setLastIndexed(new \DateTime('now -1 day'));
        $source->setOriginalContentLength(null);
        $source->setOriginalLastModified(null);
        $this->em->flush();

        // Trigger download, validate and reindex with the new cover.
        $message = new VendorImageMessage();
        $message->setOperation(VendorState::UPDATE)
            ->setIdentifier($identifier)
            ->setVendorId($vendorId)
            ->setIdentifierType($source->getMatchType());
        $this->bus->dispatch($message);

        return Command::SUCCESS;
    }
}
