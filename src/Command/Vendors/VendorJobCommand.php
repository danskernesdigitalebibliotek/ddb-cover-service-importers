<?php

/**
 * @file
 * Helper command to send single vendor import job into the queue system.
 */

namespace App\Command\Vendors;

use App\Message\DeleteMessage;
use App\Message\VendorImageMessage;
use App\Utils\Types\VendorState;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Class VendorJobCommand.
 */
class VendorJobCommand extends Command
{
    protected static $defaultName = 'app:vendor:job-test';

    private MessageBusInterface $bus;

    /**
     * VendorJobCommand constructor.
     *
     * @param MessageBusInterface $bus
     *   Message queue bus
     */
    public function __construct(MessageBusInterface $bus)
    {
        $this->bus = $bus;

        parent::__construct();
    }

    /**
     * Define the command.
     */
    protected function configure(): void
    {
        $this->setDescription('Send job to test import job')
            ->addArgument('operation', InputArgument::REQUIRED, 'The operation to dispatch (insert/update/delete).')
            ->addArgument('identifier', InputArgument::REQUIRED, 'Material identifier.')
            ->addArgument('type', InputArgument::REQUIRED, 'Identifier type e.g. ISBN.')
            ->addArgument('vendorId', InputArgument::REQUIRED, 'Vendor id found in the database');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $operation = $input->getArgument('operation');
        $identifier = $input->getArgument('identifier');
        $type = $input->getArgument('type');
        $vendorId = (int) $input->getArgument('vendorId');

        switch ($operation) {
            case VendorState::INSERT:
            case VendorState::UPDATE:
                $message = new VendorImageMessage();
                break;

            case VendorState::DELETE:
                $message = new DeleteMessage();
                break;

            default:
                $output->writeln('Unknown event type given as input.');

                return Command::FAILURE;
        }

        $message->setOperation($operation)
            ->setIdentifier($identifier)
            ->setVendorId($vendorId)
            ->setIdentifierType($type);
        $this->bus->dispatch($message);

        return Command::SUCCESS;
    }
}
