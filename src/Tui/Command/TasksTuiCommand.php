<?php

declare(strict_types=1);

namespace App\Tui\Command;

use App\Workflow\UrlSubject;
use Survos\AiWorkflowBundle\Task\AbstractCapabilityTask;
use Survos\AiWorkflowBundle\Task\AbstractPromptTask;
use Survos\AiWorkflowBundle\Task\AnalysisTaskInterface;
use Survos\AiWorkflowBundle\Task\ImageTaskInterface;
use Survos\AiWorkflowBundle\Task\TaskInterface;
use Survos\AiWorkflowBundle\Task\TaskRegistry;
use Survos\AiWorkflowBundle\Task\TextTaskInterface;
use Survos\ImgproxyBundle\Service\ImgproxyUrlBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Event\SelectionChangeEvent;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Style\Border;
use Symfony\Component\Tui\Style\Direction;
use Symfony\Component\Tui\Style\Padding;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\MarkdownWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * Browse AND test OUR curated task registry in a rich terminal UI — the
 * survos/ai-workflow tasks we control 100%, grouped by input modality.
 *
 * Browse with ↑/↓ (the detail pane tracks selection, showing the task's declared
 * consumes/produces claims). Press Enter to TEST the highlighted task: you get the
 * right input box for its shape (a URL for image/document tasks, a text box for
 * text/analysis tasks), a live preview of the resolved system + user prompt, and —
 * on Enter — the claims it produces. Image URLs are routed through imgproxy so the
 * model sees the honest low-resolution image. A dry run against an ad-hoc
 * {@see UrlSubject} — no entity, no persistence.
 *
 * @author Tac Tacelosky <tacman@gmail.com>
 */
#[AsCommand('app:tasks', 'Browse, preview prompts for, and test the curated AI workflow task registry (Symfony TUI).')]
final class TasksTuiCommand extends Command
{
    /** @var array<string, list<string>> claim predicate => task names that produce it */
    private array $producers = [];

    public function __construct(
        private readonly TaskRegistry $taskRegistry,
        private readonly ImgproxyUrlBuilder $imgproxy,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $rows = $this->rows();
        if ([] === $rows) {
            $io->error('No AI workflow tasks are registered (ai-workflow task registry is empty).');

            return Command::FAILURE;
        }

        $this->producers = $this->producerMap($rows);

        while (true) {
            $taskName = $this->selectTask($rows);
            if (null === $taskName) {
                return Command::SUCCESS;
            }

            $this->runTask($io, $rows[$taskName]);
        }
    }

    /**
     * Phase 1 — browse the registry; Enter returns the chosen task name, Esc quits.
     *
     * @param array<string, array<string, mixed>> $rows
     */
    private function selectTask(array $rows): ?string
    {
        $items = [];
        foreach ($rows as $value => $row) {
            $items[] = [
                'value' => $value,
                'label' => \sprintf('%s  %s', $this->modalityIcon($row['modality']), $row['name']),
                'description' => \sprintf('%s · %s', $row['modality'], $row['summary']),
            ];
        }

        $tui = new Tui($this->buildStyleSheet());
        $selected = null;

        $listPane = new ContainerWidget();
        $listPane->addStyleClass('list');
        $listPane->add(new TextWidget(\sprintf('%d curated tasks', \count($items))));
        $list = new SelectListWidget($items, min(\count($items), 22));
        $listPane->add($list);

        $detail = new MarkdownWidget($this->detailMarkdown($rows[$items[0]['value']]));
        $detailPane = new ContainerWidget();
        $detailPane->addStyleClass('detail');
        $detailPane->expandVertically(true);
        $detailPane->add($detail);

        $row = new ContainerWidget();
        $row->setStyle(new Style(direction: Direction::Horizontal, gap: 1));
        $row->expandVertically(true);
        $row->add($listPane)->add($detailPane);

        $hint = new TextWidget('↑/↓: browse   ·   Enter: test this task   ·   Esc: quit');
        $hint->addStyleClass('hint');

        $tui->add($row)->add($hint);
        $tui->setFocus($list);

        $list->onSelectionChange(function (SelectionChangeEvent $event) use ($tui, $detail, $rows): void {
            $detail->setText($this->detailMarkdown($rows[$event->getValue()]));
            $tui->requestRender();
            $tui->processRender();
        });
        $list->onSelect(static function (SelectEvent $event) use ($tui, &$selected): void {
            $selected = $event->getValue();
            $tui->stop();
        });
        $list->onCancel(static fn () => $tui->stop());

        $tui->run();

        return $selected;
    }

