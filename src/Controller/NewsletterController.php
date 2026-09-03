<?php

declare(strict_types=1);

namespace VRchessIndo\Controller;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use VRchessIndo\Service\VRChat\VRChatClientFactory;

/**
 * Community newsletter — pulls VRChess Indonesia's own VRChat group posts
 * (https://vrchat.community/reference/get-group-posts) straight from
 * VRChat, so the group's existing "Posts" announcements double as this
 * site's newsletter with no separate content system to maintain. The
 * public page only ever shows `visibility: public` posts (VRChat's own
 * per-post publicOnly filter) — "group"-only posts still show in the admin
 * management panel (so staff can see and edit them) but never on the
 * public /newsletter page.
 */
class NewsletterController extends AbstractApiController
{
    private const int LIST_TTL_SECONDS = 15 * 60;
    private const int IMAGE_TTL_SECONDS = 24 * 60 * 60;
    private const int MAX_UPLOAD_BYTES = 8 * 1024 * 1024;

    public function __construct(
        private readonly VRChatClientFactory $vrchatFactory,
        private readonly HttpClientInterface $httpClient,
        #[Autowire(service: 'cache.vrchat_posts')] private readonly CacheItemPoolInterface $postsCache,
        #[Autowire(env: 'VRCHAT_GROUP_ID')] private readonly string $groupId,
        #[Autowire(env: 'VRCHAT_CONTACT')] private readonly string $contact,
    ) {
    }

    #[Route('/newsletter', name: 'newsletter', methods: ['GET'])]
    public function index(): Response
    {
        $posts = $this->groupPosts(publicOnly: true);
        usort($posts, fn (array $a, array $b) => $b['createdAt'] <=> $a['createdAt']);

        return $this->render('newsletter.html.twig', [
            'posts' => $posts,
        ]);
    }

    #[Route('/api/admin/newsletter/posts', name: 'api_newsletter_admin_list', methods: ['GET'])]
    public function adminList(): JsonResponse
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        $posts = $this->groupPosts(publicOnly: false, forceRefresh: false);
        usort($posts, fn (array $a, array $b) => $b['createdAt'] <=> $a['createdAt']);

        $posts = array_map(
            fn (array $p) => $p + [
                'src' => $p['imageUrl'] !== null
                    ? $this->generateUrl('api_newsletter_admin_image', ['postId' => $p['id']])
                    : null,
            ],
            $posts,
        );

