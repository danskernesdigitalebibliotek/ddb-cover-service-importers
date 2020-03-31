<?php
/**
 * @file
 * Helper command to insert message into queue system.
 */

namespace App\Command;

use App\Utils\Message\CoverUploadProcessMessage;
use App\Utils\Message\ProcessMessage;
use App\Utils\Types\IdentifierType;
use App\Utils\Types\VendorState;
use Enqueue\Client\ProducerInterface;
use Enqueue\Util\JSON;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class QueueInsertCommand.
 */
class QueueInsertCommand extends Command
{
    private $producer;

    protected static $defaultName = 'app:queue:insert';

    /**
     * QueueInsertCommand constructor.
     *
     * @param ProducerInterface $producer
     */
    public function __construct(ProducerInterface $producer)
    {
        $this->producer = $producer;

        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Insert')
            ->addOption('topic', null, InputOption::VALUE_REQUIRED, 'Topic to send into queue system.')
            ->addOption('message', null, InputOption::VALUE_OPTIONAL, 'String encode json message')
            ->addOption('with-test-message', null, InputOption::VALUE_NONE, 'Use default test message with given topic');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $topic = $input->getOption('topic');
        $message = $input->getOption('message');
        $withTestMessage = $input->getOption('with-test-message');

        if ($withTestMessage) {
            // Test messages for easy testing.
            switch ($topic) {
                case 'UploadImageTopic':
                    $processMessage = new CoverUploadProcessMessage();
                    $processMessage->setIdentifierType(IdentifierType::PID);
                    $processMessage->setIdentifier('1234567890');
                    $processMessage->setVendorId('12');
                    $processMessage->setImageUrl('https://www.danskernesdigitalebibliotek.dk/fileadmin/_kulturstyrelsen/images/ddb/logo.png');
                    $processMessage->setOperation(VendorState::INSERT);
                    $message = JSON::encode($processMessage);
                    break;

                case 'SearchTopic':
                    $processMessage = new ProcessMessage();
                    $processMessage->setIdentifier('9788799239535')
                        ->setOperation(VendorState::UPDATE)
                        ->setIdentifierType(IdentifierType::ISBN)
                        ->setVendorId(1)
                        ->setImageId(1);
                    $message = JSON::encode($processMessage);
                    break;

                default:
                    throw new \RuntimeException('No test message exists for the given topic');
            }
        } elseif (!isset($message)) {
            throw new \RuntimeException('Missing message');
        }

        // Send message into the system.
        $this->producer->sendEvent($topic, $message);

        return 0;
    }
}
