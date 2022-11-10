<?php

/**
 * @file
 * Console commands to execute and test Open Platform authentication.
 */

namespace App\Command\OpenPlatform;

use App\Service\OpenPlatform\AuthenticationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class OpenPlatformAuthCommand.
 */
#[AsCommand(name: 'app:openplatform:auth')]
class OpenPlatformAuthCommand extends Command
{
    /**
     * OpenPlatformAuthCommand constructor.
     *
     * @param AuthenticationService $authentication
     *   Open Platform authentication service
     */
    public function __construct(
        private readonly AuthenticationService $authentication
    ) {
        parent::__construct();
    }

    /**
     * Define the command.
     */
    protected function configure(): void
    {
        $this->setDescription('Use environment configuration to test authentication')
            ->setHelp('Gets oAuth2 access token to the Open Platform')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Refresh the access token')
            ->addOption('agency-id', null, InputOption::VALUE_OPTIONAL, 'Use this agency id in the auth request (defaults to environment configured)', '');
    }

    /**
     * {@inheritdoc}
     *
     * Uses the authentication service to get an access token form the open
     * platform.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $refresh = $input->getOption('refresh');
        $agency = (string) $input->getOption('agency-id');
        $token = $this->authentication->getAccessToken($agency, $refresh);

        $msg = 'Access token: '.$token;
        $separator = str_repeat('-', strlen($msg) + 2);
        $output->writeln($separator);
        $output->writeln(' Access token: '.$token);
        $output->writeln($separator);

        return Command::SUCCESS;
    }
}
