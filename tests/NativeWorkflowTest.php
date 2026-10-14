<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\GalleryImage;
use App\Workflow\UrlSubject;
use Survos\AiWorkflowBundle\Workflow\SubjectFlow;
use Survos\MediaBundle\Entity\Photo;
use Survos\WorkflowExtrasBundle\Contract\TransitionExecutorInterface;
use Survos\WorkflowMessengerBundle\Message\TransitionRequest;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Workflow\Registry;

final class NativeWorkflowTest extends WebTestCase
{
    public function testWorkflowDiscoveryExcludesMediaAndAdHocTasks(): void
    {
        self::bootKernel();
        $registry = self::getContainer()->get(Registry::class);
        $image = new GalleryImage();
        $image->code = 'test';
        $workflow = $registry->get($image, SubjectFlow::WORKFLOW_NAME);
        self::assertSame(['new' => 1], $workflow->getMarking($image)->getPlaces());
        self::assertTrue($workflow->can($image, SubjectFlow::TRANSITION_PREPARE));
        self::assertSame([], $registry->all(new UrlSubject(url: 'https://example.com/test.jpg')));
        self::assertSame([], $registry->all(new Photo('https://example.com/test.jpg')));
        self::getContainer()->get(TransitionExecutorInterface::class)->execute($workflow, $image, SubjectFlow::TRANSITION_PREPARE);
        $sent = self::getContainer()->get('messenger.transport.ai_subject.prepare')->getSent();
        self::assertCount(1, $sent);
        self::assertInstanceOf(TransitionRequest::class, $sent[0]->getMessage());
        self::assertSame('new', $image->getMarking(), 'Dispatch must leave application work to the worker.');
        foreach (['prepare', 'observe', 'analyze', 'review', 'publish'] as $phase) {
            self::assertNotNull(self::getContainer()->get('messenger.transport.ai_subject.'.$phase));
        }
    }

    public function testWorkflowExplorerRendersWithMenus(): void
    {
        $client = self::createClient();
        $client->request('GET', '/workflow/ai_subject');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'ai_subject');
    }
}
