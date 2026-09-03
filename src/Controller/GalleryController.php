<?php

declare(strict_types=1);

namespace VRchessIndo\Controller;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use VRchessIndo\Document\Setting;
use VRchessIndo\Repository\SettingRepository;
use VRchessIndo\Service\VRChat\VRChatClientFactory;

/**
 * Community photo gallery. Pulls every approved image from VRChess
 * Indonesia's own VRChat group gallery/galleries
 * (https://vrchat.community/reference/get-group-gallery-images) — if
 * VRCHAT_GROUP_GALLERY_ID isn't set to one specific gallery, every gallery
 * the group has is auto-discovered and combined, grouped under its own
 * heading on the public page. No local fallback set — an
 * unconfigured/unreachable VRChat account just means an empty gallery
 * page, not stand-in photos.
 *
 * Admins get real management on top of VRChat's own "approved" flag:
 * individual photos can be hidden from this site's public grid (without
 * touching anything on the VRChat side), new photos can be uploaded
 * straight into a chosen VRChat gallery, new galleries can be created on
 * the group, and the cached photo list can be force-refreshed. Hidden IDs
 * are stored in the `settings` collection (same generic key/value store
 * VRChatClient already uses for its session cache), keyed by
 * VRCHAT_GROUP_ID + VRCHAT_GROUP_GALLERY_ID so switching which
 * gallery/galleries feed the site doesn't carry over a stale hide-list from
 * a different configuration.
 */
class GalleryController extends AbstractApiController
{
    private const int LIST_TTL_SECONDS = 60 * 60;
    private const int IMAGE_TTL_SECONDS = 24 * 60 * 60;
    private const int MAX_UPLOAD_BYTES = 8 * 1024 * 1024;

    public function __construct(
        private readonly VRChatClientFactory $vrchatFactory,
        private readonly HttpClientInterface $httpClient,
        private readonly SettingRepository $settings,
        private readonly DocumentManager $dm,
        #[Autowire(service: 'cache.vrchat_gallery')] private readonly CacheItemPoolInterface $galleryCache,
        #[Autowire(env: 'VRCHAT_GROUP_ID')] private readonly string $groupId,
        #[Autowire(env: 'VRCHAT_GROUP_GALLERY_ID')] private readonly string $groupGalleryId,
        #[Autowire(env: 'VRCHAT_CONTACT')] private readonly string $contact,
    ) {
    }

    #[Route('/gallery', name: 'gallery', methods: ['GET'])]
    public function index(): Response
    {
        $hidden = $this->hiddenIds();
        $photosByGallery = [];
        foreach ($this->vrchatPhotos() as $img) {
            if (isset($hidden[$img['id']])) {
                continue;
            }
            $photosByGallery[$img['galleryId']][] = [
                'src' => $this->generateUrl('gallery_vrchat_image', ['imageId' => $img['id']]),
                'title' => 'VRChess Indonesia',
                'caption' => $img['createdAt'] !== null
                    ? (new \DateTimeImmutable($img['createdAt']))->format('d M Y')
                    : 'from the group gallery',
            ];
        }

        $galleries = [];
        foreach ($this->vrchatGalleries() as $g) {
            if (empty($photosByGallery[$g['id']])) {
                continue;
            }
            $galleries[] = [
                'name' => $g['name'] !== '' ? $g['name'] : 'Galeri',
                'photos' => $photosByGallery[$g['id']],
            ];
        }

        return $this->render('gallery.html.twig', [
            'galleries' => $galleries,
        ]);
    }

