<?php
/**
 * @file
 * OverDrive API client
 *
 * @see https://developer.overdrive.com/discovery-apis
 */

namespace App\Service\VendorService\OverDrive\Api;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\Cache\Adapter\AdapterInterface;

/**
 * Class Client.
 */
class Client
{
    private const MAX_RETRIES = 5;

    private const OAUTH_URL = 'https://oauth.overdrive.com/token';
    private const LIBRARY_ACCOUNT_ENDPOINT = 'https://api.overdrive.com/v1/libraries';
    private const USER_AGENT = 'cover.dandigbib.org';

    /** @var string */
    private $libraryId;

    /** @var string */
    private $clientId;

    /** @var string */
    private $clientSecret;

    /** @var AdapterInterface */
    private $cache;

    /** @var ClientInterface */
    private $httpClient;

    /** @var string */
    private $authorization;

    /** @var string */
    private $productsEndpoint;

    /**
     * Client constructor.
     *
     * @param string $libraryId
     * @param string $clientId
     * @param string $clientSecret
     * @param AdapterInterface $cache
     * @param ClientInterface $httpClient
     */
    public function __construct(string $libraryId, string $clientId, string $clientSecret, AdapterInterface $cache, ClientInterface $httpClient)
    {
        $this->libraryId = $libraryId;
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;

        $this->cache = $cache;
        $this->httpClient = $httpClient;
    }

    /**
     * Get cover url from OverDrive crossRefId.
     *
     * @param string $crossRefId
     *   The OverDrive 'crossRefId'
     *
     * @return string|null
     *   The cover url or null
     *
     * @throws GuzzleException
     */
    public function getCoverUrl(string $crossRefId): ?string
    {
        $requests = 0;

        // The OverDrive api fails at random but still gives a 200 response.
        // Only indication of failure is that the 'products' key is missing
        // from the response body.
        // If the products key is missing we retry the request 'MAX_RETRIES' times
        do {
            $response = $this->httpClient->request('GET', $this->getProductsEndpoint(), [
                    'headers' => [
                        'User-Agent' => self::USER_AGENT,
                        'Content-Type' => 'application/json',
                        'Authorization' => $this->getAuthorization(),
                    ],
                    'query' => [
                        'crossRefId' => $crossRefId,
                    ],
            ]);

            $content = $response->getBody()->getContents();
            $json = json_decode($content, false);

            // Check for the product and images keys.
            $product = isset($json->products) && is_array($json->products) ? array_shift($json->products) : null;
            $images = $product->images ?? null;

            ++$requests;
        } while (!$images && $requests < self::MAX_RETRIES);

        return $images ? $images->cover->href : null;
    }

    /**
     * Get the products endpoint for the account used.
     *
     * @return string
     *   The complete URI for the products endpoint
     *
     * @throws GuzzleException
     * @throws InvalidArgumentException
     */
    private function getProductsEndpoint(): string
    {
        if (empty($this->productsEndpoint)) {
            $this->productsEndpoint = $this->fetchProductsEndpoint();
        }

        return $this->productsEndpoint;
    }

    /**
     * Fetch the products endpoint for the account used from the Library API.
     *
     * @see https://developer.overdrive.com/apis/library-account
     *
     * @return string
     *   The complete URI for the products endpoint
     *
     * @throws GuzzleException
     * @throws InvalidArgumentException
     */
    private function fetchProductsEndpoint(): string
    {
        $item = $this->cache->getItem('overdrive.api.productsEndpoint');

        if ($item->isHit()) {
            return $item->get();
        } else {
            $endpoint = self::LIBRARY_ACCOUNT_ENDPOINT.'/'.$this->libraryId;
            $response = $this->httpClient->request('GET', $endpoint, [
                    'headers' => [
                        'User-Agent' => self::USER_AGENT,
                        'Content-Type' => 'application/json',
                        'Authorization' => $this->getAuthorization(),
                    ],
            ]);

            $content = $response->getBody()->getContents();
            $json = json_decode($content, false);

            // Store products endpoint in local cache.
            $item->set($json->links->products->href);
            $this->cache->save($item);

            return $json->links->products->href;
        }
    }

    /**
     * Authenticate against OverDrive.
     *
     * @see https://developer.overdrive.com/apis/client-auth
     *
     * @return string
     *   The value for the authentication header
     *
     * @throws GuzzleException
     * @throws InvalidArgumentException
     */
    private function authenticate(): string
    {
        $item = $this->cache->getItem('overdrive.api.access_token');

        if ($item->isHit()) {
            return $item->get();
        } else {
            $authorization = base64_encode($this->clientId.':'.$this->clientSecret);

            $response = $this->httpClient->request('POST', self::OAUTH_URL, [
                    'headers' => [
                        'Authorization' => 'Basic '.$authorization,
                        'Content-Type' => 'application/x-www-form-urlencoded;charset=UTF-8',
                    ],
                    'form_params' => [
                        'grant_type' => 'client_credentials',
                    ],
            ]);

            $content = $response->getBody()->getContents();
            $json = json_decode($content, true);

            // Store authorization in local cache.
            $authorization = $json['token_type'].' '.$json['access_token'];

            $item->expiresAfter($json['expires_in']);
            $item->set($authorization);
            $this->cache->save($item);

            return $authorization;
        }
    }

    /**
     * Get OverDrive authorization.
     *
     * If not in local cache an request to OverDrive for a new authorization will
     * be executed.
     *
     * @return string
     *   The value for the authentication header
     *
     * @throws GuzzleException
     * @throws InvalidArgumentException
     */
    private function getAuthorization(): string
    {
        if (empty($this->authorization)) {
            $this->authorization = $this->authenticate();
        }

        return $this->authorization;
    }
}
