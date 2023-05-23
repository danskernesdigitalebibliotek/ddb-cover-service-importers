<?php

/**
 * @file
 */

namespace App\Command\Source;

use App\Entity\Source;
use App\Service\VendorService\VendorImageValidatorService;
use App\Utils\CoverVendor\VendorImageItem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:source:update-image-meta')]
class SourceUpdateImageMetaCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly VendorImageValidatorService $validator
    ) {
        parent::__construct();
    }

    /**
     * Define the command.
     */
    protected function configure(): void
    {
        $this->setDescription('Update image metadata information')
            ->addOption('vendor-id', null, InputOption::VALUE_OPTIONAL, 'Limit to this vendor')
            ->addOption('identifier', null, InputOption::VALUE_OPTIONAL, 'Only for this identifier');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $vendorId = $input->getOption('vendor-id');
        $identifier = $input->getOption('identifier');
        $batchSize = 50;
        $i = 0;

        // @TODO: Move into repository and use query builder.
        $queryStr = 'SELECT s FROM App\Entity\Source s WHERE s.originalFile IS NOT NULL AND s.originalContentLength IS NULL AND s.originalLastModified IS NULL';
        if (!is_null($identifier)) {
            $queryStr = 'SELECT s FROM App\Entity\Source s WHERE s.matchId='.$identifier;
        }
        if (!is_null($vendorId)) {
            $queryStr .= ' AND s.vendor = '.$vendorId;
        }
        $query = $this->em->createQuery($queryStr);

        /** @var Source $source */
        foreach ($query->toIterable() as $source) {
            $item = new VendorImageItem();

            $originalFile = $source->getOriginalFile();
            $item->setOriginalFile($originalFile);

            $this->validator->validateRemoteImage($item);

            if ($item->isFound()) {
                $source->setOriginalLastModified($item->getOriginalLastModified());
                $source->setOriginalContentLength($item->getOriginalContentLength());
            }

            // Free memory when batch size is reached.
            if (0 === ($i % $batchSize)) {
                $this->em->flush();
                $this->em->clear();
            }

            ++$i;
        }

        $this->em->flush();
        $this->em->clear();

        return Command::SUCCESS;
    }
}
