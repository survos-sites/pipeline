<?php

declare(strict_types=1);
namespace App\Task;

use Survos\AiWorkflowBundle\Result\SourceDescription;
use Survos\AiWorkflowBundle\Task\AbstractPromptTask;
use Survos\AiWorkflowBundle\Task\AsTask;
use Survos\AiWorkflowBundle\Task\ImageTaskInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsTask('Compare visual evidence with optional catalog context.', self::class, produces: ['dcterms:description'])]
final class PhotoComparisonTask extends AbstractPromptTask implements ImageTaskInterface
{
    public function __construct(#[Autowire(service: 'ai.agent.description')] AgentInterface $agent) { parent::__construct($agent); }
    protected function responseFormatClass(): string { return SourceDescription::class; }
    protected function systemPromptTemplate(): string { return 'prompt/photo_comparison_system.html.twig'; }
    protected function userPromptTemplate(): ?string { return 'prompt/photo_comparison_user.html.twig'; }
    protected function promptContext(array $inputs, array $context = []): array { return ['catalog' => $context['catalog'] ?? null, 'capture' => $context['capture'] ?? null]; }
    protected function claimsFromData(array $data): array { return $this->claimMapper->map($data); }
}
