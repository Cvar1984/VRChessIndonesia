<?php

declare(strict_types=1);

namespace VRchessIndo\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Replicates the blanket CORS policy both legacy entry points set on every
 * response (index.php's jsonResponse()/OPTIONS handling and stockfish.php's
 * top-of-file headers): Access-Control-Allow-Origin: * plus an OPTIONS
 * preflight short-circuit. The two legacy files used slightly different
 * method/header allow-lists (stockfish.php's was narrower, no PUT/DELETE/
 * PATCH or admin headers, since it has no mutating or admin-only routes);
 * this uses the union of both across the whole unified app.
 *
 * Not part of any single phase's legacy source — noticed while porting
 * stockfish.php's own CORS headers that nothing built in Phases 0-4 had
 * replicated this at all yet, so it's applied globally here rather than
 * left as a stockfish-only gap.
 */
class CorsListener implements EventSubscriberInterface
{
    private const string ALLOW_METHODS = 'GET, POST, PUT, DELETE, PATCH, OPTIONS';
    private const string ALLOW_HEADERS = 'Content-Type, Authorization, X-API-Token, X-Admin-Username, X-Admin-Password';

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 250],
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || $event->getRequest()->getMethod() !== 'OPTIONS') {
            return;
        }

        $response = new Response('', 204);
        $this->applyHeaders($response);
        $event->setResponse($response);
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->applyHeaders($event->getResponse());
    }

    private function applyHeaders(Response $response): void
    {
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Methods', self::ALLOW_METHODS);
        $response->headers->set('Access-Control-Allow-Headers', self::ALLOW_HEADERS);
    }
}
