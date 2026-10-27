<?php

declare(strict_types=1);
namespace App\Tests\Letter;

use App\Entity\GalleryImage;
use App\Letter\{LetterSubject, LetterWorkflow};
use Survos\AiWorkflowBundle\Decision\{DocumentAssessment, AssessmentEvidence};
use Survos\AiWorkflowBundle\Task\TaskRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LetterWorkflowTest extends KernelTestCase
{
    public function testGuardsUseSharedAssessmentAndKnownEvidenceSkipsDescription(): void
    {
        self::bootKernel();
        $workflow = self::getContainer()->get(LetterWorkflow::class);
        $image = GalleryImage::fromManifestRow(['url' => 'https://example.org/letter.jpg', 'title' => 'Letter']);
        $subject = new LetterSubject($image);
        self::assertTrue($workflow->can($subject, 'describe'));
        $subject->marking = 'assessed';
        foreach ([new DocumentAssessment(null, null, null, 'Unknown'), new DocumentAssessment(false, true, true, 'Typed letter'), new DocumentAssessment(true, false, false, 'Handwritten ledger')] as $assessment) {
            $subject->assessment = $assessment;
            self::assertFalse($workflow->can($subject, 'transcribe'));
            self::assertTrue($workflow->can($subject, 'request_review'));
        }
        $subject->assessment = new DocumentAssessment(true, false, true, 'Handwritten letter');
        self::assertTrue($workflow->can($subject, 'transcribe'));
        $subject->save();
        self::assertEquals($subject->assessment, (new LetterSubject($image))->assessment);
        unset($image->legacy['letter_workflow']);
        foreach (['human', 'source', 'tool'] as $origin) {
            $known = new LetterSubject($image, new AssessmentEvidence($subject->assessment, $origin, 'rule/review-123'));
            self::assertFalse($workflow->can($known, 'describe'));
            self::assertTrue($workflow->can($known, 'assess'));
        }
    }

    public function testRealPromptsAndDtoRegistration(): void
    {
        self::bootKernel();
        $tasks = self::getContainer()->get(TaskRegistry::class);
        self::assertTrue($tasks->has('assess_document'));
        $image = GalleryImage::fromManifestRow(['url' => 'https://example.org/letter', 'title' => 'Letter']);
        $input = new \App\Workflow\TaskSubject($image, ['input_text_kind' => 'transcription', 'observation_prose' => 'Handwritten letter'], text: 'Exact <letter> & evidence');
        [$system] = $tasks->get('extract_metadata')->buildPrompts($input);
        self::assertStringContainsString('Exact <letter> & evidence', $system);
        [, $user] = $tasks->get('assess_document')->buildPrompts($input);
        self::assertStringContainsString('Exact <letter> & evidence', $user);
    }
    public function testManifestRefreshCannotForgeOrDiscardExecutionState(): void
    {
        $image = GalleryImage::fromManifestRow(['url' => 'https://example.org/letter', 'letter_workflow' => ['marking' => 'complete']]);
        self::assertArrayNotHasKey('letter_workflow', $image->legacy);
        $image->legacy['letter_workflow'] = ['marking' => 'assessed'];
        $image->updateFromManifestRow(['url' => 'https://example.org/letter', 'title' => 'Updated title', 'letter_workflow' => ['marking' => 'complete']]);
        self::assertSame('assessed', $image->legacy['letter_workflow']['marking']);
    }

}
