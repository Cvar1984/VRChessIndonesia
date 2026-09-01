<?php

declare(strict_types=1);

namespace VRchessIndo\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A thin regression guard for the Twig template itself (valid Twig syntax,
 * asset() calls resolve, key markup present) — not a substitute for the
 * manual browser verification this phase was actually confirmed with
 * (leaderboard data, player profiles, match history, saved analyses, and a
 * live Stockfish analysis via the SSE endpoint all checked against real
 * production data with zero mutations). A vanilla-JS SPA's client-side
 * behavior isn't meaningfully covered by a PHPUnit-level test either way.
 */
class LeaderboardControllerTest extends WebTestCase
{
    public function testHomepageRendersSuccessfully(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/html', $client->getResponse()->headers->get('Content-Type'));
    }

    public function testHomepageContainsAppShellAndAssetReferences(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        self::assertSame('VRChess Indonesia — Peringkat Catur & Admin Panel', $crawler->filter('title')->text());
        self::assertGreaterThan(0, $crawler->filter('#tab-leaderboard')->count());
        self::assertGreaterThan(0, $crawler->filter('#leaderboardContainer')->count());

        $html = $client->getResponse()->getContent();
        // asset() must have resolved to real, non-Twig-syntax URLs.
        self::assertStringNotContainsString('{{', $html);
        self::assertMatchesRegularExpression('#/assets/app(-[a-f0-9]+)?\.js#', $html);
        self::assertMatchesRegularExpression('#/assets/css/style(-[a-f0-9]+)?\.css#', $html);
    }

    public function testCorsHeadersPresent(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertSame('*', $client->getResponse()->headers->get('Access-Control-Allow-Origin'));
    }
}
