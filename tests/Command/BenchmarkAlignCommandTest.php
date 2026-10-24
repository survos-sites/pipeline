<?php

declare(strict_types=1);
namespace App\Tests\Command;

use App\Command\BenchmarkAlignCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

final class BenchmarkAlignCommandTest extends TestCase
{
    private string $dir;
    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/benchmark-align-'.bin2hex(random_bytes(5));
        $fs = new Filesystem();
        $fs->mkdir([$this->dir.'/capture/images', $this->dir.'/capture/evidence', $this->dir.'/refs/1']);
        $image = new \Imagick(); $image->newImage(20, 10, 'white'); $image->setImageFormat('jpeg');
        $image->writeImage($this->dir.'/capture/images/FL1.jpg');
        file_put_contents($this->dir.'/capture/images/FL1.jpg.json', json_encode(['sha256' => hash_file('sha256', $this->dir.'/capture/images/FL1.jpg')]));
        file_put_contents($this->dir.'/capture/evidence/copyright.html', 'rights');
        file_put_contents($this->dir.'/refs/rights.html', 'rights');
        file_put_contents($this->dir.'/refs/1/reference.txt', 'Hello handwritten world');
        file_put_contents($this->dir.'/refs/1/source.html', 'source evidence');
        $this->json('/capture/inventory.json', ['imageRights' => [], 'pageTranscriptRights' => 'unverified', 'pages' => [['id' => 'FL1', 'sequence' => 4, 'imageUrl' => 'https://example.org/image']]]);
        $this->json('/refs/manifest.json', ['pages' => [['id' => '1', 'url' => 'https://example.org/page', 'reference' => '1/reference.txt', 'reference_sha256' => hash('sha256', 'Hello handwritten world'), 'source_html_sha256' => hash('sha256', 'source evidence')]]]);
        $this->json('/spec.json', ['name' => 'test', 'reference_policy' => 'unchanged', 'alignments' => [['page_id' => '1', 'institution_id' => 'FL1', 'sequence' => 4, 'anchor' => 'checked', 'regions' => [['id' => 'sample', 'crop' => [2, 1, 8, 5], 'group' => 'handwriting-region', 'reference_excerpt' => 'handwritten', 'notes' => 'test']]]]]);
    }
    protected function tearDown(): void { (new Filesystem())->remove($this->dir); }
    public function testProducesExactCropAndUnchangedReferenceWithProvenance(): void
    {
        $this->runAlignment();
        $m = json_decode(file_get_contents($this->dir.'/output/manifest.json'), true);
        self::assertSame('handwritten', file_get_contents($this->dir.'/output/sample/reference.txt'));
        self::assertSame([8, 5], array_slice(getimagesize($this->dir.'/output/sample/image.png'), 0, 2));
        self::assertSame(hash_file('sha256', $this->dir.'/output/sample/image.png'), $m['pages'][0]['image_sha256']);
        self::assertSame('Hello handwritten world', file_get_contents($this->dir.'/output/provenance/1.reference.txt'));
    }
    public function testRejectsChangedReferenceBeforeCropping(): void
    {
        file_put_contents($this->dir.'/refs/1/reference.txt', 'silently corrected text');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Source integrity');
        $this->runAlignment();
    }
    public function testRejectsFabricatedExcerpt(): void
    {
        $s = json_decode(file_get_contents($this->dir.'/spec.json'), true);
        $s['alignments'][0]['regions'][0]['reference_excerpt'] = 'invented';
        $this->json('/spec.json', $s);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not verbatim');
        $this->runAlignment();
    }
    private function runAlignment(): void
    {
        (new BenchmarkAlignCommand())(new SymfonyStyle(new ArrayInput([]), new BufferedOutput()), $this->dir.'/spec.json', $this->dir.'/refs/manifest.json', $this->dir.'/capture', $this->dir.'/output');
    }
    private function json(string $path, array $data): void { file_put_contents($this->dir.$path, json_encode($data, JSON_THROW_ON_ERROR)); }
}
