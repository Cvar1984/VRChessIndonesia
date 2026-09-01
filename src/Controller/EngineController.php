<?php

declare(strict_types=1);

namespace VRchessIndo\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use VRchessIndo\Service\Engine\StockfishEngineFactory;

/**
 * Ports stockfish.php's three request shapes onto three clean routes
 * instead of one endpoint that branches on request body shape (same
 * "new URLs, same behavior" convention used everywhere else in this
 * migration). No auth on any of these — legacy never gated stockfish.php
 * either, since analysis is read-only and used by anonymous visitors
 * browsing games, not just admins.
 */
class EngineController extends AbstractController
{
    public function __construct(private readonly StockfishEngineFactory $engines)
    {
    }

    #[Route('/api/engine/analyze', name: 'api_engine_analyze', methods: ['GET', 'POST'])]
    public function analyze(Request $request): JsonResponse
    {
        set_time_limit(0);

        try {
            $input = $this->requestData($request);

            if (empty($input['fen'])) {
                return $this->json(['error' => "Missing 'fen' or 'fens'"], 400);
            }

            $fen = $this->sanitizeFen((string) $input['fen']);
            [$depth, $movetime] = $this->resolveDepthAndMovetime($input, 26);
            $multipv = $this->resolveMultipv($input);
            $chess960 = (bool) ($input['chess960'] ?? false);

            $engine = $this->engines->create($multipv, $chess960);
            $result = $engine->analyze($fen, $depth, $movetime);

            return $this->json($result);
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    #[Route('/api/engine/analyze/batch', name: 'api_engine_analyze_batch', methods: ['GET', 'POST'])]
    public function analyzeBatch(Request $request): JsonResponse
    {
        set_time_limit(0);

        try {
            $input = $this->requestData($request);

            $fens = $input['fens'] ?? null;
            if (!is_array($fens)) {
                return $this->json(['error' => 'fens must be an array.'], 400);
            }

            $depth = isset($input['depth']) && is_numeric($input['depth'])
                && $input['depth'] >= 1 && $input['depth'] <= 26
                ? (int) $input['depth'] : 22; // 18 fast, 22 standard, 24 deep, 26 max

            $cleanFens = [];
            foreach ($fens as $f) {
                $cleanFens[] = $this->sanitizeFen((string) $f);
            }

            $multipv = $this->resolveMultipv($input);
            $chess960 = (bool) ($input['chess960'] ?? false);

            $positions = $this->analyzeFens($cleanFens, $depth, $multipv, $chess960);

            return $this->json([
                'success' => true,
                'positions' => $positions,
                'depth' => $depth,
                'count' => count($positions),
            ]);
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    #[Route('/api/engine/analyze/stream', name: 'api_engine_analyze_stream', methods: ['GET', 'POST'])]
    public function analyzeStream(Request $request): Response
    {
        set_time_limit(0);

        // Validate *before* committing to the SSE content-type — legacy hits
        // sanitizeFen() (and its outer catch-all) before ever setting the
        // stream headers, so a bad FEN here still gets a clean JSON error,
        // not malformed output spliced into an event stream.
        try {
            $input = $this->requestData($request);

            if (empty($input['fen'])) {
                return $this->json(['error' => "Missing 'fen' or 'fens'"], 400);
            }

            $fen = $this->sanitizeFen((string) $input['fen']);
            // Full depth (up to 99, effectively unbounded for Stockfish) is only
            // allowed for streaming, where the caller can watch it progress and
            // navigate away anytime — a one-shot request is capped at 26.
            [$depth, $movetime] = $this->resolveDepthAndMovetime($input, 99);
            $multipv = $this->resolveMultipv($input);
            $chess960 = (bool) ($input['chess960'] ?? false);
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }

        $response = new StreamedResponse(function () use ($fen, $depth, $movetime, $multipv, $chess960): void {
            try {
                $engine = $this->engines->create($multipv, $chess960);

                $lastStreamTime = 0.0;
                $result = $engine->analyze($fen, $depth, $movetime, function (array $res) use (&$lastStreamTime): void {
                    $now = microtime(true);
                    if ($now - $lastStreamTime < 0.03) {
                        return;
                    }
                    $lastStreamTime = $now;

                    $this->emitSse($this->infoPayload($res, 'info'));
                });

                $this->emitSse($this->infoPayload($result, 'done'));
            } catch (\Throwable $e) {
                $this->emitSse(['type' => 'error', 'error' => $e->getMessage()]);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    /**
     * @param array<int, string> $fens
     */
    private function analyzeFens(array $fens, int $depth, int $multipv, bool $chess960): array
    {
        $engine = $this->engines->create($multipv, $chess960);
        $results = [];

        foreach ($fens as $i => $fen) {
            $res = $engine->analyze($fen, $depth);

            $scoreDisplay = null;
            $scoreCp = null;
            if ($res['score_type'] === 'cp') {
                $cp = (int) $res['score'];
                // Always from White's perspective.
                $parts = explode(' ', $fen);
                $turn = $parts[1] ?? 'w';
                if ($turn === 'b') {
                    $cp = -$cp;
                }
                $scoreCp = $cp;
                $scoreDisplay = round($cp / 100, 2);
            } elseif ($res['score_type'] === 'mate') {
                $cp = (int) $res['score'];
                $scoreCp = $cp > 0 ? 9999 : -9999;
                $parts = explode(' ', $fen);
                $turn = $parts[1] ?? 'w';
                if ($turn === 'b') {
                    $scoreCp = -$scoreCp;
                }
                $scoreDisplay = 'M' . abs($cp);
            }

            $results[] = [
                'fen' => $fen,
                'move_index' => $i,
                'score' => $scoreDisplay,
                'score_cp' => $scoreCp,
                'score_type' => $res['score_type'],
                'bestmove' => $res['bestmove'],
                'pv' => array_slice($res['pv'] ?? [], 0, 6),
                'depth' => $res['depth'],
                'multipv' => $res['multipv'] ?? [],
            ];
        }

        return $results;
    }

    private function infoPayload(array $res, string $type): array
    {
        return [
            'type' => $type,
            'depth' => $res['depth'],
            'seldepth' => $res['seldepth'],
            'time' => $res['time'],
            'nodes' => $res['nodes'],
            'nps' => $res['nps'],
            'score' => $res['score'],
            'score_type' => $res['score_type'],
            'eval' => $res['eval'],
            'bestmove' => $res['bestmove'] ?? ($res['pv'][0] ?? null),
            'pv' => $res['pv'] ?? [],
            'multipv' => $res['multipv'] ?? [],
        ];
    }

    private function emitSse(array $payload): void
    {
        echo 'data: ' . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private function resolveDepthAndMovetime(array $input, int $maxDepth): array
    {
        $depth = isset($input['depth']) && is_numeric($input['depth'])
            && $input['depth'] >= 1 && $input['depth'] <= $maxDepth
            ? (int) $input['depth'] : null;

        $movetime = isset($input['movetime']) && is_numeric($input['movetime'])
            && $input['movetime'] >= 100 && $input['movetime'] <= 10000
            ? (int) $input['movetime'] : null;

        if ($depth === null && $movetime === null) {
            $depth = 18;
        }
        if ($movetime !== null) {
            $depth = null;
        }

        return [$depth, $movetime];
    }

    private function resolveMultipv(array $input): int
    {
        return max(1, min(5, (int) ($input['multipv'] ?? 1)));
    }

    private function sanitizeFen(string $fen): string
    {
        $fen = trim($fen);
        if (strlen($fen) > 128) {
            throw new \Exception('FEN too long.');
        }
        if (preg_match('/[\r\n\x00]/', $fen)) {
            throw new \Exception('Invalid characters in FEN.');
        }
        if (!preg_match('/^[prnbqkPRNBQK1-8\/wb\-\sKQkqa-h0-9]+$/', $fen)) {
            throw new \Exception('Invalid FEN.');
        }

        return $fen;
    }

    private function requestData(Request $request): array
    {
        $data = [];
        $content = $request->getContent();
        if ($content !== '') {
            $json = json_decode($content, true);
            if (is_array($json)) {
                $data = $json;
            }
        }

        return array_merge($request->query->all(), $data);
    }
}
