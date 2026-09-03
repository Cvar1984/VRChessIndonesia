<?php

declare(strict_types=1);

namespace VRchessIndo\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Mirrors legacy index.php's requireAdmin($db)/requireApiAccess($db): each
 * mutating action calls one of these as its first line and returns early on
 * a non-null response, exactly like the legacy functions terminating via
 * jsonResponse(...). Kept as explicit in-controller checks (rather than
 * routing-layer #[IsGranted]/access_control) specifically so the JSON error
 * bodies match the legacy text byte-for-byte.
 *
 * ROLE_API_TOKEN is satisfied by either a valid API token or an admin
 * session/header (role_hierarchy: ROLE_ADMIN => ROLE_API_TOKEN in
 * security.yaml), matching requireApiAccess()'s "isAdmin() OR valid token".
 */
abstract class AbstractApiController extends AbstractController
{
    protected function requireAdmin(): ?JsonResponse
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return null;
        }

        return $this->json([
            'success' => false,
            'error' => 'Akses ditolak: Diperlukan autentikasi admin.',
        ], 401);
    }

    protected function requireApiAccess(): ?JsonResponse
    {
        if ($this->isGranted('ROLE_API_TOKEN')) {
            return null;
        }

        return $this->json([
            'success' => false,
            'error' => 'Akses API ditolak: Diperlukan API Token yang valid.',
        ], 401);
    }

    /**
     * VRChat's own generic "You don't have permission to perform this
     * action." on any endpoint that uploads a file (gallery images, post
     * images) turned out — confirmed via a one-off diagnostic against the
     * real account: Group Owner role with every group permission, but no
     * `system_supporter` tag — to mean the connected VRChat account lacks
     * an active VRC+ subscription. VRChat's Gallery/photo-upload feature is
     * a VRC+ perk, unrelated to group roles entirely. Appends that
     * explanation when the message matches, so hitting this again doesn't
     * require re-diagnosing it from scratch. Shared by GalleryController
     * and NewsletterController — both attach images via the same
     * upload-gallery-image endpoint under the hood.
     */
    protected function explainVrchatError(\Throwable $e): string
    {
        $message = $e->getMessage();
        if (str_contains($message, "don't have permission")) {
            $message .= ' Kemungkinan besar akun VRChat yang terhubung (VRCHAT_USERNAME) belum berlangganan VRC+ — upload gambar ke VRChat memerlukan VRC+ aktif, terlepas dari role/permission grup.';
        }

        return $message;
    }

    /**
     * Decodes an arbitrary image (JPEG/PNG/WEBP/GIF, whatever GD supports)
     * and re-encodes it as PNG, since VRChat's upload-gallery-image
     * endpoint documents its expected payload as "the binary blob of the
     * png file" specifically. Returns null if the input isn't a decodable
     * image at all. Shared by GalleryController and NewsletterController.
     */
    protected function normalizeToPng(string $bytes): ?string
    {
        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            return null;
        }

        imagepalettetotruecolor($img);
        imagealphablending($img, true);
        imagesavealpha($img, true);

        ob_start();
        imagepng($img, null, 6);
        $png = ob_get_clean();
        imagedestroy($img);

        return $png !== false ? $png : null;
    }
}
