<?php

declare(strict_types=1);

namespace App\Ocr;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Runs Ztein/figmark (Docling) through its CLI. figmark has no HTTP server, so
 * this shells out to `uv run figmark convert` in a local checkout. Picture
 * description is off unless that checkout's figmark.yaml configures it.
 */
final class FigmarkEngine implements OcrEngine
{
    public function __construct(private readonly string $figmarkDir)
    {
    }

    public function id(): string
    {
        return 'figmark';
    }

    public function label(): string
    {
        return 'figmark (Docling)';
    }

    public function isAvailable(): bool
    {
        return '' !== $this->figmarkDir && is_file($this->figmarkDir.'/pyproject.toml');
    }

    public function isPaid(): bool
    {
        return false;
    }

    public function recognize(string $path, string $mimeType): OcrResult
    {
        $started = hrtime(true);
        $fs = new Filesystem();
        $dir = sys_get_temp_dir().'/figmark-'.bin2hex(random_bytes(6));
        try {
            $fs->mkdir($dir);
            $ext = pathinfo($path, PATHINFO_EXTENSION) ?: 'png';
            $input = $dir.'/input.'.$ext;
            $fs->copy($path, $input);

            $process = new Process(['uv', 'run', 'figmark', 'convert', $input, '-o', $dir.'/out'], $this->figmarkDir, null, null, 300);
            $process->run();
            $markdown = $dir.'/out/input.md';
            if (!$process->isSuccessful() || !is_file($markdown)) {
                throw new \RuntimeException(trim($process->getErrorOutput()) ?: 'figmark produced no output');
            }

            return new OcrResult($this->id(), $this->label(), (string) file_get_contents($markdown), $this->elapsed($started));
        } catch (\Throwable $e) {
            return new OcrResult($this->id(), $this->label(), '', $this->elapsed($started), $e->getMessage());
        } finally {
            $fs->remove($dir);
        }
    }

    private function elapsed(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1e6);
    }
}
