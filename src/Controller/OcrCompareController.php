<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ocr\OcrEngine;
use App\Ocr\OcrResult;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** One image, every OCR engine configured on this machine, side by side. */
final class OcrCompareController extends AbstractController
{
    private const ALLOWED = ['image/png', 'image/jpeg', 'image/webp', 'image/tiff'];

    /** @param iterable<OcrEngine> $engines */
    public function __construct(
        #[AutowireIterator('app.ocr_engine')] private readonly iterable $engines,
    ) {
    }

    #[Route('/ocr', name: 'app_ocr_compare', methods: ['GET', 'POST'])]
    #[Template('ocr/compare.html.twig')]
    public function compare(Request $request): array
    {
        $engines = [...$this->engines];
        $available = array_values(array_filter($engines, static fn (OcrEngine $e): bool => $e->isAvailable()));
        $view = [
            'engines' => $available,
            'skipped' => array_values(array_filter($engines, static fn (OcrEngine $e): bool => !$e->isAvailable())),
            'results' => [],
            'error' => null,
            'imageData' => null,
            'includePaid' => $request->request->getBoolean('include_paid'),
        ];
        if (!$request->isMethod('POST')) {
            return $view;
        }

        $file = $request->files->get('image');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return $view + ['error' => 'Choose an image file.'];
        }
        $mime = (string) $file->getMimeType();
        if (!in_array($mime, self::ALLOWED, true)) {
            return ['error' => 'Images only (PNG, JPEG, WebP, TIFF); got '.$mime.'.'] + $view;
        }

        $path = $file->getPathname();
        $view['imageData'] = in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)
            ? sprintf('data:%s;base64,%s', $mime, base64_encode((string) file_get_contents($path)))
            : null;
        $view['results'] = array_map(
            fn (OcrEngine $e): OcrResult => $e->isPaid() && !$view['includePaid']
                ? new OcrResult($e->id(), $e->label(), error: 'not run: tick "include paid engines"')
                : $e->recognize($path, $mime),
            $available,
        );

        return $view;
    }
}