    /**
     * Phase 2 — type a URL or text and preview the prompt live; on Enter the TUI
     * stops and the (blocking) task runs in plain console mode, so the slow API call
     * never freezes the event loop. Loops so several inputs can be tested in a row.
     *
     * @param array<string, mixed> $row
     */
    private function runTask(SymfonyStyle $io, array $row): void
    {
        while (true) {
            $value = $this->promptForInput($row);
            if (null === $value) {
                return;
            }

            $this->runInConsole($io, $row, $value);
        }
    }

    /**
     * The live-preview input pane. Returns the submitted value, or null on Esc.
     *
     * @param array<string, mixed> $row
     */
    private function promptForInput(array $row): ?string
    {
        $kind = (string) $row['inputKind'];
        $task = $this->taskRegistry->get((string) $row['name']);
        $submitted = null;

        $tui = new Tui($this->buildStyleSheet());

        $title = new TextWidget(\sprintf('▶ Test "%s"   ·   %s input   ·   model %s', $row['name'], $kind, $row['model']));
        $title->addStyleClass('title');

        $pane = new MarkdownWidget($this->previewMarkdown($row, $task, $this->buildSubject($kind, (string) $row['name'], ''), ''));
        $paneBox = new ContainerWidget();
        $paneBox->addStyleClass('detail');
        $paneBox->expandVertically(true);
        $paneBox->add($pane);

        $prompt = new InputWidget();
        $prompt->setPrompt(\in_array($kind, ['text', 'analysis'], true) ? 'Text: ' : 'URL: ');

        $hint = new TextWidget('type: live prompt preview   ·   Enter: run (drops to console)   ·   Esc: back');
        $hint->addStyleClass('hint');

        $tui->add($title)->add($paneBox)->add($prompt)->add($hint);
        $tui->setFocus($prompt);

        $prompt->onChange(function () use ($tui, $pane, $row, $task, $kind, $prompt): void {
            $subject = $this->buildSubject($kind, (string) $row['name'], $prompt->getValue());
            $pane->setText($this->previewMarkdown($row, $task, $subject, $prompt->getValue()));
            $tui->requestRender();
            $tui->processRender();
        });
        $prompt->onSubmit(static function (SubmitEvent $event) use ($tui, &$submitted): void {
            $value = trim($event->getValue());
            if ('' === $value) {
                return;
            }
            $submitted = $value;
            $tui->stop();
        });
        $prompt->onCancel(static fn () => $tui->stop());

        $tui->run();

        return $submitted;
    }

