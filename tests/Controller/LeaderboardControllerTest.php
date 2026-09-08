<?php

declare(strict_types=1);

namespace VRchessIndo\Tests\Controller;

use VRchessIndo\Tests\ApiTestCase;

/**
 * A thin regression guard for the Twig template itself (valid Twig syntax,
 * asset() calls resolve, key markup present) — not a substitute for the
 * manual browser verification this phase was actually confirmed with
 * (leaderboard data, player profiles, match history, saved analyses, and a
 * live Stockfish analysis via the SSE endpoint all checked against real
 * production data with zero mutations). A vanilla-JS SPA's client-side
 * behavior isn't meaningfully covered by a PHPUnit-level test either way.
 *
 * Extends ApiTestCase (not plain WebTestCase) so the ?analysis= tests below
 * can persist a real Analysis document to look up — the homepage's own tests
 * don't need the database, but inherit its setUp() harmlessly.
 */
class LeaderboardControllerTest extends ApiTestCase
{
    private const string SAMPLE_PGN = '[Event "Test"]
[White "Alice"]
[Black "Bob"]
[Result "1-0"]

1. e4 e5 2. Nf3 Nc6 3. Bb5 *';

    public function testHomepageRendersSuccessfully(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/html', $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testHomepageContainsAppShellAndAssetReferences(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertSame('VRChess Indonesia — Peringkat Catur & Admin Panel', $crawler->filter('title')->text());
        self::assertGreaterThan(0, $crawler->filter('#tab-leaderboard')->count());
        self::assertGreaterThan(0, $crawler->filter('#leaderboardContainer')->count());

        $html = $this->client->getResponse()->getContent();
        // asset() must have resolved to real, non-Twig-syntax URLs.
        self::assertStringNotContainsString('{{', $html);
        self::assertMatchesRegularExpression('#/assets/app(-[a-f0-9]+)?\.js#', $html);
        self::assertMatchesRegularExpression('#/assets/css/style(-[a-f0-9]+)?\.css#', $html);
    }

    public function testCorsHeadersPresent(): void
    {
        $this->client->request('GET', '/');

        self::assertSame('*', $this->client->getResponse()->headers->get('Access-Control-Allow-Origin'));
    }

    public function testHomepageMetaIsSelfReferencingAndGeneric(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertSame(
            'http://localhost/',
            $crawler->filter('link[rel="canonical"]')->attr('href'),
        );
        self::assertSame(
            'VRChess Indonesia — Peringkat Catur & API Token Management',
            $crawler->filter('meta[property="og:title"]')->attr('content'),
        );
    }

    public function testAnalysisQueryParamRendersGameSpecificMeta(): void
    {
        $this->jsonRequest('POST', '/api/analyses', ['pgn' => self::SAMPLE_PGN]);
        self::assertResponseIsSuccessful();
        $id = $this->jsonBody()['id'];

        $crawler = $this->client->request('GET', "/?analysis={$id}");

        self::assertResponseIsSuccessful();
        self::assertSame('Alice vs Bob — VRChess Analysis', $crawler->filter('title')->text());
        self::assertSame(
            "http://localhost/?analysis={$id}",
            $crawler->filter('link[rel="canonical"]')->attr('href'),
        );
        self::assertSame(
            'Analisis Stockfish untuk pertandingan Alice vs Bob (1-0) di VRChess Indonesia.',
            $crawler->filter('meta[property="og:description"]')->attr('content'),
        );
    }

    public function testUnknownAnalysisIdFallsBackToGenericMeta(): void
    {
        $crawler = $this->client->request('GET', '/?analysis=doesnotexist12345');

        self::assertResponseIsSuccessful();
        self::assertSame('VRChess Indonesia — Peringkat Catur & Admin Panel', $crawler->filter('title')->text());
        self::assertSame(
            'http://localhost/',
            $crawler->filter('link[rel="canonical"]')->attr('href'),
            'An unresolvable analysis id must not leak the raw query string into the canonical.',
        );
    }

    public function testEmptyAnalysisQueryParamIsTreatedAsAbsent(): void
    {
        $crawler = $this->client->request('GET', '/?analysis=');

        self::assertResponseIsSuccessful();
        self::assertSame('http://localhost/', $crawler->filter('link[rel="canonical"]')->attr('href'));
    }
}