        return $this->json([
            'success' => true,
            'groupConfigured' => $this->groupId !== '',
            'posts' => $posts,
        ]);
    }

    #[Route('/api/admin/newsletter/posts', name: 'api_newsletter_admin_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }
        if ($this->groupId === '') {
            return $this->json(['success' => false, 'error' => 'VRCHAT_GROUP_ID belum dikonfigurasi'], 400);
        }

        [$title, $text, $visibility, $sendNotification, $error] = $this->readPostFields($request);
        if ($error !== null) {
            return $this->json(['success' => false, 'error' => $error], 400);
        }

        try {
            $this->vrchatFactory->build()->createGroupPost($this->groupId, $title, $text, $visibility, $sendNotification);
        } catch (\Throwable $e) {
            return $this->json(['success' => false, 'error' => $this->explainVrchatError($e)], 502);
        }

        $this->groupPosts(publicOnly: false, forceRefresh: true);

        return $this->json(['success' => true, 'message' => 'Post berhasil dibuat.']);
    }

    #[Route('/api/admin/newsletter/posts/{postId}', name: 'api_newsletter_admin_update', methods: ['PATCH'])]
    public function update(string $postId, Request $request): JsonResponse
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }
        if ($this->groupId === '') {
            return $this->json(['success' => false, 'error' => 'VRCHAT_GROUP_ID belum dikonfigurasi'], 400);
        }

        [$title, $text, $visibility, $sendNotification, $error] = $this->readPostFields($request);
        if ($error !== null) {
            return $this->json(['success' => false, 'error' => $error], 400);
        }

        $existing = $this->findPost($postId);
        if ($existing === null) {
            return $this->json(['success' => false, 'error' => 'Post tidak ditemukan'], 404);
        }

        try {
            $this->vrchatFactory->build()->updateGroupPost(
                $this->groupId,
                $postId,
                $title,
                $text,
                $visibility,
                $sendNotification,
                $existing['imageId'],
            );
        } catch (\Throwable $e) {
            return $this->json(['success' => false, 'error' => $this->explainVrchatError($e)], 502);
        }

        $this->groupPosts(publicOnly: false, forceRefresh: true);

        return $this->json(['success' => true, 'message' => 'Post berhasil diperbarui.']);
    }

    /**
     * Attaches/replaces the image on an existing post — kept as its own
     * multipart endpoint (separate from update()'s JSON body) for the same
     * reason GalleryController's upload is separate: PHP only parses
     * multipart/form-data bodies for POST requests, never PATCH/PUT.
     */
    #[Route('/api/admin/newsletter/posts/{postId}/image', name: 'api_newsletter_admin_image_upload', methods: ['POST'])]
    public function uploadImage(string $postId, Request $request): JsonResponse
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

        $existing = $this->findPost($postId);
        if ($existing === null) {
            return $this->json(['success' => false, 'error' => 'Post tidak ditemukan'], 404);
        }

        try {
            $client = $this->vrchatFactory->build();
            $fileId = $client->uploadGalleryImage($png);
            $client->updateGroupPost(
                $this->groupId,
                $postId,
                $existing['title'],
                $existing['text'],
                $existing['visibility'],
                false,
                $fileId,
            );
        } catch (\Throwable $e) {
            return $this->json(['success' => false, 'error' => $this->explainVrchatError($e)], 502);
        }

        $this->groupPosts(publicOnly: false, forceRefresh: true);

        return $this->json(['success' => true, 'message' => 'Gambar post berhasil diperbarui.']);
    }

    #[Route('/api/admin/newsletter/posts/{postId}', name: 'api_newsletter_admin_delete', methods: ['DELETE'])]
    public function delete(string $postId): JsonResponse
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }
        if ($this->groupId === '') {
            return $this->json(['success' => false, 'error' => 'VRCHAT_GROUP_ID belum dikonfigurasi'], 400);
        }

        try {
            $this->vrchatFactory->build()->deleteGroupPost($this->groupId, $postId);
        } catch (\Throwable $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 502);
        }

        $this->groupPosts(publicOnly: false, forceRefresh: true);

        return $this->json(['success' => true, 'message' => 'Post berhasil dihapus.']);
    }

    #[Route('/api/admin/newsletter/refresh', name: 'api_newsletter_admin_refresh', methods: ['POST'])]
    public function refresh(): JsonResponse
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        $posts = $this->groupPosts(publicOnly: false, forceRefresh: true);

        return $this->json([
            'success' => true,
            'message' => 'Newsletter berhasil diperbarui.',
            'count' => count($posts),
        ]);
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: bool, 4: ?string} [title, text, visibility, sendNotification, error]
     */
    private function readPostFields(Request $request): array
    {
        $input = json_decode($request->getContent(), true) ?? [];
        $title = trim((string) ($input['title'] ?? ''));
        $text = trim((string) ($input['text'] ?? ''));
        $visibility = (string) ($input['visibility'] ?? 'group');
        $sendNotification = !empty($input['send_notification']);

        if ($title === '' || $text === '') {
            return ['', '', '', false, 'Judul dan isi post diperlukan'];
        }
        if (!in_array($visibility, ['group', 'public'], true)) {
            return ['', '', '', false, "visibility harus 'group' atau 'public'"];
        }

        return [$title, $text, $visibility, $sendNotification, null];
    }

    /** @return array{id: string, title: string, text: string, imageId: ?string, visibility: string}|null */
    private function findPost(string $postId): ?array
    {
        foreach ($this->groupPosts(publicOnly: false) as $post) {
            if ($post['id'] === $postId) {
                return $post;
            }
        }

        return null;
    }

    /**
     * Fetches (and caches) the group's posts. Public and admin views use
     * separate cache entries (publicOnly changes what VRChat returns), both
     * short-lived (15 min, vs. the gallery's hour — posts are timelier
     * "news" content admins expect to see reflected sooner) with the same
     * stale-on-failure fallback used throughout this app's VRChat
     * integrations.
     *
     * @return array<int, array{id: string, title: string, text: string, imageId: ?string, imageUrl: ?string, visibility: string, createdAt: string, updatedAt: string}>
     */
    private function groupPosts(bool $publicOnly, bool $forceRefresh = false): array
    {
        if ($this->groupId === '') {
            return [];
        }

        $cacheKey = 'newsletter_posts_' . ($publicOnly ? 'public' : 'all') . '_' . md5($this->groupId);
        $item = $this->postsCache->getItem($cacheKey);
        if (!$forceRefresh && $item->isHit()) {
            return $item->get();
        }

        try {
            $posts = $this->vrchatFactory->build()->getGroupPosts($this->groupId, $publicOnly);
        } catch (\Throwable) {
            return $item->isHit() ? $item->get() : [];
        }

        $item->set($posts);
        $item->expiresAfter(self::LIST_TTL_SECONDS);
        $this->postsCache->save($item);

        return $posts;
    }

    /**
     * Proxies one post's image bytes (VRChat's CDN needs a custom
     * User-Agent, same as the avatar and gallery proxies). Only looks up
     * against public posts — a post's image is never reachable through this
     * route unless the post itself is public, matching what actually shows
     * on the public /newsletter page.
     */
    #[Route('/api/newsletter/image/{postId}', name: 'newsletter_image', methods: ['GET'])]
    public function image(string $postId): Response
    {
        return $this->servePostImage($postId, publicOnly: true);
    }

    /**
     * Same proxy as image(), but looks up against every post (public and
     * group-only) — used by the admin management panel, which needs to
     * preview a group-only post's image even though it never appears on
     * the public page.
     */
    #[Route('/api/admin/newsletter/posts/{postId}/image', name: 'api_newsletter_admin_image', methods: ['GET'])]
    public function adminImage(string $postId): Response
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        return $this->servePostImage($postId, publicOnly: false);
    }

    private function servePostImage(string $postId, bool $publicOnly): Response
    {
        $imageUrl = null;
        foreach ($this->groupPosts($publicOnly) as $post) {
            if ($post['id'] === $postId && $post['imageUrl'] !== null) {
                $imageUrl = $post['imageUrl'];
                break;
            }
        }

        if ($imageUrl === null) {
            return new Response('Unknown post image', 404, ['Content-Type' => 'text/plain']);
        }

        $cacheKey = 'newsletter_image_' . md5($imageUrl);
        $item = $this->postsCache->getItem($cacheKey);
        $cached = $item->isHit() ? $item->get() : null;

        if (!is_array($cached)) {
            $fetched = $this->fetchImage($imageUrl);
            if ($fetched !== null) {
                $cached = $fetched;
                $item->set($cached);
                $item->expiresAfter(self::IMAGE_TTL_SECONDS);
                $this->postsCache->save($item);
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
