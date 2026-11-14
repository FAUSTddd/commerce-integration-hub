<?php

declare(strict_types=1);

namespace App\Admin\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class AdminCacheControlSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', 0],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();

        // Target exclusively ^/admin routes
        if (!str_starts_with($path, '/admin')) {
            return;
        }

        $response = $event->getResponse();
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
    }
}
