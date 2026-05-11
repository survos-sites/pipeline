<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GalleryImageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\AiWorkflowBundle\Contract\ContextSubjectInterface;
use Survos\AiWorkflowBundle\Contract\ImageSubjectInterface;
use Survos\AiWorkflowBundle\Contract\WorkflowSubjectInterface;
use Survos\AiWorkflowBundle\Workflow\SubjectFlow;
use Survos\FieldBundle\Attribute\EntityMeta;

#[ORM\Entity(repositoryClass: GalleryImageRepository::class)]
#[EntityMeta(
    icon: 'tabler:photo',
    group: 'AI Workflow',
    label: 'Gallery Images',
    description: 'Plain image records imported from the gallery manifest.',
)]
class GalleryImage implements WorkflowSubjectInterface, ImageSubjectInterface, ContextSubjectInterface
{
    #[ORM\Id]
    #[ORM\Column(length: 16)]
    public string $code;

    #[ORM\Column(length: 255)]
    public string $title;

    #[ORM\Column(type: Types::TEXT)]
    public string $sourceUrl;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $collection = null;

    #[ORM\Column(length: 32)]
    public string $marking = SubjectFlow::PLACE_NEW;

    /**
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    public array $workflowQueue = [];

    #[ORM\Column]
    public bool $workflowLocked = false;

    /**
     * Temporary provenance while replacing the old manifest.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    public array $legacy = [];

    public static function fromManifestRow(array $row): self
    {
        $image = new self();
        $image->code = (string) ($row['code'] ?? substr(sha1((string) ($row['url'] ?? '')), 0, 8));
        $image->updateFromManifestRow($row);

        return $image;
    }

    public function updateFromManifestRow(array $row): void
    {
        $this->title = (string) ($row['title'] ?? $row['url'] ?? $this->code);
        $this->sourceUrl = (string) ($row['url'] ?? '');
        $this->collection = isset($row['collection']) ? (string) $row['collection'] : null;
        $this->legacy = $row;
    }

    public function getWorkflowSubjectId(): string
    {
        return $this->code;
    }

    public function getWorkflowScope(): ?string
    {
        return null;
    }

    public function getWorkflowQueue(): array
    {
        return $this->workflowQueue;
    }

    public function setWorkflowQueue(array $queue): void
    {
        $this->workflowQueue = array_values($queue);
    }

    public function isWorkflowLocked(): bool
    {
        return $this->workflowLocked;
    }

    public function setWorkflowLocked(bool $locked): void
    {
        $this->workflowLocked = $locked;
    }

    public function getWorkflowImageUrl(): ?string
    {
        return $this->sourceUrl ?: null;
    }

    public function getWorkflowContext(): array
    {
        return [
            'title' => $this->title,
            'collection' => $this->collection,
            'legacy' => $this->legacy,
        ];
    }

    public function getDisplayUrl(): string
    {
        if (str_starts_with($this->sourceUrl, 'http://') || str_starts_with($this->sourceUrl, 'https://')) {
            return $this->sourceUrl;
        }

        return '/' . ltrim($this->sourceUrl, '/');
    }
}
