<?php

declare(strict_types=1);

namespace App\Task;

use Survos\AiWorkflowBundle\Task\AbstractPromptTask;
use Survos\AiWorkflowBundle\Task\AsTask;
use Survos\AiWorkflowBundle\Task\ImageTaskInterface;
use Survos\ClaimsBundle\Service\RawClaim;
use Survos\DataContracts\Workflow\ImageSubjectInterface;
use Survos\DataContracts\Workflow\WorkflowSubjectInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsTask('Estimate collector value from an image and supplied context.', self::class, produces: ['ai:valueEstimate'])]
final class EstimateValueTask extends AbstractPromptTask implements ImageTaskInterface
{
    public const string TASK = 'estimate_value';

    public function __construct(#[Autowire(service: 'ai.agent.metadata')] ?AgentInterface $agent = null)
    {
        parent::__construct($agent);
    }

    public function supports(WorkflowSubjectInterface $subject): bool
    {
        return $subject instanceof ImageSubjectInterface && null !== $subject->getWorkflowImageUrl();
    }

    protected function systemPromptTemplate(): string
    {
        return 'ai/prompt/estimate_value/system.html.twig';
    }

    protected function userPromptTemplate(): ?string
    {
        return 'ai/prompt/estimate_value/user.html.twig';
    }

    protected function claimsFromData(array $data): array
    {
        if (!is_numeric($data['estimate_low'] ?? null) || !is_numeric($data['estimate_high'] ?? null)
            || (float) $data['estimate_low'] < 0 || (float) $data['estimate_high'] < (float) $data['estimate_low']
            || ($data['currency'] ?? null) !== 'USD'
            || !is_string($data['rationale'] ?? null) || trim($data['rationale']) === '') {
            throw new \UnexpectedValueException('Value estimate must contain an ordered nonnegative USD range and a rationale.');
        }

        return [new RawClaim('ai:valueEstimate', $data, basis: $data['rationale'])];
    }
}
