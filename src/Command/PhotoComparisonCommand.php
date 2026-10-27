<?php

declare(strict_types=1);
namespace App\Command;

use App\Repository\GalleryImageRepository;
use App\Service\GalleryImageCache;
use App\Task\PhotoComparisonTask;
use App\Workflow\TaskSubject;
use Doctrine\ORM\EntityManagerInterface;
use Survos\AiWorkflowBundle\Execution\OperationCache;
use Survos\AiWorkflowBundle\Result\SourceDescription;
use Survos\ClaimsBundle\Service\ClaimIngestor;
use Survos\ClaimsBundle\Service\RunMeta;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand('app:case:photo', 'Run the typewriter photograph with and without NARA context.')]
final readonly class PhotoComparisonCommand
{
    public function __construct(private GalleryImageRepository $images, private GalleryImageCache $files, private PhotoComparisonTask $task, private OperationCache $cache, private ClaimIngestor $claims, private EntityManagerInterface $em, #[Autowire('%letter.description_model%')] private string $model) {}
    public function __invoke(SymfonyStyle $io): int
    {
        $image = $this->images->find('nara-196468') ?? throw new \RuntimeException('Import the case first.');
        $this->files->cacheOriginal($image);
        $bytes = file_get_contents($image->localOriginalPath);
        $url = 'data:'.(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes).';base64,'.base64_encode($bytes);
        $dimensions = getimagesizefromstring($bytes);
        $capture = ['date' => (string) $image->legacy['catalog_metadata']['year'], 'date_precision' => 'year', 'provenance' => $image->legacy['citation_url']];
        // No capture location or orientation is established by this catalog record.
        foreach (['capture_context' => null, 'capture_and_caption' => $image->legacy['catalog_metadata']['title']] as $variant => $catalog) {
            $subject = new TaskSubject($image, ['catalog' => $catalog, 'capture' => $capture], $url);
            $prompts = $this->task->buildPrompts($subject);
            $execution = $this->cache->execute(['case'=>'photo-comparison-v2','source'=>hash('sha256',$bytes),'model'=>$this->model,'schema'=>SourceDescription::class,'prompts'=>$prompts], function () use ($subject) {
                $r = $this->task->run($subject);
                if (!$r->output instanceof SourceDescription || trim($r->output->description) === '') { throw new \UnexpectedValueException('Missing description.'); }
                return $r;
            });
            $r=$execution->value;
            $trace = [
                'provider' => 'openai', 'execution_location' => 'cloud', 'submission_mode' => 'sync',
                'agent' => 'ai.agent.description', 'model_requested' => $this->model,
                'cache_hit' => $execution->cacheHit, 'elapsed_ms' => $execution->elapsedMs,
                'original_task_ms' => $execution->originalDurationMs,
                'timing_scope' => 'task.run: prompt construction, provider round trip and structured output mapping; excludes source download and database writes',
                'capture_context' => $capture, 'catalog_caption' => $catalog,
                'image' => [
                    'source_url' => $image->sourceUrl, 'transport' => 'embedded data URI',
                    'rendition' => 'original NARA catalog GIF; no resizing or monochrome conversion by this app',
                    'mime_type' => $dimensions['mime'], 'width' => $dimensions[0], 'height' => $dimensions[1],
                    'byte_count' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
                    'vision_detail' => 'not explicitly set',
                    'data_uri_note' => 'Exact payload is data:<mime_type>;base64,<base64 of cached source bytes>; not duplicated in logs',
                ],
            ];
            $this->claims->record(null, $image->getWorkflowSubjectType(), $image->code, 'photo:'.$variant.'@1', $r->claims, new RunMeta(model:$r->meta?->model, prompt:$r->meta?->prompt, response:['output'=>$r->meta?->response, 'execution'=>$trace], inputTokens:$execution->cacheHit?0:$r->meta?->inputTokens, outputTokens:$execution->cacheHit?0:$r->meta?->outputTokens, durationMs:$execution->elapsedMs));
            $image->legacy['photo_comparison'][$variant]=['execution'=>$trace,'description'=>$r->output->description,'model'=>$r->meta?->model,'duration_ms'=>$execution->originalDurationMs,'input_tokens'=>$r->meta?->inputTokens,'output_tokens'=>$r->meta?->outputTokens,'cache_hit'=>$execution->cacheHit,'source_sha256'=>hash('sha256',$bytes)];
            $this->em->flush();
            $io->writeln($variant.': recorded');
        }
        return 0;
    }
}
