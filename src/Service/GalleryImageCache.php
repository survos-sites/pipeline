<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\GalleryImage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Mime\MimeTypesInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class GalleryImageCache
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly Filesystem $filesystem,
        private readonly MimeTypesInterface $mimeTypes,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%env(AI_TOOLS_SHARED_DIR)%')]
        private readonly string $cacheDir,
    ) {
    }

    public function cacheOriginal(GalleryImage $image): void
    {
        if ($image->localOriginalPath && is_file($image->localOriginalPath)) {
            return;
        }

        if ($existingPath = $this->existingOriginalPath($image)) {
            $image->localOriginalPath = $existingPath;

            return;
        }

        $dir = $this->cacheDir . '/gallery/' . $image->code;
        $this->filesystem->mkdir($dir);

        $downloadPath = $dir . '/original.download';
        $this->filesystem->dumpFile($downloadPath, $this->readSource($image->sourceUrl));

        $mime = $this->mimeTypes->guessMimeType($downloadPath) ?? 'image/jpeg';
        $extension = $this->mimeTypes->getExtensions($mime)[0] ?? 'jpg';
        $originalPath = $dir . '/original.' . $extension;
        $this->filesystem->rename($downloadPath, $originalPath, true);

        $image->localOriginalPath = $originalPath;
    }

    private function readSource(string $sourceUrl): string
    {
        if (str_starts_with($sourceUrl, 'http://') || str_starts_with($sourceUrl, 'https://')) {
            return $this->httpClient->request('GET', $sourceUrl, ['timeout' => 60])->getContent();
        }

        $sourcePath = str_starts_with($sourceUrl, 'file://')
            ? substr($sourceUrl, 7)
            : $this->projectDir . '/public/' . ltrim($sourceUrl, '/');

        if (!is_file($sourcePath)) {
            throw new \RuntimeException(sprintf('Source image not found: %s', $sourcePath));
        }

        $contents = file_get_contents($sourcePath);
        if ($contents === false) {
            throw new \RuntimeException(sprintf('Cannot read source image: %s', $sourcePath));
        }

        return $contents;
    }

    private function existingOriginalPath(GalleryImage $image): ?string
    {
        $paths = glob($this->cacheDir . '/gallery/' . $image->code . '/original.*') ?: [];
        foreach ($paths as $path) {
            if (!str_ends_with($path, '.download') && is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
