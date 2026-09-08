<?php

declare(strict_types=1);

namespace VRchessIndo\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use VRchessIndo\Repository\AnalysisRepository;

/**
 * Serves the leaderboard SPA shell — the Twig-extracted equivalent of the
 * legacy index.php's final `echo $html` fallback (which served index.html
 * verbatim, only cache-busting the CSS link via filemtime()). AssetMapper's
 * own asset() versioning replaces that cache-busting mechanism; everything
 * else in templates/leaderboard.html.twig is index.html's markup, unchanged.
 */
class LeaderboardController extends AbstractController
{
    #[Route('/', name: 'leaderboard', methods: ['GET'])]
    public function index(Request $request, AnalysisRepository $analyses): Response
    {
        // Self-referencing by default (path only — deliberately drops any
        // query string, e.g. tracking params, so those don't fragment the
        // canonical into duplicates of themselves). The one deliberate
        // exception: a *known* ?analysis=<id> is real, distinct content (a
        // specific analyzed game), so it gets its own title/description and
        // canonical below instead of collapsing into the homepage's.
        $meta = [
            'url' => $request->getSchemeAndHttpHost() . $request->getPathInfo(),
        ];

        $analysisId = $request->query->get('analysis');
        if (is_string($analysisId) && $analysisId !== '') {
            $analysis = $analyses->findOneById($analysisId);
            if ($analysis !== null) {
                $headers = $analysis->toPreviewArray()['headers'];
                $white = $headers['White'] ?? '?';
                $black = $headers['Black'] ?? '?';
                $result = $headers['Result'] ?? null;

                $meta['title'] = sprintf('%s vs %s — VRChess Analysis', $white, $black);
                $meta['description'] = sprintf(
                    'Analisis Stockfish untuk pertandingan %s vs %s%s di VRChess Indonesia.',
                    $white,
                    $black,
                    $result ? " ({$result})" : '',
                );
                $meta['url'] = $request->getUri();
            }
        }

        return $this->render('leaderboard.html.twig', ['meta' => $meta]);
    }
}
