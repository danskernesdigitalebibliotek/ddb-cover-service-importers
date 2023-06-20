<?php

/**
 * @file
 * Handle image storing at Cloudinary.
 */

namespace App\Service\CoverStore;

use App\Exception\CoverStoreAlreadyExistsException;
use App\Exception\CoverStoreCredentialException;
use App\Exception\CoverStoreException;
use App\Exception\CoverStoreInvalidResourceException;
use App\Exception\CoverStoreNotFoundException;
use App\Exception\CoverStoreTooLargeFileException;
use App\Exception\CoverStoreUnexpectedException;
use App\Utils\CoverStore\CoverStoreItem;
use Cloudinary\Api\Exception\ApiError;
use Cloudinary\Api\Search\SearchApi;
use Cloudinary\Api\Upload\UploadApi;
use Cloudinary\Configuration\Configuration;

/**
 * Class CloudinaryCoverStoreService.
 */
class CloudinaryCoverStoreService implements CoverStoreInterface
{
    /**
     * Use to make paginated search (off-set in cloudinary).
     *
     * This is an array to ensure the next cursor used is for the requested search. So they are indexed by search query.
     *
     * @var array
     */
    private array $nextCursor = [];

    /**
     * Used to terminate store search when no more content is found.
     */
    private const LAST_ELEMENT_REACHED = -1;

