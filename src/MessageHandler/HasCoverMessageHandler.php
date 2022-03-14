<?php

namespace App\MessageHandler;

use App\Message\HasCoverMessage;
use App\Service\HasCoverService;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

/**
 * Class HasCoverMessageHandler.
 */
class HasCoverMessageHandler implements MessageHandlerInterface
{
    private HasCoverService $hasCoverService;

    /**
     * HasCoverMessageHandler constructor.
     *
     * @param HasCoverService $hasCoverService
     */
    public function __construct(HasCoverService $hasCoverService)
    {
        $this->hasCoverService = $hasCoverService;
    }

    /**
     * @param HasCoverMessage $message
     *
     * @throws \App\Exception\HasCoverException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    public function __invoke(HasCoverMessage $message)
    {
        $this->hasCoverService->post($message->getPid(), $message->getCoverExists());
    }
}
