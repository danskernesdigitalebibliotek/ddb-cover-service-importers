<?php

/**
 * @file
 * Console command to find images that are uploaded but not found in image table.
 */

namespace App\Command\Image;

use App\Entity\Image;
use App\Entity\Source;
use App\Entity\Vendor;
use App\Service\CoverStore\CoverStoreInterface;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class MissingImagesCommand.
 */
#[AsCommand(name: 'app:image:missing')]
class MissingImagesCommand extends Command
{
    /**
     * MissingImagesCommand constructor.
     *
     * @param CoverStoreInterface $store
     * @param EntityManager $em
     */
    public function __construct(
        private readonly CoverStoreInterface $store,
        private readonly EntityManagerInterface $em
    ) {
        parent::__construct();
    }

    /**
     * Define the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setDescription('Find images in CoverStore that is not in the image table')
            ->addOption('vendor-id', null, InputOption::VALUE_REQUIRED, 'Limit the re-index to vendor with this id number')
            ->addOption('identifier', null, InputOption::VALUE_REQUIRED, 'If set only this identifier will be re-index (requires that you set vendor id)');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $vendorId = $input->getOption('vendor-id');
        $identifier = $input->getOption('identifier');

        $vendorRepos = $this->em->getRepository(Vendor::class);
        $vendor = $vendorRepos->findById($vendorId);
        if (is_null($vendor)) {
            throw new \RuntimeException('Vendor not found');
        }
        $vendor = reset($vendor);

        $query = 'SELECT s FROM App\Entity\Source s WHERE s.image IS NULL';
        if (!is_null($identifier)) {
            if (!is_null($vendorId)) {
                $query .= ' AND s.matchId = \''.$identifier.'\'';
            } else {
                $output->writeln('<error>Missing vendor id required in combination with identifier</error>');

                return Command::FAILURE;
            }
        }
        if (!is_null($vendorId)) {
            $query .= ' AND s.vendor = '.$vendorId;
        }

        $query = $this->em->createQuery($query);

        /* @var Source $source */
        foreach ($query->toIterable() as $source) {
            $searchQuery = 'public_id:'.$vendor->getName().'/'.addcslashes($source->getMatchId(), ':');
            $items = $this->store->search($searchQuery, $vendor->getName());
            if (!empty($items)) {
                $item = reset($items);
                $image = new Image();
                $image->setImageFormat($item->getImageFormat())
                    ->setSize($item->getSize())
                    ->setWidth($item->getWidth())
                    ->setHeight($item->getHeight())
                    ->setCoverStoreURL($item->getUrl());
                $this->em->persist($image);
                $source->setImage($image);
                $this->em->flush();
            }
        }

        return Command::SUCCESS;
    }
}
