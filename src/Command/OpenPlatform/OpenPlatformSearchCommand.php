<?php

/**
 * @file
 * Console commands to execute and test Open Platform search.
 */

namespace App\Command\OpenPlatform;

use App\Service\OpenPlatform\SearchService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class OpenPlatformSearchCommand.
 */
class OpenPlatformSearchCommand extends Command
{
    protected static $defaultName = 'app:openplatform:search';

    private SearchService $search;

    /**
     * OpenPlatformSearchCommand constructor.
     *
     * @param SearchService $search
     *   The open platform search service
     */
    public function __construct(SearchService $search)
    {
        $this->search = $search;

        parent::__construct();
    }

    /**
     * Define the command.
     */
    protected function configure(): void
    {
        $this->setDescription('Use environment configuration to test search')
            ->setHelp('Try search request against the open platform')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'The material id (isbn, faust, pid)')
            ->addOption('identifier', null, InputOption::VALUE_REQUIRED, 'Identifier type e.g. ISBN.')
            ->addOption('without-search-cache', null, InputOption::VALUE_NONE, 'If set do not use search cache during re-index');
    }

    /**
     * {@inheritdoc}
     *
     * Execute an data well search and output the result.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $is = (string) $input->getOption('identifier');
        $type = (string) $input->getOption('type');
        $withOutSearchCache = $input->getOption('without-search-cache');

        $material = $this->search->search($is, $type, $withOutSearchCache);
        $output->writeln($material);

        return 0;
    }
}
