<?php

declare(strict_types=1);

namespace VRchessIndo\Tests\EventListener;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * onKernelResponse() (the Access-Control-Allow-* headers on every response)
 * is already incidentally exercised by every functional test that hits the
 * app. This targets the one branch nothing else does: the OPTIONS preflight
 * short-circuit in onKernelRequest(), which never reaches a controller at
 * all — a silent gap here would only ever surface as a mysterious CORS
 * failure in a real browser, not as a failing assertion.
 */
class CorsListenerTest extends WebTestCase
{
    public function testOptionsPreflightShortCircuitsWithNoContent(): void
    {
        $client = self::createClient();
        $client->request('OPTIONS', '/api/players');

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $client->getResponse()->getContent());
        self::assertSame('*', $client->getResponse()->headers->get('Access-Control-Allow-Origin'));
        self::assertStringContainsString('DELETE', $client->getResponse()->headers->get('Access-Control-Allow-Methods'));
        self::assertStringContainsString('X-Admin-Password', $client->getResponse()->headers->get('Access-Control-Allow-Headers'));
    }

    public function testOptionsPreflightWorksOnAnyPathNotJustRegisteredRoutes(): void
    {
        // The preflight short-circuit runs at priority 250 on kernel.request
        // — before routing resolves the path at all — so it must respond
        // 204 even for a path with no matching route.
        $client = self::createClient();
        $client->request('OPTIONS', '/this/route/does/not/exist');

        self::assertResponseStatusCodeSame(204);
        self::assertSame('*', $client->getResponse()->headers->get('Access-Control-Allow-Origin'));
    }

    public function testOrdinaryGetGetsCorsHeadersButIsNotShortCircuited(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/rankings');

        self::assertNotSame(204, $client->getResponse()->getStatusCode());
        self::assertSame('*', $client->getResponse()->headers->get('Access-Control-Allow-Origin'));
    }
}
