<?php

declare(strict_types=1);

namespace App\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** The bundle registers its own namespace path before Symfony's normal overrides. */
final class TaskPromptPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $container->getDefinition('twig.loader.native_filesystem')->addMethodCall('prependPath', [
            '%kernel.project_dir%/templates/bundles/SurvosAiWorkflowBundle',
            'SurvosAiWorkflow',
        ]);
    }
}
