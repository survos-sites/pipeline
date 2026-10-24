<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand('app:benchmark:compare', 'Compare complete runs of the same frozen inputs, separately by evaluation group')]
final class BenchmarkCompareCommand
{
    public function __invoke(SymfonyStyle $io, #[Argument] string $first, #[Argument] string $second, #[Argument('New JSON report path')] string $output): int
    {
        if (file_exists($output)) { throw new \InvalidArgumentException('Use a new report path.'); }
        $runs = array_map(static fn ($p) => json_decode(file_get_contents($p), true, flags: JSON_THROW_ON_ERROR), [$first, $second]);
        if ($runs[0]['manifest_sha256'] !== $runs[1]['manifest_sha256']) { throw new \RuntimeException('Different frozen inputs.'); }
        $identities = [];
        foreach ($runs as $run) {
            if ($run['aggregate']['succeeded'] !== $run['aggregate']['selected']) { throw new \RuntimeException('Incomplete run.'); }
            $identity = [];
            foreach ($run['pages'] as $page) { $identity[$page['id']] = [$page['image_sha256'], $page['reference_sha256'], $page['evaluation_group']]; }
            ksort($identity);
            $identities[] = $identity;
        }
        if ($identities[0] !== $identities[1]) { throw new \RuntimeException('Different page selection, hashes or groups.'); }
        $summary = ['manifest_sha256' => $runs[0]['manifest_sha256'], 'interpretation' => 'Agreement with unchanged human transcripts, not independently adjudicated accuracy. Tiny sample; groups overlap by source page.', 'runs' => []];
        $rows = [];
        foreach ($runs as $i => $run) {
            $groups = [];
            foreach ($run['pages'] as $page) {
                $group = $page['evaluation_group'];
                if (!isset($groups[$group])) { $groups[$group] = ['inputs' => 0, 'reference_characters' => 0, 'character_errors' => 0, 'reference_words' => 0, 'word_errors' => 0, 'seconds' => 0.0]; }
                ++$groups[$group]['inputs'];
                foreach (['reference_characters', 'character_errors', 'reference_words', 'word_errors', 'seconds'] as $key) { $groups[$group][$key] += $page[$key]; }
            }
            foreach ($groups as $group => &$stats) {
                $stats['cer'] = $stats['reference_characters'] ? $stats['character_errors'] / $stats['reference_characters'] : null;
                $stats['wer'] = $stats['reference_words'] ? $stats['word_errors'] / $stats['reference_words'] : null;
                $rows[] = [$run['requested_model'], $group, $stats['inputs'], sprintf('%.2f%%', 100 * $stats['cer']), sprintf('%.2f%%', 100 * $stats['wer'])];
            }
            unset($stats);
            $summary['runs'][] = ['requested_model' => $run['requested_model'], 'returned_models' => array_values(array_unique(array_column($run['pages'], 'returned_model'))), 'source_report' => realpath([$first, $second][$i]), 'groups' => $groups];
        }
        (new Filesystem())->dumpFile($output, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        $io->table(['Model', 'Group', 'Inputs', 'CER', 'WER'], $rows);
        return 0;
    }
}
