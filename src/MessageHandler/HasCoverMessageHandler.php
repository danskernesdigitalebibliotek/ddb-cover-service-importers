<?php

namespace App\MessageHandler;

use App\Exception\HasCoverException;
use App\Message\HasCoverMessage;
use App\Service\HasCoverService;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Class HasCoverMessageHandler.
 */
class HasCoverMessageHandler implements MessageHandlerInterface
{
    /**
     * HasCoverMessageHandler constructor.
     *
     * @param HasCoverService $hasCoverService
     */
    public function __construct(
        private readonly HasCoverService $hasCoverService
    ) {
    }

    /**
     * @param HasCoverMessage $message
     *
     * @throws HasCoverException
     * @throws TransportExceptionInterface
     */
    public function __invoke(HasCoverMessage $message): void
    {
        $this->hasCoverService->post($message->getPid(), $message->getCoverExists());
    }
}
