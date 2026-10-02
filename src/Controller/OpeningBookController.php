<?php

declare(strict_types=1);

namespace VRchessIndo\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Opening theory for Game Review's "Book" label: the moves played from a position
 * in the Lichess masters database. Lichess's explorer needs an API token now, so
 * this proxies it server-side (a personal token with no scopes, from
 * lichess.org/account/oauth/token, set as LICHESS_API_TOKEN) instead of exposing
 * the token to browsers, and caches each position — theory doesn't change.
 * Without a token it answers available:false and the frontend falls back to its
 * bundled list of named openings.
 */
class OpeningBookController extends AbstractController
{
    private const int CACHE_SECONDS = 30 * 24 * 60 * 60;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        // Shares the server-engine limiter: both are public routes that spend something
        // upstream per uncached request (here, the token's Lichess rate limit).
        #[Autowire(service: 'limiter.engine_requests')] private readonly RateLimiterFactory $limiter,
        #[Autowire('%env(default::LICHESS_API_TOKEN)%')] private readonly ?string $lichessToken,
    ) {
    }

    #[Route('/api/opening-book', name: 'api_opening_book', methods: ['GET'])]
    public function lookup(Request $request): JsonResponse
    {
        if (($this->lichessToken ?? '') === '') {
            return $this->json(['success' => true, 'available' => false, 'moves' => []]);
        }

        $fen = trim((string) $request->query->get('fen', ''));
        if ($fen === '' || strlen($fen) > 100 || !preg_match('/^[prnbqkPRNBQK1-8\/]+ [wb] [KQkqA-Ha-h-]+ [a-h1-8-]+( \d+ \d+)?$/', $fen)) {
            return $this->json(['success' => false, 'error' => 'FEN tidak valid'], 400);
        }

        if (!$this->isGranted('ROLE_API_TOKEN')
            && !$this->limiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return $this->json(['success' => false, 'error' => 'Terlalu banyak permintaan. Coba lagi sebentar lagi.'], 429);
        }

        try {
            $moves = $this->cache->get('opening_book_' . md5($fen), function (ItemInterface $item) use ($fen): array {
                $item->expiresAfter(self::CACHE_SECONDS);

                return $this->fetchMasters($fen);
            });
        } catch (\Throwable) {
            // Lichess down or rate-limiting the token: no book info this time, not an error
            // the review should fail on. Not cached, so the next review asks again.
            return $this->json(['success' => true, 'available' => false, 'moves' => []]);
        }

        return $this->json(['success' => true, 'available' => true, 'moves' => $moves]);
    }

    /** @return list<array{uci: string, san: string, games: int}> */
    private function fetchMasters(string $fen): array
    {
        $data = $this->httpClient->request('GET', 'https://explorer.lichess.ovh/masters', [
            'query' => ['fen' => $fen, 'moves' => 30, 'topGames' => 0],
            'headers' => ['Authorization' => 'Bearer ' . $this->lichessToken],
            'timeout' => 5,
        ])->toArray();

        return array_map(static fn (array $m): array => [
            'uci' => (string) ($m['uci'] ?? ''),
            'san' => (string) ($m['san'] ?? ''),
            'games' => (int) ($m['white'] ?? 0) + (int) ($m['draws'] ?? 0) + (int) ($m['black'] ?? 0),
        ], $data['moves'] ?? []);
    }
}
