<?php
/**
 * @file
 * Helper command to insert message into queue system.
 */

namespace App\Command;

use App\Message\CoverUserUploadMessage;
use App\Message\SearchMessage;
use App\Message\SearchNoHitsMessage;
use App\Utils\Types\IdentifierType;
use App\Utils\Types\VendorState;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Class QueueInsertCommand.
 */
#[AsCommand(name: 'app:queue:insert')]
class QueueInsertCommand extends Command
{
    /**
     * QueueInsertCommand constructor.
     *
     * @param MessageBusInterface $bus
     */
    public function __construct(
        private readonly MessageBusInterface $bus
    ) {
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this
            ->setDescription('Create job in queue system with a given message and topic')
            ->addOption('topic', null, InputOption::VALUE_REQUIRED, 'Topic to send into queue system.')
            ->addOption('message', null, InputOption::VALUE_OPTIONAL, 'String encode json message')
            ->addOption('with-test-message', null, InputOption::VALUE_NONE, 'Use default test message with given topic')
            ->addOption('vendorState', null, InputOption::VALUE_OPTIONAL, 'The vendor state to set: \'insert\', \'update\', \'delete\'');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $topic = $input->getOption('topic');
        $message = $input->getOption('message');
        $withTestMessage = $input->getOption('with-test-message');
        $vendorState = $input->getOption('vendorState');

        if ($withTestMessage) {
            // Test messages for easy testing.
            switch ($topic) {
                case 'UserUploadImage':
                    $message = new CoverUserUploadMessage();
                    $message->setIdentifierType(IdentifierType::PID);
                    $message->setIdentifier('1234567890');
                    $message->setVendorId('15');
                    $message->setImageUrl('https://images.bogportalen.dk/images/9788740050134.jpg');
                    $message->setOperation($vendorState ?? VendorState::INSERT);
                    break;

                case 'Search':
                    $message = new SearchMessage();
                    $message->setIdentifier('9788799239535')
                        ->setOperation($vendorState ?? VendorState::UPDATE)
                        ->setIdentifierType(IdentifierType::ISBN)
                        ->setVendorId(1)
                        ->setImageId(1);
                    break;

                case 'SearchNoHits':
                    $message = new SearchNoHitsMessage();
                    $message->setIdentifier('9788799239535')
                        ->setIdentifierType(IdentifierType::ISBN);
                    break;

                default:
                    throw new \RuntimeException('No test message exists for the given topic');
            }
        } elseif (!isset($message)) {
            throw new \RuntimeException('Missing message');
        }

        // Send message into the system.
        $this->bus->dispatch((object) $message);

        return Command::SUCCESS;
    }
}
