<?php

declare(strict_types=1);

namespace VRchessIndo\Tests\Controller;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpClient\Response\MockResponse;
use VRchessIndo\Document\Player;
use VRchessIndo\Tests\ApiTestCase;

class AvatarProxyControllerTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The avatar cache is filesystem-backed (cache.avatars), not part of
        // vrchessindo_test — clear it too so a previous run's cached bytes
        // under the same test avatar URLs can't leak into this one.
        self::getContainer()->get('cache.avatars')->clear();
    }

    public function testNoCachedAvatarReturns404(): void
    {
        $this->loginAsAdmin();
        $this->jsonRequest('POST', '/api/matches', ['white' => 'Alice', 'black' => 'Bob', 'result' => '1']);

        $anon = $this->freshClient();
        $anon->request('GET', '/api/avatar/Alice');
        self::assertSame(404, $anon->getResponse()->getStatusCode());
    }

    public function testNonexistentPlayerReturns404(): void
    {
        $anon = $this->freshClient();
        $anon->request('GET', '/api/avatar/NoSuchPlayer');
        self::assertSame(404, $anon->getResponse()->getStatusCode());
    }

    public function testFetchesAndServesAvatarThenCachesIt(): void
    {
        $player = new Player(1, 'Alice');
        $player->setVrchatLink('usr_alice', 'Alice VR', 'https://cdn.example.com/alice.png');
        $this->dm->persist($player);
        $this->dm->flush();
        $this->dm->clear();

        $this->mockHttpClient([
            new MockResponse('fake-png-bytes', ['http_code' => 200, 'response_headers' => ['Content-Type: image/png']]),
        ]);

        $anon = $this->freshClient();
        $anon->request('GET', '/api/avatar/Alice');

        self::assertSame(200, $anon->getResponse()->getStatusCode());
        self::assertSame('fake-png-bytes', $anon->getResponse()->getContent());
        self::assertStringContainsString('image/png', $anon->getResponse()->headers->get('Content-Type'));
    }

    public function testFreshCacheEntryIsServedWithoutRefetching(): void
    {
        $player = new Player(1, 'Alice');
        $player->setVrchatLink('usr_alice', 'Alice VR', 'https://cdn.example.com/alice.png');
        $this->dm->persist($player);
        $this->dm->flush();
        $this->dm->clear();

        /** @var CacheItemPoolInterface $avatarsCache */
        $avatarsCache = self::getContainer()->get('cache.avatars');
        $item = $avatarsCache->getItem('avatar_' . md5('https://cdn.example.com/alice.png'));
        $item->set(['content_type' => 'image/webp', 'body' => 'already-cached-bytes', 'cached_at' => time()]);
        $avatarsCache->save($item);

        // Zero responses queued: any attempted HTTP call would throw.
        $this->mockHttpClient([]);

        $anon = $this->freshClient();
        $anon->request('GET', '/api/avatar/Alice');

        self::assertSame(200, $anon->getResponse()->getStatusCode());
        self::assertSame('already-cached-bytes', $anon->getResponse()->getContent());
    }

    public function testStaleCacheIsServedWhenRefetchFails(): void
    {
        $player = new Player(1, 'Alice');
        $player->setVrchatLink('usr_alice', 'Alice VR', 'https://cdn.example.com/alice.png');
        $this->dm->persist($player);
        $this->dm->flush();
        $this->dm->clear();

        /** @var CacheItemPoolInterface $avatarsCache */
        $avatarsCache = self::getContainer()->get('cache.avatars');
        $item = $avatarsCache->getItem('avatar_' . md5('https://cdn.example.com/alice.png'));
        $item->set(['content_type' => 'image/png', 'body' => 'stale-but-still-good', 'cached_at' => time() - (48 * 60 * 60)]);
        $avatarsCache->save($item);

        $this->mockHttpClient([new MockResponse('', ['http_code' => 500])]);

        $anon = $this->freshClient();
        $anon->request('GET', '/api/avatar/Alice');

        self::assertSame(200, $anon->getResponse()->getStatusCode());
        self::assertSame('stale-but-still-good', $anon->getResponse()->getContent(), 'Falls back to the stale copy rather than 502ing');
    }

    public function testNoCacheAndFailedFetchReturns502(): void
    {
        $player = new Player(1, 'Alice');
        $player->setVrchatLink('usr_alice', 'Alice VR', 'https://cdn.example.com/never-cached.png');
        $this->dm->persist($player);
        $this->dm->flush();
        $this->dm->clear();

        $this->mockHttpClient([new MockResponse('', ['http_code' => 500])]);

        $anon = $this->freshClient();
        $anon->request('GET', '/api/avatar/Alice');

        self::assertSame(502, $anon->getResponse()->getStatusCode());
    }
}
