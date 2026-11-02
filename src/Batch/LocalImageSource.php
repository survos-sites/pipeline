<?php

declare(strict_types=1);

namespace App\Batch;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

/** Bounded local intake, independent of HTTP upload and AI providers. */
final class LocalImageSource
{
    public const MAX_BYTES = 20 * 1024 * 1024;
    public const MIME_EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    /** @return \Generator<array{path: string, bytes: string, mime: string, sha256: string}> */
    public function images(string $directory): \Generator
    {
        $root = realpath($directory);
        if ($root === false || !is_dir($root)) {
            throw new \InvalidArgumentException('Source must be an existing local directory.');
        }
        $storage = new Filesystem(new LocalFilesystemAdapter($root));
        $count = 0;
        foreach ($storage->listContents('', true)->filter(fn ($entry) => $entry->isFile()) as $entry) {
            $path = $entry->path();
            if (!in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                continue;
            }
            if (++$count > 1000) {
                throw new \RuntimeException('Import limit is 1,000 images. Split this directory into smaller batches.');
            }
            if ($storage->fileSize($path) > self::MAX_BYTES) {
                throw new \RuntimeException(sprintf('Image exceeds 20 MiB: %s', $path));
            }
            $stream = $storage->readStream($path);
            try {
                $bytes = stream_get_contents($stream, self::MAX_BYTES + 1);
            } finally {
                fclose($stream);
            }
            if ($bytes === false || strlen($bytes) > self::MAX_BYTES) {
                throw new \RuntimeException(sprintf('Cannot read bounded image: %s', $path));
            }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            if (!isset(self::MIME_EXTENSIONS[$mime]) || @getimagesizefromstring($bytes) === false) {
                throw new \RuntimeException(sprintf('Not a supported image: %s', $path));
            }
            yield ['path' => $path, 'bytes' => $bytes, 'mime' => $mime, 'sha256' => hash('sha256', $bytes)];
        }
    }

    public function dataUri(string $path): string
    {
        if (!is_file($path) || filesize($path) > self::MAX_BYTES) {
            throw new \RuntimeException('Local AI image is missing or exceeds 20 MiB.');
        }
        $bytes = file_get_contents($path, length: self::MAX_BYTES + 1);
        $mime = $bytes === false ? '' : (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if ($bytes === false || strlen($bytes) > self::MAX_BYTES || !isset(self::MIME_EXTENSIONS[$mime])) {
            throw new \RuntimeException('Local AI image must be JPEG, PNG, WebP or GIF.');
        }

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }
}
