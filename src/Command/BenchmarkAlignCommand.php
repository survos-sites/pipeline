<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand('app:benchmark:align', 'Freeze reviewed institutional crops aligned with unchanged human transcript excerpts')]
final class BenchmarkAlignCommand
{
    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Reviewed alignment specification JSON')] string $specification,
        #[Argument('Frozen human-reference manifest.json')] string $references,
        #[Argument('Harvest capture directory with inventory.json and images')] string $capture,
        #[Argument('New output directory')] string $output,
    ): int {
        if (file_exists($output)) { throw new \InvalidArgumentException('Use a new snapshot directory.'); }
        $spec = json_decode(file_get_contents($specification), true, flags: JSON_THROW_ON_ERROR);
        $source = json_decode(file_get_contents($references), true, flags: JSON_THROW_ON_ERROR);
        $inventory = json_decode(file_get_contents($capture.'/inventory.json'), true, flags: JSON_THROW_ON_ERROR);
        $pages = array_column($source['pages'], null, 'id');
        $institutionPages = array_column($inventory['pages'], null, 'id');
        $fs = new Filesystem();
        $fs->mkdir($output.'/provenance');
        $fs->copy($specification, $output.'/provenance/alignment.json');
        $fs->copy($references, $output.'/provenance/reference-manifest.json');
        $fs->copy($capture.'/inventory.json', $output.'/provenance/institution-inventory.json');
        $fs->copy(dirname($references).'/rights.html', $output.'/provenance/reference-rights.html');
        $fs->copy($capture.'/evidence/copyright.html', $output.'/provenance/institution-rights.html');
        $manifest = ['schema_version' => 1, 'name' => $spec['name'], 'created_at' => gmdate(DATE_ATOM),
            'reference_policy' => $spec['reference_policy'], 'alignment_sha256' => hash_file('sha256', $specification),
            'reference_manifest_sha256' => hash_file('sha256', $references),
            'attribution' => 'Archives New Zealand, Wellington, R17845610; Auckland Museum collaboration; FromThePage contributors. Crops produced by ai-pipeline-demo.',
            'image_license' => $inventory['imageRights'], 'transcript_rights_caveat' => $inventory['pageTranscriptRights'], 'pages' => []];
        foreach ($spec['alignments'] as $alignment) {
            $page = $pages[$alignment['page_id']];
            $institution = $institutionPages[$alignment['institution_id']];
            if ($institution['sequence'] !== $alignment['sequence']) { throw new \RuntimeException('Institution sequence mismatch.'); }
            $imageFile = $capture.'/images/'.$institution['id'].'.jpg';
            $receipt = json_decode(file_get_contents($imageFile.'.json'), true, flags: JSON_THROW_ON_ERROR);
            $referenceFile = dirname($references).'/'.$page['reference'];
            $htmlFile = dirname($references).'/'.$page['id'].'/source.html';
            if (hash_file('sha256', $imageFile) !== $receipt['sha256'] || hash_file('sha256', $referenceFile) !== $page['reference_sha256'] || hash_file('sha256', $htmlFile) !== $page['source_html_sha256']) {
                throw new \RuntimeException('Source integrity check failed.');
            }
            $reference = file_get_contents($referenceFile);
            $fs->copy($referenceFile, $output.'/provenance/'.$page['id'].'.reference.txt');
            $fs->copy($htmlFile, $output.'/provenance/'.$page['id'].'.html');
            foreach ($alignment['regions'] as $region) {
                $id = $region['id'];
                if (!preg_match('/^[a-z0-9-]+$/D', $id) || is_dir($output.'/'.$id)) { throw new \RuntimeException('Invalid or duplicate region ID.'); }
                $text = $region['reference_excerpt'] ?? $reference;
                if ('' === trim($text) || !str_contains($reference, $text)) { throw new \RuntimeException('Excerpt is not verbatim human reference: '.$id); }
                [$x, $y, $width, $height] = $region['crop'];
                $image = new \Imagick($imageFile);
                if (min($x, $y) < 0 || min($width, $height) < 1 || $x + $width > $image->getImageWidth() || $y + $height > $image->getImageHeight()) { throw new \RuntimeException('Crop outside image.'); }
                $image->cropImage($width, $height, $x, $y);
                $image->setImagePage(0, 0, 0, 0);
                $image->setImageFormat('png');
                $image->stripImage();
                $fs->mkdir($output.'/'.$id);
                $image->writeImage($output.'/'.$id.'/image.png');
                $fs->dumpFile($output.'/'.$id.'/reference.txt', $text);
                $manifest['pages'][] = ['id' => $id, 'image' => $id.'/image.png', 'reference' => $id.'/reference.txt',
                    'image_sha256' => hash_file('sha256', $output.'/'.$id.'/image.png'), 'reference_sha256' => hash('sha256', $text),
                    'handwriting' => 'mixed typed and handwritten', 'evaluation_group' => $region['group'],
                    'source_page_id' => $page['id'], 'source_url' => $page['url'], 'institution_id' => $institution['id'],
                    'institution_image_url' => $institution['imageUrl'], 'institution_image_sha256' => $receipt['sha256'],
                    'crop_xywh' => $region['crop'], 'width' => $width, 'height' => $height,
                    'alignment_anchor' => $alignment['anchor'], 'notes' => $region['notes']];
                $io->writeln('Aligned '.$id);
            }
        }
        $fs->dumpFile($output.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        return 0;
    }
}
