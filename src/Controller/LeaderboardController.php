<?php

declare(strict_types=1);

namespace VRchessIndo\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

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
    public function index(): Response
    {
        return $this->render('leaderboard.html.twig');
    }
}