    /**
     * CloudinaryCoverStoreService constructor.
     *
     * @param string $cloudinaryCloudName
     *   The account cloud name
     * @param string $cloudinaryApiKey
     *   API key
     * @param string $cloudinaryApiSecret
     *   API secret
     *
     * @throws CoverStoreCredentialException
     */
    public function __construct(string $cloudinaryCloudName, string $cloudinaryApiKey, string $cloudinaryApiSecret)
    {
        if (empty($cloudinaryCloudName)) {
            throw new CoverStoreCredentialException('Missing Cloudinary configuration in environment: CLOUDINARY_CLOUD_NAME');
        }
        if (empty($cloudinaryApiKey)) {
            throw new CoverStoreCredentialException('Missing Cloudinary configuration in environment: CLOUDINARY_API_KEY');
        }
        if (empty($cloudinaryApiSecret)) {
            throw new CoverStoreCredentialException('Missing Cloudinary configuration in environment: CLOUDINARY_API_SECRET');
        }

        // Set global Cloudinary configuration.
        Configuration::instance([
            'cloud' => [
                'cloud_name' => $cloudinaryCloudName,
                'api_key' => $cloudinaryApiKey,
                'api_secret' => $cloudinaryApiSecret,
                'secure' => true,
            ],
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function upload(string $url, string $folder, string $identifier, array $tags = []): CoverStoreItem
    {
        $options = [
            'public_id' => $identifier,
            'folder' => $folder,
            'tags' => implode(',', $tags),
        ];

        try {
            $uploadApi = new UploadApi();
            $response = $uploadApi->upload($url, $options);
        } catch (ApiError $error) {
            throw $this->createCloudinaryException($error);
        }

        $item = new CoverStoreItem();
        $item->setId($response['public_id'])
            ->setUrl($response['secure_url'])
            ->setVendor($folder)
            ->setSize($response['bytes'])
            ->setWidth((int) $response['width'])
            ->setHeight((int) $response['height'])
            ->setImageFormat($response['format'])
            ->setOriginalFile($url)
            ->setCrc($response['signature']);

        return $item;
    }

    /**
     * {@inheritdoc}
     */
    public function remove(string $folder, string $identifier): void
    {
        try {
            $uploadApi = new UploadApi();
            $response = $uploadApi->destroy($folder.'/'.$identifier, ['invalidate' => true]);
        } catch (\Exception $error) {
            $message = $error->getMessage();

            if (preg_match('/^Invalid.*/', $message)) {
                throw new CoverStoreCredentialException($message, (int) $error->getCode());
            }

            throw new CoverStoreException($message, (int) $error->getCode());
        }

        $status = $response['result'];
        if (preg_match('/^not found.*/', (string) $status)) {
            throw new CoverStoreNotFoundException($status, 400);
        }

        if ('ok' !== $status) {
            throw new CoverStoreException($status);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws \Cloudinary\Api\Exception\GeneralError
     */
    public function search(string $folder, string $rawQuery = null, bool $useRecursiveSearch = false): array
    {
        $search = new SearchApi();
        $search->expression('folder='.$folder)
            ->sortBy('public_id', 'desc')
            ->maxResults(100);

        $nextCursorIndex = sha1($folder.$rawQuery ?? '');
        if (isset($this->nextCursor[$nextCursorIndex])) {
            if (self::LAST_ELEMENT_REACHED === $this->nextCursor[$nextCursorIndex]) {
                // No more results.
                return [];
            }

            $search->nextCursor($this->nextCursor[$nextCursorIndex]);
        }

        if (!is_null($rawQuery)) {
            $search->expression($rawQuery);
        }
        $result = $search->execute()->getArrayCopy();

        $items = [];
        foreach ($result['resources'] as $resources) {
            $item = new CoverStoreItem();
            $item->setId($resources['public_id'])
                ->setUrl($resources['secure_url'])
                ->setVendor($folder)
                ->setSize($resources['bytes'])
                ->setWidth((int) $resources['width'])
                ->setHeight((int) $resources['height'])
                ->setImageFormat($resources['format']);
            $items[] = $item;
        }

        if ($useRecursiveSearch) {
            // Store next cursor to continue this search on next request.
            $this->nextCursor[$nextCursorIndex] = $result['next_cursor'] ?? self::LAST_ELEMENT_REACHED;
        }

        return $items;
    }

    /**
     * {@inheritdoc}
     */
    public function move(string $source, string $destination, bool $overwrite = false): CoverStoreItem
    {
        try {
            // This is done like this with overwrite because you get an "Invalid Signature" error if you send overwrite
            // false in the request.
            $uploadApi = new UploadApi();
            if (true === $overwrite) {
                $response = $uploadApi->rename($source, $destination, ['invalidate' => true, 'overwrite' => true]);
            } else {
                $response = $uploadApi->rename($source, $destination, ['invalidate' => true]);
            }
        } catch (\Exception $error) {
            throw $this->createCloudinaryException($error);
        }

        $parts = explode('/', $destination);

        $item = new CoverStoreItem();
        $item->setId($response['public_id'])
            ->setUrl($response['secure_url'])
            ->setVendor($parts[0])
            ->setSize($response['bytes'])
            ->setWidth((int) $response['width'])
            ->setHeight((int) $response['height'])
            ->setImageFormat($response['format'])
            ->setCrc($response['signature']);

        return $item;
    }

    /**
     * Helper function to transform Cloudinary error into CoverStore Exceptions.
     *
     *   Error generated by the cloudinary library
     *
     * @return coverStoreAlreadyExistsException|CoverStoreCredentialException|CoverStoreException|CoverStoreNotFoundException|CoverStoreTooLargeFileException|CoverStoreUnexpectedException|CoverStoreInvalidResourceException
     *   Exception based on the error inputted
     */
    private function createCloudinaryException(\Exception $error): coverStoreAlreadyExistsException|CoverStoreCredentialException|CoverStoreException|CoverStoreNotFoundException|CoverStoreTooLargeFileException|CoverStoreUnexpectedException|CoverStoreInvalidResourceException
    {
        $message = $error->getMessage();

        // Try to convert to cover store exception.
        if (preg_match('/^to_public_id(.+)already exists$/', $message)) {
            return new CoverStoreAlreadyExistsException($message, (int) $error->getCode());
        }

        if (preg_match('/^Invalid.*/', $message)) {
            return new CoverStoreCredentialException($message, (int) $error->getCode());
        }

        if (preg_match('/^Resource not found.*/', $message)) {
            return new CoverStoreNotFoundException($message, (int) $error->getCode());
        }

        if (preg_match('/^File size too large.*/', $message)) {
            return new CoverStoreTooLargeFileException($message, (int) $error->getCode());
        }

        if (preg_match('/^Resource is invalid.*/', $message)) {
            return new CoverStoreInvalidResourceException($message, (int) $error->getCode());
        }

        if (520 === (int) $error->getCode()) {
            return new CoverStoreUnexpectedException($error->getMessage(), $error->getCode());
        }

        return new CoverStoreException($message, (int) $error->getCode());
    }
}