    /**
     * Run the task as a blocking call in plain console mode. With the event loop gone,
     * the slow API call no longer freezes a live TUI and Ctrl-C works.
     *
     * @param array<string, mixed> $row
     */
    private function runInConsole(SymfonyStyle $io, array $row, string $value): void
    {
        $kind = (string) $row['inputKind'];
        $task = $this->taskRegistry->get((string) $row['name']);
        $subject = $this->buildSubject($kind, (string) $row['name'], $value);
        $sent = $subject->getWorkflowImageUrl() ?? $subject->getWorkflowText() ?? $value;

        $io->section(\sprintf('▶ %s', $row['name']));
        $io->writeln(\sprintf('📡 Sending to <info>%s</info>', $row['model']));
        $io->writeln(\sprintf('   <comment>%s</comment>', mb_strlen($sent) > 120 ? mb_substr($sent, 0, 119).'…' : $sent));
        $io->newLine();
        $io->write('   working… ');

        $markdown = $this->resultMarkdown($task, $subject);

        $io->writeln('<info>done</info>');
        $io->newLine();
        $io->writeln($markdown);
        $io->newLine();
        $io->ask('Press <return> to go back to the task list', '');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function previewMarkdown(array $row, ?TaskInterface $task, UrlSubject $subject, string $value): string
    {
        $md = \sprintf("## ▶ %s\n\n", $row['name']);

        /** @var list<string> $consumes */
        $consumes = $row['consumes'];
        /** @var list<string> $produces */
        $produces = $row['produces'];
        if ([] !== $consumes) {
            $parts = [];
            foreach ($consumes as $pred) {
                $from = $this->producers[$pred] ?? [];
                $parts[] = [] !== $from ? \sprintf('`%s` (from: %s)', $pred, implode(', ', $from)) : \sprintf('`%s` _(no producer)_', $pred);
            }
            $md .= '**Consumes:** '.implode('   ', $parts)."\n";
        }
        if ([] !== $produces) {
            $md .= '**Produces:** '.implode(', ', array_map(static fn (string $p): string => "`{$p}`", $produces))."\n";
        }
        if ([] !== $consumes || [] !== $produces) {
            $md .= "\n";
        }

        if ('image' === $row['inputKind'] && '' !== $value) {
            $md .= \sprintf("**Image sent (imgproxy):** `%s`\n\n", $subject->getWorkflowImageUrl() ?? '—');
        }

        if ($task instanceof AbstractPromptTask) {
            try {
                [$system, $user] = $task->buildPrompts($subject);
                $md .= $this->fence('System prompt', $system, 900);
                $md .= $this->fence('User prompt', $user, 700);
            } catch (\Throwable $e) {
                $md .= \sprintf("_Prompt preview unavailable: %s_\n", $e->getMessage());
            }

            return $md;
        }

        $md .= \sprintf("_Transducer — no prompt. Runs `\$platform->invoke('%s', …)` directly._\n", $row['model']);

        return $md;
    }

    private function resultMarkdown(?TaskInterface $task, UrlSubject $subject): string
    {
        if (null === $task) {
            return '❌ Task is no longer registered.';
        }
        if (!$task->supports($subject)) {
            return "⚠️ Task does not support this input — its shape needs more than what you supplied.";
        }

        try {
            $result = $task->run($subject);
        } catch (\Throwable $e) {
            return \sprintf("❌ **Run failed:**\n\n```\n%s\n```", $e->getMessage());
        }

        $meta = $result->meta;
        $tokens = '';
        if (null !== $meta?->inputTokens || null !== $meta?->outputTokens) {
            $tokens = \sprintf('  ·  %d in / %d out tokens', $meta->inputTokens ?? 0, $meta->outputTokens ?? 0);
        }
        $md = \sprintf(
            "### ▶ Result — %d claims%s%s\n\n",
            \count($result->claims),
            null !== $meta?->durationMs ? \sprintf('  ·  %d ms', $meta->durationMs) : '',
            $tokens,
        );
        if ([] === $result->claims) {
            return $md."_No claims produced._\n";
        }

        // Group claims by predicate so rich OCR output is shown as layout + raw,
        // not a flat dump of repeated lines.
        $byPredicate = [];
        foreach ($result->claims as $claim) {
            $byPredicate[$claim->predicate][] = $claim;
        }

        // 1. Raw text — readable, not truncated to a line.
        foreach (['ai:ocrText', 'ai:transcription'] as $textPredicate) {
            if (isset($byPredicate[$textPredicate])) {
                $md .= $this->fence('Raw text', (string) $byPredicate[$textPredicate][0]->value, 1400);
                unset($byPredicate[$textPredicate]);
            }
        }

        // 2. Layout — deduped and grouped by type (so 12 identical paragraphs collapse).
        if (isset($byPredicate['ai:layoutBlock'])) {
            $md .= $this->layoutSummary($byPredicate['ai:layoutBlock']);
            unset($byPredicate['ai:layoutBlock']);
        }

        foreach (['ai:ocrPage' => 'Pages', 'ai:imageBlock' => 'Embedded images', 'ai:table' => 'Tables'] as $predicate => $label) {
            if (isset($byPredicate[$predicate])) {
                $md .= \sprintf("**%s:** %d\n\n", $label, \count($byPredicate[$predicate]));
                unset($byPredicate[$predicate]);
            }
        }

        // 3. Remaining scalar claims — deduped.
        foreach ($byPredicate as $predicate => $claims) {
            $seen = [];
            foreach ($claims as $claim) {
                $value = \is_array($claim->value)
                    ? (string) json_encode($claim->value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)
                    : (string) $claim->value;
                $value = preg_replace('/\s+/', ' ', trim($value)) ?? $value;
                if (isset($seen[$value])) {
                    continue;
                }
                $seen[$value] = true;
                if (mb_strlen($value) > 140) {
                    $value = mb_substr($value, 0, 139).'…';
                }
                $md .= \sprintf("- `%s` — %s  _(%d%%)_\n", $predicate, $value, $claim->confidence);
            }
        }

        return $md;
    }

    /**
     * Render layout blocks as a deduped skeleton: structural headings in full,
     * everything else counted by type — instead of a flat list of repeats.
     *
     * @param list<object{predicate: string, value: mixed, confidence: int}> $claims
     */
    private function layoutSummary(array $claims): string
    {
        $seen = [];
        $blocks = [];
        foreach ($claims as $claim) {
            $block = \is_array($claim->value) ? $claim->value : json_decode((string) $claim->value, true);
            if (!\is_array($block)) {
                continue;
            }
            $key = json_encode($block);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $blocks[] = $block;
        }

        $byType = [];
        foreach ($blocks as $block) {
            $byType[(string) ($block['type'] ?? 'other')][] = (string) ($block['text'] ?? '');
        }

        $collapsed = \count($claims) - \count($blocks);
        $md = \sprintf("**Layout — %d blocks%s**\n\n", \count($blocks), $collapsed > 0 ? \sprintf(' (%d duplicate collapsed)', $collapsed) : '');

        foreach (['headline', 'subheadline', 'byline'] as $type) {
            foreach ($byType[$type] ?? [] as $text) {
                $md .= \sprintf("- **%s:** %s\n", $type, $this->oneLine($text));
            }
            unset($byType[$type]);
        }
        foreach ($byType as $type => $texts) {
            $md .= \sprintf("- %d × %s\n", \count($texts), $type);
        }

        return $md."\n";
    }

    private function buildSubject(string $kind, string $name, string $value): UrlSubject
    {
        $value = trim($value);

        if (\in_array($kind, ['text', 'analysis'], true)) {
            // Pasted text stands in for the consumed prior-claim (observation prose),
            // so a task that consumes it can be tested without a stored subject — and,
            // because the key is always set, prompt preview never touches the database.
            $context = ['observation_prose' => $value, 'text' => $value];

            return new UrlSubject(text: '' !== $value ? $value : null, context: $context);
        }

        if ('image' === $kind) {
            return new UrlSubject(url: '' !== $value ? $this->resolveImageUrl($name, $kind, $value) : null);
        }

        return new UrlSubject(url: '' !== $value ? $value : null);
    }

    /**
     * Pick the image resolution a task actually needs:
     *  - OCR/document tasks read dense text and are billed per page, not per pixel —
     *    send the FULL image (matches production OcrMistralTask::getWorkflowImageUrl()).
     *    A thumbnail here only destroys accuracy for zero cost saving.
     *  - *_hires tasks → imgproxy high-res variant.
     *  - normal reasoning vision (observe, …) → ~512px AI thumbnail (cheap, honest low-res).
     */
    private function resolveImageUrl(string $name, string $kind, string $url): string
    {
        if ('document' === $kind || str_contains($name, 'ocr')) {
            return $url;
        }

        try {
            return str_contains($name, 'hires') ? $this->imgproxy->aiHires($url) : $this->imgproxy->aiThumbnail($url);
        } catch (\Throwable) {
            return $url;
        }
    }

    /**
     * @return array<string, array{name: string, summary: string, modality: string, kind: string, model: string, platform: string, class: string, inputKind: string, consumes: list<string>, produces: list<string>}>
     */
    private function rows(): array
    {
        $rows = [];
        foreach (array_keys($this->taskRegistry->getTaskMap()) as $name) {
            $task = $this->taskRegistry->get($name);
            if (null === $task) {
                continue;
            }

            $descriptor = $this->taskRegistry->getDescriptor($name);
            $meta = $task->getMeta();
            $modality = $this->modality($task);

            $rows[$name] = [
                'name' => $name,
                'summary' => $this->oneLine($descriptor?->description ?? ''),
                'modality' => $modality,
                'kind' => $this->kind($task),
                'model' => (string) ($meta['model'] ?? $meta['agent'] ?? '—'),
                'platform' => (string) ($meta['platform'] ?? ('' !== ($meta['agent'] ?? '') ? 'agent' : '—')),
                'class' => $task::class,
                'inputKind' => $this->inputKindOf($task, $modality),
                'consumes' => $descriptor?->consumes ?? [],
                'produces' => $descriptor?->produces ?? [],
            ];
        }

        uasort($rows, static function (array $a, array $b): int {
            return [$a['modality'], $a['name']] <=> [$b['modality'], $b['name']];
        });

        return $rows;
    }

    /**
     * @param array<string, array<string, mixed>> $rows
     *
     * @return array<string, list<string>>
     */
    private function producerMap(array $rows): array
    {
        $map = [];
        foreach ($rows as $name => $row) {
            foreach ($row['produces'] as $predicate) {
                $map[$predicate][] = (string) $name;
            }
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function detailMarkdown(array $row): string
    {
        $md = \sprintf("## %s\n\n", $row['name']);
        $md .= \sprintf("%s\n\n", '' !== $row['summary'] ? $row['summary'] : '_No description._');
        $md .= \sprintf("- **Input shape:** %s\n", $row['inputKind']);
        $md .= \sprintf("- **Kind:** %s\n", $row['kind']);
        $md .= \sprintf("- **Model:** `%s`  ·  **Platform:** `%s`\n", $row['model'], $row['platform']);

        /** @var list<string> $consumes */
        $consumes = $row['consumes'];
        /** @var list<string> $produces */
        $produces = $row['produces'];
        if ([] !== $consumes) {
            $parts = [];
            foreach ($consumes as $pred) {
                $from = $this->producers[$pred] ?? [];
                $parts[] = [] !== $from ? \sprintf('`%s` (from %s)', $pred, implode(', ', $from)) : "`{$pred}`";
            }
            $md .= '- **Consumes:** '.implode(', ', $parts)."\n";
        }
        if ([] !== $produces) {
            $md .= '- **Produces:** '.implode(', ', array_map(static fn (string $p): string => "`{$p}`", $produces))."\n";
        }

        $md .= \sprintf("- **Class:** `%s`\n", $row['class']);
        $md .= "\n**Goal:** structured output → claims.   _Enter to test._\n";

        return $md;
    }

    private function inputKindOf(TaskInterface $task, string $modality): string
    {
        if ($task instanceof AbstractPromptTask) {
            return $task->inputKind();
        }
        if ($task instanceof AbstractCapabilityTask) {
            return $task->inputKind();
        }

        return 'image' === $modality ? 'image' : 'text';
    }

    private function modality(TaskInterface $task): string
    {
        if ($task instanceof ImageTaskInterface) {
            return 'image';
        }
        if ($task instanceof TextTaskInterface) {
            return 'text';
        }
        if ($task instanceof AnalysisTaskInterface) {
            return 'analysis';
        }

        return 'other';
    }

    private function kind(TaskInterface $task): string
    {
        if ($task instanceof AbstractCapabilityTask) {
            return 'capability — direct `$platform->invoke()` (no agent)';
        }
        if ($task instanceof AbstractPromptTask) {
            return 'agent — prompt + `$agent->call()`';
        }

        return 'custom';
    }

    private function modalityIcon(string $modality): string
    {
        return match ($modality) {
            'image' => '🖼',
            'text' => '🆎',
            'analysis' => '🧮',
            default => '•',
        };
    }

    private function fence(string $label, string $text, int $max): string
    {
        $text = trim($text);
        if ('' === $text) {
            $text = '(empty)';
        } elseif (mb_strlen($text) > $max) {
            $text = mb_substr($text, 0, $max)."\n… (".(mb_strlen($text) - $max).' more chars)';
        }

        return \sprintf("**%s:**\n\n```\n%s\n```\n\n", $label, $text);
    }

    private function oneLine(string $text): string
    {
        $text = preg_replace('/\s+/', ' ', trim($text)) ?? $text;

        return mb_strlen($text) > 64 ? mb_substr($text, 0, 63).'…' : $text;
    }

    private function buildStyleSheet(): StyleSheet
    {
        $styles = new StyleSheet();
        $styles->addRule('.list', new Style(padding: Padding::all(1), border: Border::all(1, 'rounded', 'cyan')));
        $styles->addRule('.detail', new Style(padding: Padding::all(1), border: Border::all(1, 'rounded', 'gray')));
        $styles->addRule('.title', new Style(color: 'cyan'));
        $styles->addRule('.hint', new Style(color: 'gray', dim: true));

        return $styles;
    }
}
