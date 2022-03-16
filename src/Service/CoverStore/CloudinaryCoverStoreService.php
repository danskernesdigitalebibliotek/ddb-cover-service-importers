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
use Cloudinary\Error;

/**
 * Class CloudinaryCoverStoreService.
 */
class CloudinaryCoverStoreService implements CoverStoreInterface
{
    /**
     * CloudinaryCoverStoreService constructor.
     *
     * @param string $bindCloudinaryCloudName
     *   The account cloud name
     * @param string $bindCloudinaryApiKey
     *   API key
     * @param string $bindCloudinaryApiSecret
     *   API secret
     *
     * @throws CoverStoreCredentialException
     */
    public function __construct(string $bindCloudinaryCloudName, string $bindCloudinaryApiKey, string $bindCloudinaryApiSecret)
    {
        if (empty($bindCloudinaryCloudName)) {
            throw new CoverStoreCredentialException('Missing Cloudinary configuration in environment: CLOUDINARY_CLOUD_NAME');
        }
        if (empty($bindCloudinaryApiKey)) {
            throw new CoverStoreCredentialException('Missing Cloudinary configuration in environment: CLOUDINARY_API_KEY');
        }
        if (empty($bindCloudinaryApiSecret)) {
            throw new CoverStoreCredentialException('Missing Cloudinary configuration in environment: CLOUDINARY_API_SECRET');
        }

        // Set global Cloudinary configuration.
        \Cloudinary::config([
            'cloud_name' => $bindCloudinaryCloudName,
            'api_key' => $bindCloudinaryApiKey,
            'api_secret' => $bindCloudinaryApiSecret,
            'secure' => true, ]);
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
            $image = \Cloudinary\Uploader::upload($url, $options);
        } catch (\Cloudinary\Error $error) {
            throw $this->createCloudinaryException($error);
        }

        $item = new CoverStoreItem();
        $item->setId($image['public_id'])
            ->setUrl($image['secure_url'])
            ->setVendor($folder)
            ->setSize($image['bytes'])
            ->setWidth((int) $image['width'])
            ->setHeight((int) $image['height'])
            ->setImageFormat($image['format'])
            ->setOriginalFile($url)
            ->setCrc($image['signature']);

        return $item;
    }

    /**
     * {@inheritdoc}
     */
    public function remove(string $folder, string $identifier): void
    {
        try {
            $result = \Cloudinary\Uploader::destroy($folder.'/'.$identifier, ['invalidate' => true]);
        } catch (\Cloudinary\Error $error) {
            $message = $error->getMessage();

            if (preg_match('/^Invalid.*/', $message)) {
                throw new CoverStoreCredentialException($message, (int) $error->getCode());
            }

            throw new CoverStoreException($message, (int) $error->getCode());
        }

        $status = $result['result'];
        if (preg_match('/^not found.*/', $status)) {
            throw new CoverStoreNotFoundException($status, 400);
        }

        if ('ok' !== $status) {
            throw new CoverStoreException($status);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function search(string $folder, string $rawQuery = null): array
    {
        $search = new \Cloudinary\Search();
        $search
            ->expression('folder='.$folder)
            ->sort_by('public_id', 'desc')
            ->max_results(100);

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
            if (true === $overwrite) {
                $result = \Cloudinary\Uploader::rename($source, $destination, ['invalidate' => true, 'overwrite' => $overwrite]);
            } else {
                $result = \Cloudinary\Uploader::rename($source, $destination, ['invalidate' => true]);
            }
        } catch (\Cloudinary\Error $error) {
            throw $this->createCloudinaryException($error);
        }

        $parts = explode('/', $destination);

        $item = new CoverStoreItem();
        $item->setId($result['public_id'])
            ->setUrl($result['secure_url'])
            ->setVendor($parts[0])
            ->setSize($result['bytes'])
            ->setWidth((int) $result['width'])
            ->setHeight((int) $result['height'])
            ->setImageFormat($result['format'])
            ->setCrc($result['signature']);

        return $item;
    }

    /**
     * Helper function to transform Cloudinary error into CoverStore Exceptions.
     *
     * @param error $error
     *   Error generated by the cloudinary library
     *
     * @return coverStoreAlreadyExistsException|CoverStoreCredentialException|CoverStoreException|CoverStoreNotFoundException|CoverStoreTooLargeFileException|CoverStoreUnexpectedException|CoverStoreInvalidResourceException
     *   Exception based on the error inputted
     */
    private function createCloudinaryException(Error $error)
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