    /**
     * Lists every gallery (including ones with zero photos, e.g. freshly
     * created) with every fetched photo (including hidden ones, flagged as
     * such) for the admin management panel — unlike the public /gallery
     * page, nothing is filtered out here.
     */
    #[Route('/api/admin/gallery/photos', name: 'api_gallery_admin_photos', methods: ['GET'])]
    public function adminPhotos(): JsonResponse
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        $hidden = $this->hiddenIds();
        $photosByGallery = [];
        foreach ($this->vrchatPhotos() as $img) {
            $photosByGallery[$img['galleryId']][] = [
                'id' => $img['id'],
                'src' => $this->generateUrl('gallery_vrchat_image', ['imageId' => $img['id']]),
                'createdAt' => $img['createdAt'],
                'hidden' => isset($hidden[$img['id']]),
            ];
        }

        $galleries = array_map(
            fn (array $g) => [
                'id' => $g['id'],
                'name' => $g['name'] !== '' ? $g['name'] : $g['id'],
                'photos' => $photosByGallery[$g['id']] ?? [],
            ],
            $this->vrchatGalleries(),
        );

        return $this->json([
            'success' => true,
            'groupConfigured' => $this->groupId !== '',
            'galleries' => $galleries,
        ]);
    }

    #[Route('/api/admin/gallery/hide', name: 'api_gallery_admin_hide', methods: ['POST'])]
    public function hidePhoto(Request $request): JsonResponse
    {
        return $this->setHidden($request, true);
    }

    #[Route('/api/admin/gallery/unhide', name: 'api_gallery_admin_unhide', methods: ['POST'])]
    public function unhidePhoto(Request $request): JsonResponse
    {
        return $this->setHidden($request, false);
    }

    private function setHidden(Request $request, bool $hide): JsonResponse
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        $input = json_decode($request->getContent(), true) ?? [];
        $imageId = trim((string) ($input['image_id'] ?? ''));
        if ($imageId === '') {
            return $this->json(['success' => false, 'error' => 'image_id diperlukan'], 400);
        }

        $hidden = $this->hiddenIds();
        if ($hide) {
            $hidden[$imageId] = true;
        } else {
            unset($hidden[$imageId]);
        }
        $this->saveHiddenIds(array_keys($hidden));

        return $this->json(['success' => true]);
    }

    /**
     * Creates a brand-new gallery on the VRChat group, so the admin has
     * somewhere to upload into beyond whatever galleries already exist —
     * mirrors VRChat's own "+ Create Gallery" group-settings action.
     */
    #[Route('/api/admin/gallery/create-gallery', name: 'api_gallery_admin_create_gallery', methods: ['POST'])]
    public function createGallery(Request $request): JsonResponse
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        if ($this->groupId === '') {
            return $this->json(['success' => false, 'error' => 'VRCHAT_GROUP_ID belum dikonfigurasi'], 400);
        }

        $input = json_decode($request->getContent(), true) ?? [];
        $name = trim((string) ($input['name'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        if ($name === '') {
            return $this->json(['success' => false, 'error' => 'Nama galeri diperlukan'], 400);
        }

        try {
            $gallery = $this->vrchatFactory->build()->createGroupGallery($this->groupId, $name, $description);
        } catch (\Throwable $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 502);
        }

        $this->vrchatGalleries(true);
        $this->vrchatPhotos(true);

        return $this->json(['success' => true, 'gallery' => $gallery]);
    }

    /**
     * Uploads an admin-supplied image straight into one of the group's
     * VRChat galleries: normalizes it to PNG (VRChat's upload endpoint
     * documents a PNG file as the expected payload, and admin-supplied
     * photos may arrive as JPEG straight off a phone), uploads it for a
     * file ID, then attaches that file to the given gallery.
     */
    #[Route('/api/admin/gallery/{galleryId}/upload', name: 'api_gallery_admin_upload', methods: ['POST'])]
    public function uploadPhoto(string $galleryId, Request $request): JsonResponse
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        if ($this->groupId === '') {
            return $this->json(['success' => false, 'error' => 'VRCHAT_GROUP_ID belum dikonfigurasi'], 400);
        }

        $file = $request->files->get('file');
        if ($file === null) {
            return $this->json(['success' => false, 'error' => 'File gambar diperlukan'], 400);
        }
        if ($file->getSize() === null || $file->getSize() > self::MAX_UPLOAD_BYTES) {
            return $this->json(['success' => false, 'error' => 'Ukuran gambar maksimal 8MB'], 400);
        }

        $bytes = file_get_contents($file->getPathname());
        $png = $bytes !== false ? $this->normalizeToPng($bytes) : null;
        if ($png === null) {
            return $this->json(['success' => false, 'error' => 'File yang diunggah bukan gambar yang valid'], 400);
        }

        try {
            $client = $this->vrchatFactory->build();
            $fileId = $client->uploadGalleryImage($png);
            $client->addGroupGalleryImage($this->groupId, $galleryId, $fileId);
        } catch (\Throwable $e) {
            return $this->json(['success' => false, 'error' => $this->explainVrchatError($e)], 502);
        }

        $this->vrchatPhotos(true);

        return $this->json(['success' => true, 'message' => 'Gambar berhasil diunggah ke galeri VRChat.']);
    }

    /**
     * Bypasses both caches and re-fetches from VRChat immediately — for
     * when a new photo/gallery was just approved/created and the admin
     * doesn't want to wait out the hour-long cache TTL.
     */
    #[Route('/api/admin/gallery/refresh', name: 'api_gallery_admin_refresh', methods: ['POST'])]
    public function refresh(): JsonResponse
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        $this->vrchatGalleries(true);
        $photos = $this->vrchatPhotos(true);

        return $this->json([
            'success' => true,
            'message' => 'Galeri VRChat berhasil diperbarui.',
            'count' => count($photos),
        ]);
    }

    private function hiddenSettingKey(): string
    {
        return 'vrchat_gallery_hidden_' . md5($this->groupId . '_' . $this->groupGalleryId);
    }

    /** @return array<string, true> set of hidden image IDs */
    private function hiddenIds(): array
    {
        $setting = $this->settings->findOneByKey($this->hiddenSettingKey());
        if ($setting === null) {
            return [];
        }

        $decoded = json_decode($setting->getValue(), true);
        return is_array($decoded) ? array_fill_keys(array_map('strval', $decoded), true) : [];
    }

    /** @param string[] $ids */
    private function saveHiddenIds(array $ids): void
    {
        $key = $this->hiddenSettingKey();
        $value = json_encode(array_values(array_unique($ids)));
        $setting = $this->settings->findOneByKey($key);

        if ($setting === null) {
            $setting = new Setting($key, $value);
            $this->dm->persist($setting);
        } else {
            $setting->setValue($value);
        }

        $this->dm->flush();
    }

    /**
     * Fetches (and caches) the group's list of galleries. In
     * single-gallery mode (VRCHAT_GROUP_GALLERY_ID set), we don't actually
     * know that gallery's real name without an extra API call, so it's
     * left blank and callers fall back to showing the ID.
     *
     * @return array<int, array{id: string, name: string}>
     */
    private function vrchatGalleries(bool $forceRefresh = false): array
    {
        if ($this->groupId === '') {
            return [];
        }

        if ($this->groupGalleryId !== '') {
            return [['id' => $this->groupGalleryId, 'name' => '']];
        }

        $cacheKey = 'gallery_meta_' . md5($this->groupId);
        $item = $this->galleryCache->getItem($cacheKey);
        if (!$forceRefresh && $item->isHit()) {
            return $item->get();
        }

        try {
            $galleries = $this->vrchatFactory->build()->getGroupGalleries($this->groupId);
        } catch (\Throwable) {
            return $item->isHit() ? $item->get() : [];
        }

        $item->set($galleries);
        $item->expiresAfter(self::LIST_TTL_SECONDS);
        $this->galleryCache->save($item);

        return $galleries;
    }

    /**
     * Fetches (and caches) the combined image list across every relevant
     * group gallery, newest first, tagged with which gallery each photo
     * came from. Returns [] if VRChat isn't configured or the fetch fails
     * (falling back to a stale cached copy if one exists rather than
     * failing outright) — the public page just renders an empty-state
     * message in that case, no stand-in photos.
     *
     * @return array<int, array{id: string, imageUrl: string, createdAt: ?string, galleryId: string, galleryName: string}>
     */
    private function vrchatPhotos(bool $forceRefresh = false): array
    {
        if ($this->groupId === '') {
            return [];
        }

        $cacheKey = 'gallery_list_' . md5($this->groupId . '_' . $this->groupGalleryId);
        $item = $this->galleryCache->getItem($cacheKey);
        if (!$forceRefresh && $item->isHit()) {
            return $item->get();
        }

        $galleries = $this->vrchatGalleries($forceRefresh);
        if ($galleries === []) {
            return $item->isHit() ? $item->get() : [];
        }

        try {
            $client = $this->vrchatFactory->build();
            $images = [];
            $seenIds = [];
            foreach ($galleries as $gallery) {
                foreach ($client->getGroupGalleryImages($this->groupId, $gallery['id']) as $image) {
                    if (isset($seenIds[$image['id']])) {
                        continue;
                    }
                    $seenIds[$image['id']] = true;
                    $image['galleryId'] = $gallery['id'];
                    $image['galleryName'] = $gallery['name'];
                    $images[] = $image;
                }
            }

            usort($images, fn (array $a, array $b) => ($b['createdAt'] ?? '') <=> ($a['createdAt'] ?? ''));
        } catch (\Throwable) {
            // Refetch failed — serve a stale cached copy if one exists rather
            // than an empty gallery, matching AvatarProxyController's pattern.
            return $item->isHit() ? $item->get() : [];
        }

        $item->set($images);
        $item->expiresAfter(self::LIST_TTL_SECONDS);
        $this->galleryCache->save($item);

        return $images;
    }

    /**
     * Proxies one gallery image's bytes (VRChat's CDN needs a custom
     * User-Agent, same as the avatar proxy — a plain browser <img> request
     * to it directly gets rejected). The imageId is looked up against our
     * own already-fetched, server-side-cached list rather than accepting a
     * client-supplied URL, so this can't be used to fetch arbitrary URLs.
     */
    #[Route('/api/gallery/vrchat-image/{imageId}', name: 'gallery_vrchat_image', methods: ['GET'])]
    public function vrchatImage(string $imageId): Response
    {
        $vrchatPhotos = $this->vrchatPhotos();
        $imageUrl = null;
        foreach ($vrchatPhotos as $img) {
            if ($img['id'] === $imageId) {
                $imageUrl = $img['imageUrl'];
                break;
            }
        }

        if ($imageUrl === null) {
            return new Response('Unknown gallery image', 404, ['Content-Type' => 'text/plain']);
        }

        $cacheKey = 'gallery_image_' . md5($imageUrl);
        $item = $this->galleryCache->getItem($cacheKey);
        $cached = $item->isHit() ? $item->get() : null;

        if (!is_array($cached)) {
            $fetched = $this->fetchImage($imageUrl);
            if ($fetched !== null) {
                $cached = $fetched;
                $item->set($cached);
                $item->expiresAfter(self::IMAGE_TTL_SECONDS);
                $this->galleryCache->save($item);
            }
        }

        if (!is_array($cached) || empty($cached['body'])) {
            return new Response('', 502);
        }

        return new Response($cached['body'], 200, [
            'Content-Type' => $cached['content_type'],
            'Cache-Control' => 'public, max-age=' . self::IMAGE_TTL_SECONDS,
        ]);
    }

    /** @return array{content_type: string, body: string}|null */
    private function fetchImage(string $url): ?array
    {
        $userAgent = 'VRchessIndo/1.0' . ($this->contact !== '' ? " ({$this->contact})" : '');

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => ['User-Agent' => $userAgent],
                'max_redirects' => 3,
                'timeout' => 15,
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

        return ['content_type' => $contentType, 'body' => $body];
    }
}
