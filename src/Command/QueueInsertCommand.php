<?php
/**
 * @file
 * Helper command to insert message into queue system.
 */

namespace App\Command;

use App\Message\ProcessMessageSearch;
use App\Utils\Message\CoverUploadProcessMessage;
use App\Utils\Message\ProcessMessage;
use App\Utils\Types\IdentifierType;
use App\Utils\Types\VendorState;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Class QueueInsertCommand.
 */
class QueueInsertCommand extends Command
{
    private $bus;

    protected static $defaultName = 'app:queue:insert';

    /**
     * QueueInsertCommand constructor.
     *
     * @param MessageBusInterface $bus
     */
    public function __construct(MessageBusInterface $bus)
    {
        $this->bus = $bus;

        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
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
                case 'UserUploadImageTopic':
//                    $processMessage = new CoverUploadProcessMessage();
//                    $processMessage->setIdentifierType(IdentifierType::PID);
//                    $processMessage->setIdentifier('1234567890');
//                    $processMessage->setVendorId('15');
//                    $processMessage->setImageUrl('https://www.danskernesdigitalebibliotek.dk/fileadmin/_kulturstyrelsen/images/ddb/logo.png');
//                    $processMessage->setOperation($vendorState ?? VendorState::INSERT);
//                    $message = JSON::encode($processMessage);
                    break;

                case 'SearchTopic':
                    $message = new ProcessMessageSearch();
                    $message->setIdentifier('9788799239535')
                        ->setOperation($vendorState ?? VendorState::UPDATE)
                        ->setIdentifierType(IdentifierType::ISBN)
                        ->setVendorId(1)
                        ->setImageId(1);
                    break;

                case 'SearchNoHitsTopic':
//                    $processMessage = new ProcessMessage();
//                    $processMessage->setIdentifier('9788799239535')
//                        ->setIdentifierType(IdentifierType::ISBN);
//                    $message = JSON::encode($processMessage);
                    break;

                default:
                    throw new \RuntimeException('No test message exists for the given topic');
            }
        } elseif (!isset($message)) {
            throw new \RuntimeException('Missing message');
        }

        // Send message into the system.
        $this->bus->dispatch($message);

        return 0;
    }
}
