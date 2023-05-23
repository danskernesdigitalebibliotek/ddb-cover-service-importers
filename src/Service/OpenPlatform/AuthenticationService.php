<?php

/**
 * @file
 * Service that handle authentication against the Open Platform.
 *
 * Uses oAuth2 request to get access token and stores in cache until expire to
 * speed up the process and make as few calls as possible.
 */

namespace App\Service\OpenPlatform;

use App\Exception\OpenPlatformAuthException;
use JsonException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Class AuthenticationService.
 */
class AuthenticationService
{
    // Used to give the token some grace-period, so it will not expire will
    // being used. Currently, the token is valid for 30 days. So we set the
    // limit to be 1 day, so it will be refreshed before it expires.
    final public const TOKEN_EXPIRE_LIMIT = 86400;

    /**
     * Authentication constructor.
     *
     * @param CacheItemPoolInterface $cache
     *   Cache to store access token
     * @param LoggerInterface $logger
     *   Logger object to send stats to ES
     * @param HttpClientInterface $httpClient
     *   Http Client
     * @param string $authUrl
     *   Authentication URL
     * @param string $agency
     *   Default agency to use when authenticated
     * @param string $clientId
     *   oAuth client id
     * @param string $clientSecret
     *   oAuth client secret
     */
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly HttpClientInterface $httpClient,
        private readonly string $authUrl,
        private readonly string $agency,
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {
    }

    /**
     * Get access token.
     *
     * If not in local cache a request to the open platform for a new token will be executed.
     *
     * @param string $agency
     *   Agency to use for authentication, if not set default from environment will be used
     * @param bool $refresh
     *   If TRUE refresh token. Default: FALSE.
     *
     *   The access token
     *
     * @throws ClientExceptionInterface
     * @throws InvalidArgumentException
     * @throws JsonException
     * @throws OpenPlatformAuthException
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */
    public function getAccessToken(string $agency = '', bool $refresh = false): string
    {
        return $this->authenticate($agency, $refresh);
    }

    /**
     * Authenticate against open platform.
     *
     * @param string $agency
     *   Agency to use for authentication, if not set default from environment will be used
     * @param bool $refresh
     *   If TRUE refresh token. Default: FALSE.
     *
     *   The token if successful else the empty string,
     *
     * @throws OpenPlatformAuthException
     * @throws TransportExceptionInterface
     * @throws JsonException
     * @throws InvalidArgumentException
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     */
    private function authenticate(string $agency = '', bool $refresh = false): string
    {
        // Try getting item from cache.
        $item = $this->cache->getItem('openplatform.access_token_'.$agency);

        // Check if the access token is located in local file cache to speed up the
        // process.
        if ($item->isHit() && !$refresh) {
            $this->logger->info('Access token requested', [
                'service' => 'AuthenticationService',
                'cache' => true,
            ]);

            return $item->get();
        } else {
            try {
                $response = $this->httpClient->request('POST', $this->authUrl, [
                    'body' => [
                        'grant_type' => 'password',
                        'username' => '@'.(empty($agency) ? $this->agency : $agency),
                        'password' => '@'.(empty($agency) ? $this->agency : $agency),
                    ],
                    'auth_basic' => [
                        $this->clientId,
                        $this->clientSecret,
                    ],
                ]);
            } catch (TransportExceptionInterface $exception) {
                $this->logger->error('Access token not acquired', [
                    'service' => 'AuthenticationService',
                    'cache' => false,
                    'message' => $exception->getMessage(),
                ]);

                throw new OpenPlatformAuthException($exception->getMessage(), (int) $exception->getCode(), $exception);
            } catch (\Exception $exception) {
                $this->logger->error('Unknown error in acquiring access token', [
                    'service' => 'AuthenticationService',
                    'message' => $exception->getMessage(),
                ]);

                throw new OpenPlatformAuthException($exception->getMessage(), (int) $exception->getCode(), $exception);
            }

            if (200 !== $response->getStatusCode()) {
                throw new OpenPlatformAuthException('Authentication service returned non 200 status code', $response->getStatusCode());
            }

            // Get the content and parse json object as an array.
            $content = $response->getContent();
            $json = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            $this->logger->info('Access token acquired', [
                'service' => 'AuthenticationService',
                'cache' => false,
            ]);

            // Store access token in local cache.
            $item->expiresAfter($json['expires_in'] - $this::TOKEN_EXPIRE_LIMIT);
            $item->set($json['access_token']);
            $this->cache->save($item);

            return $json['access_token'];
        }
    }
}
