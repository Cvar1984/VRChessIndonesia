<?php

declare(strict_types=1);

namespace VRchessIndo\Controller;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use VRchessIndo\Repository\PlayerRepository;

/**
 * Proxies a linked player's cached VRChat picture, fetched server-side
 * (VRChat's CDN rejects browser <img> requests outright — it requires a
 * custom User-Agent identifying the calling app, which only our own server
 * can send). Matches the legacy GET /index.php?avatar=<username> behavior,
 * including its "serve a stale cached copy rather than nothing" fallback
 * when a refetch fails — ported onto Symfony Cache (PSR-6, the `cache.avatars`
 * pool) instead of the legacy's hand-rolled serialize()-to-disk cache.
 *
 * Uses the pool directly (getItem()/save()) rather than the CacheInterface
 * get()-with-callback convenience wrapper, since that wrapper treats an
 * expired item as a plain miss — it can't hand back the stale value on a
 * failed refetch the way this needs to.
 */
class AvatarProxyController extends AbstractController
{
    private const int TTL_SECONDS = 24 * 60 * 60;

    public function __construct(
        private readonly PlayerRepository $players,
        private readonly HttpClientInterface $httpClient,
        #[Autowire(service: 'cache.avatars')] private readonly CacheItemPoolInterface $avatarsCache,
        #[Autowire(env: 'VRCHAT_CONTACT')] private readonly string $contact,
    ) {
    }

    #[Route('/api/avatar/{username}', name: 'api_avatar', methods: ['GET'])]
    public function avatar(string $username): Response
    {
        $player = $this->players->findOneByUsername(trim($username));
        $avatarUrl = $player?->getAvatarUrl();

        if (empty($avatarUrl)) {
            return new Response('No cached avatar for this player', 404, ['Content-Type' => 'text/plain']);
        }

        $item = $this->avatarsCache->getItem('avatar_' . md5($avatarUrl));
        $cached = $item->isHit() ? $item->get() : null;
        $isFresh = is_array($cached) && (time() - ($cached['cached_at'] ?? 0)) < self::TTL_SECONDS;

        if (!$isFresh) {
            $fetched = $this->fetchAvatar($avatarUrl);
            if ($fetched !== null) {
                $cached = $fetched;
                $item->set($cached);
                $this->avatarsCache->save($item);
            }
            // Fetch failed (or nothing changed): fall through and serve
            // whatever $cached already held — possibly stale, possibly null.
        }

        if (!is_array($cached) || empty($cached['body'])) {
            return new Response('', 502);
        }

        return new Response($cached['body'], 200, [
            'Content-Type' => $cached['content_type'],
            'Cache-Control' => 'public, max-age=' . self::TTL_SECONDS,
        ]);
    }

    /**
     * @return array{content_type: string, body: string, cached_at: int}|null
     */
    private function fetchAvatar(string $url): ?array
    {
        $userAgent = 'VRchessIndo/1.0' . ($this->contact !== '' ? " ({$this->contact})" : '');

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => ['User-Agent' => $userAgent],
                'max_redirects' => 3,
                'timeout' => 10,
            ]);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
            $contentType = $response->getHeaders(false)['content-type'][0] ?? 'image/png';
        } catch (\Throwable) {
            return null;
        }

        if ($status < 200 || $status >= 300 || $body === '') {
            return null;
        }

        return ['content_type' => $contentType, 'body' => $body, 'cached_at' => time()];
    }
}
