<?php

declare(strict_types=1);

namespace App\Menu;

use Survos\TablerBundle\Event\MenuEvent;
use Survos\TablerBundle\Service\MenuService;
use Survos\TablerBundle\Traits\KnpMenuHelperInterface;
use Survos\TablerBundle\Traits\KnpMenuHelperTrait;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class AppMenu implements KnpMenuHelperInterface
{
    use KnpMenuHelperTrait;

    public function __construct(
        #[Autowire('%kernel.environment%')]
        protected string $env,
        private MenuService $menuService,
        private ?AuthorizationCheckerInterface $authorizationChecker = null,
    ) {
    }

    #[AsEventListener(event: MenuEvent::NAVBAR_MENU)]
    public function navbarMenu(MenuEvent $event): void
    {
        $menu = $event->getMenu();
        $this->add($menu, 'app_gallery', icon: 'tabler:photo');
        $this->add($menu, 'survos_ai_workflow_tasks', icon: 'tabler:list-details');
    }
}
