<?php

declare(strict_types=1);

namespace App\Menu;

use Survos\TablerBundle\Event\MenuEvent;
use Survos\TablerBundle\Service\MenuService;
use Survos\TablerBundle\Menu\MenuBuilderTrait;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class AppMenu
{
    use MenuBuilderTrait;

    public function __construct(
        #[Autowire('%kernel.environment%')]
        protected string $env,
        private MenuService $menuService,
        private ?AuthorizationCheckerInterface $authorizationChecker = null,
    ) {
    }

    #[AsEventListener(event: MenuEvent::NAVBAR_PRIMARY)]
    public function navbarMenu(MenuEvent $event): void
    {
        $menu = $event->getMenu();
        $this->add($menu, 'app_gallery', icon: 'tabler:photo', translationDomain: 'routing', label: 'app_gallery');
        $this->add($menu, 'survos_ai_workflow_tasks', icon: 'tabler:list-details', translationDomain: 'routing', label: 'survos_ai_workflow_tasks');
    }
}
