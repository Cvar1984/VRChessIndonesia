<?php

declare(strict_types=1);

namespace VRchessIndo\Service\VRChat;

use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use VRchessIndo\Document\Setting;
use VRchessIndo\Repository\SettingRepository;
use VRchessIndo\Service\Totp;

/**
 * Thin client for VRChat's unofficial API (documented at https://vrchat.community/,
 * hosted at api.vrchat.cloud — VRChat does not officially support or document it,
 * so endpoints and behavior may change without notice).
 *
 * Handles login (including authenticator-app TOTP 2FA), persists the
 * resulting session cookie in the `settings` collection (via
 * SettingRepository) so we don't burn a fresh VRChat session on every
 * request, and exposes only the two read endpoints this app needs:
 * searching users by display name and fetching a user by ID.
 *
 * Not registered as a container service directly (its credentials come from
 * env vars, and legacy only ever builds one lazily per-request, inside a
 * try/catch) — see VRChatClientFactory, which controllers use instead.
 *
 * Every outbound HTTP call (rawRequest()/multipartRequest(), which
 * everything else funnels through) is throttled to at most one request
 * every $rateLimitSeconds, self-imposed rather than reactive to VRChat
 * actually rate-limiting us — VRChat's unofficial API publishes no rate
 * limit numbers, is known to temporarily block accounts that hammer it, and
 * several of this client's own methods fire multiple sequential requests in
 * a tight loop (gallery/post pagination) with no other natural spacing. The
 * throttle's "last request at" timestamp is persisted the same way the
 * session cookie is (via the `settings` collection, not an in-memory
 * property) specifically because a fresh VRChatClient is constructed per
 * request/per call site (see VRChatClientFactory) — an in-memory-only timer
 * would reset every time and never actually space out calls made from
 * separate requests landing close together.
 */
class VRChatClient
{
    private const string BASE_URL = 'https://api.vrchat.cloud/api/1';
    private const string SESSION_SETTING_KEY = 'vrchat_session';
    private const string RATE_LIMIT_SETTING_KEY = 'vrchat_rate_limit_last_request_at';

    private ?array $session = null;
    private bool $sessionLoaded = false;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SettingRepository $settings,
        private readonly DocumentManager $dm,
        private readonly string $username,
        private readonly string $password,
        private readonly ?string $totpSecret,
        private readonly string $userAgent,
        // Defaults to off (0), not a "safe-sounding" nonzero value: the real
        // app always goes through VRChatClientFactory, which explicitly
        // passes the real VRCHAT_RATE_LIMIT_SECONDS env value regardless —
        // this default only matters for callers that construct VRChatClient
        // directly (namely VRChatClientTest, against a MockHttpClient with
        // no real API to be polite to), where a nonzero default would
        // silently add real sleep time to every test without those tests
        // ever asking for it.
        private readonly float $rateLimitSeconds = 0,
    ) {
        if (trim($this->username) === '' || trim($this->password) === '') {
            throw new \Exception('VRChat belum dikonfigurasi. Isi VRCHAT_USERNAME dan VRCHAT_PASSWORD di .env');
        }
    }

    /**
     * Searches VRChat users by display name (fuzzy match, VRChat-side).
     *
     * @return array<int, array{id: string, displayName: string, thumbnail: ?string}>
     */
    public function searchUsers(string $query, int $limit = 10): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $n = max(1, min(100, $limit));
        $data = $this->authedRequest('GET', '/users?search=' . rawurlencode($query) . '&n=' . $n);

        $results = [];
        foreach ((array) $data as $user) {
            if (!is_array($user) || empty($user['id'])) {
                continue;
            }
            $results[] = [
                'id' => (string) $user['id'],
                'displayName' => (string) ($user['displayName'] ?? ''),
                'thumbnail' => $this->pickAvatarUrl($user),
            ];
        }

        return $results;
    }

    /**
     * Fetches a single VRChat user by their stable ID.
     *
     * @return array{id: string, displayName: string, avatarUrl: ?string}|null
     */
    public function getUser(string $userId): ?array
    {
        $userId = trim($userId);
        if ($userId === '') {
            return null;
        }

        $data = $this->authedRequest('GET', '/users/' . rawurlencode($userId));
        if (!is_array($data) || empty($data['id'])) {
            return null;
        }

        return [
            'id' => (string) $data['id'],
            'displayName' => (string) ($data['displayName'] ?? ''),
            'avatarUrl' => $this->pickAvatarUrl($data),
        ];
    }

    /**
     * Lists every gallery defined on a VRChat group (a group can have more
     * than one — e.g. "Screenshots", "Events" — each with its own ID), via
     * the group object's own `galleries` field
     * (https://vrchat.community/reference/get-group).
     *
     * @return array<int, array{id: string, name: string}>
     */
    public function getGroupGalleries(string $groupId): array
    {
        $data = $this->authedRequest('GET', '/groups/' . rawurlencode($groupId));

        $galleries = [];
        foreach ((array) ($data['galleries'] ?? []) as $gallery) {
            if (!is_array($gallery) || empty($gallery['id'])) {
                continue;
            }
            $galleries[] = [
                'id' => (string) $gallery['id'],
                'name' => (string) ($gallery['name'] ?? ''),
            ];
        }

        return $galleries;
    }

    /**
     * Fetches every approved image from one of a VRChat group's galleries
     * (VRChess Indonesia's own group, not a per-user gallery) — see
     * https://vrchat.community/reference/get-group-gallery-images. Pages
     * through the full gallery (the API caps each request at 100 images)
     * rather than just the first page, stopping once a page comes back
     * short of a full page (the API exposes no total count to page against
     * instead) or a hard cap is hit as a runaway-loop safety net.
     *
     * @return array<int, array{id: string, imageUrl: string, createdAt: ?string}>
     */
    public function getGroupGalleryImages(string $groupId, string $groupGalleryId): array
    {
        $n = 100;
        $offset = 0;
        $hardCap = 2000;
        $results = [];

        do {
            $path = '/groups/' . rawurlencode($groupId) . '/galleries/' . rawurlencode($groupGalleryId)
                . '?n=' . $n . '&offset=' . $offset . '&approved=true';

            $data = $this->authedRequest('GET', $path);
            $page = (array) $data;

            foreach ($page as $image) {
                if (!is_array($image) || empty($image['id']) || empty($image['imageUrl'])) {
                    continue;
                }
                $results[] = [
                    'id' => (string) $image['id'],
                    'imageUrl' => (string) $image['imageUrl'],
                    'createdAt' => isset($image['createdAt']) ? (string) $image['createdAt'] : null,
                ];
            }

            $offset += $n;
        } while (count($page) === $n && count($results) < $hardCap);

        return $results;
    }

    /**
     * Fetches every post on a VRChat group ("newsletter" content) — see
     * https://vrchat.community/reference/get-group-posts. Unlike the
     * gallery-image endpoint, this one exposes a real `total` count, so
     * pagination stops precisely instead of guessing from a short page.
     *
     * @return array<int, array{id: string, title: string, text: string, imageId: ?string, imageUrl: ?string, visibility: string, createdAt: string, updatedAt: string}>
     */
    public function getGroupPosts(string $groupId, bool $publicOnly = false): array
    {
        $n = 100;
        $offset = 0;
        $hardCap = 2000;
        $results = [];
        $total = null;

        do {
            $path = '/groups/' . rawurlencode($groupId) . '/posts?n=' . $n . '&offset=' . $offset
                . ($publicOnly ? '&publicOnly=true' : '');

            $data = $this->authedRequest('GET', $path);
            $page = is_array($data) ? (array) ($data['posts'] ?? []) : [];
            $total ??= is_array($data) && isset($data['total']) ? (int) $data['total'] : null;

            foreach ($page as $post) {
                if (!is_array($post) || empty($post['id'])) {
                    continue;
                }
                $results[] = [
                    'id' => (string) $post['id'],
                    'title' => (string) ($post['title'] ?? ''),
                    'text' => (string) ($post['text'] ?? ''),
                    'imageId' => !empty($post['imageId']) ? (string) $post['imageId'] : null,
                    'imageUrl' => !empty($post['imageUrl']) ? (string) $post['imageUrl'] : null,
                    'visibility' => (string) ($post['visibility'] ?? 'group'),
                    'createdAt' => (string) ($post['createdAt'] ?? ''),
                    'updatedAt' => (string) ($post['updatedAt'] ?? ($post['createdAt'] ?? '')),
                ];
            }

            $offset += $n;
        } while (count($page) === $n && count($results) < $hardCap && ($total === null || count($results) < $total));

        return $results;
    }

    /**
     * Creates a new post on the group — see
     * https://vrchat.community/reference/add-group-post. `imageId` comes
     * from uploadGalleryImage() (the same upload endpoint gallery photos
     * use) if the post has an image attached.
     *
     * @return array{id: string, title: string, text: string, imageUrl: ?string, visibility: string, createdAt: string, updatedAt: string}
     */
    public function createGroupPost(string $groupId, string $title, string $text, string $visibility, bool $sendNotification, ?string $imageId = null): array
    {
        return $this->postMutation('POST', '/groups/' . rawurlencode($groupId) . '/posts', $title, $text, $visibility, $sendNotification, $imageId);
    }

    /**
     * Edits an existing post — see
     * https://vrchat.community/reference/update-group-post. VRChat's PUT
     * here fully replaces the post's editable fields (not a partial patch),
     * so callers must pass the current imageId along to keep an existing
     * image when only the text is changing.
     *
     * @return array{id: string, title: string, text: string, imageUrl: ?string, visibility: string, createdAt: string, updatedAt: string}
     */
    public function updateGroupPost(string $groupId, string $postId, string $title, string $text, string $visibility, bool $sendNotification, ?string $imageId = null): array
    {
        $path = '/groups/' . rawurlencode($groupId) . '/posts/' . rawurlencode($postId);
        return $this->postMutation('PUT', $path, $title, $text, $visibility, $sendNotification, $imageId);
    }

    private function postMutation(string $method, string $path, string $title, string $text, string $visibility, bool $sendNotification, ?string $imageId): array
    {
        $body = [
            'title' => $title,
            'text' => $text,
            'visibility' => $visibility,
            'sendNotification' => $sendNotification,
        ];
        if ($imageId !== null) {
            $body['imageId'] = $imageId;
        }

        $data = $this->authedRequest($method, $path, $body);
        if (!is_array($data) || empty($data['id'])) {
            throw new \Exception('Respons tidak terduga dari VRChat saat menyimpan post.');
        }

        return [
            'id' => (string) $data['id'],
            'title' => (string) ($data['title'] ?? ''),
            'text' => (string) ($data['text'] ?? ''),
            'imageUrl' => !empty($data['imageUrl']) ? (string) $data['imageUrl'] : null,
            'visibility' => (string) ($data['visibility'] ?? $visibility),
            'createdAt' => (string) ($data['createdAt'] ?? ''),
            'updatedAt' => (string) ($data['updatedAt'] ?? ''),
        ];
    }

    /**
     * Deletes a group post — see
     * https://vrchat.community/reference/delete-group-post.
     */
    public function deleteGroupPost(string $groupId, string $postId): void
    {
        $this->authedRequest('DELETE', '/groups/' . rawurlencode($groupId) . '/posts/' . rawurlencode($postId));
    }

    /**
     * Creates a new gallery on the group — see
     * https://vrchat.community/reference/create-group-gallery. Only `name`
     * is required; role-ID fields are left at VRChat's own defaults
     * (matching what "+ Create Gallery" does in VRChat's own group settings
     * UI when those are left blank).
     *
     * @return array{id: string, name: string}
     */
    public function createGroupGallery(string $groupId, string $name, string $description = ''): array
    {
        $body = ['name' => $name];
        if ($description !== '') {
            $body['description'] = $description;
        }

        $data = $this->authedRequest('POST', '/groups/' . rawurlencode($groupId) . '/galleries', $body);
        if (!is_array($data) || empty($data['id'])) {
            throw new \Exception('Respons tidak terduga dari VRChat saat membuat galeri baru.');
        }

        return [
            'id' => (string) $data['id'],
            'name' => (string) ($data['name'] ?? $name),
        ];
    }

    /**
     * Uploads raw image bytes to VRChat's dedicated gallery-image upload
     * endpoint and returns the resulting file ID — step one of two (step
     * two is addGroupGalleryImage()) — see
     * https://vrchat.community/reference/upload-gallery-image. VRChat
     * documents the expected field as "the binary blob of the png file", so
     * callers should normalize images to PNG before calling this.
     */
    public function uploadGalleryImage(string $pngBytes, string $filename = 'gallery.png'): string
    {
        $this->ensureLoggedIn();

        [$status, $data] = $this->multipartRequest('/gallery', $pngBytes, $filename);

        if ($status === 401) {
            $this->session = null;
            $this->persistSession(null);
            $this->ensureLoggedIn(true);
            [$status, $data] = $this->multipartRequest('/gallery', $pngBytes, $filename);
        }

        if ($status < 200 || $status >= 300 || empty($data['id'])) {
            throw new \Exception($this->extractError($data, "Gagal mengunggah gambar ke VRChat (HTTP {$status})"));
        }

        return (string) $data['id'];
    }

    /**
     * Attaches an already-uploaded file (see uploadGalleryImage()) to one
     * of the group's galleries — see
     * https://vrchat.community/reference/add-group-gallery-image.
     * Auto-approved if the acting account has manage/auto-approve rights on
     * the gallery (true for the group's own linked account), otherwise it
     * lands pending approval, same as a member submission.
     *
     * @return array{id: string, imageUrl: string, createdAt: ?string, approved: bool}
     */
    public function addGroupGalleryImage(string $groupId, string $groupGalleryId, string $fileId): array
    {
        $path = '/groups/' . rawurlencode($groupId) . '/galleries/' . rawurlencode($groupGalleryId) . '/images';
        $data = $this->authedRequest('POST', $path, ['fileId' => $fileId]);

        if (!is_array($data) || empty($data['id'])) {
            throw new \Exception('Respons tidak terduga dari VRChat saat menambahkan gambar ke galeri.');
        }

        return [
            'id' => (string) $data['id'],
            'imageUrl' => (string) ($data['imageUrl'] ?? ''),
            'createdAt' => isset($data['createdAt']) ? (string) $data['createdAt'] : null,
            'approved' => !empty($data['approved']),
        ];
    }

    /**
     * Picks the best available "picture" field from a VRChat user object —
     * a custom profile picture if they've set one, otherwise their current
     * avatar's thumbnail (what VRChat itself shows in friends lists), otherwise
     * their user icon.
     */
    private function pickAvatarUrl(array $user): ?string
    {
        foreach (['profilePicOverrideThumbnail', 'profilePicOverride', 'currentAvatarThumbnailImageUrl', 'userIcon'] as $field) {
            if (!empty($user[$field]) && is_string($user[$field])) {
                return $user[$field];
            }
        }

        return null;
    }

    /**
     * Performs an authenticated GET/POST, logging in first if needed and
     * retrying once if the cached session turns out to be stale (401).
     */
    private function authedRequest(string $method, string $path, ?array $body = null): mixed
    {
        $this->ensureLoggedIn();

        [$status, $data] = $this->rawRequest($method, $path, $body, true);

        if ($status === 401) {
            $this->session = null;
            $this->persistSession(null);
            $this->ensureLoggedIn(true);
            [$status, $data] = $this->rawRequest($method, $path, $body, true);
        }

        if ($status < 200 || $status >= 300) {
            throw new \Exception($this->extractError($data, "VRChat API error (HTTP {$status})"));
        }

        return $data;
    }

    private function ensureLoggedIn(bool $force = false): void
    {
        if (!$this->sessionLoaded && !$force) {
            $this->session = $this->loadSession();
            $this->sessionLoaded = true;
        }

        if (!$force && !empty($this->session['cookie'] ?? null)) {
            // Trust the cached session; authedRequest() forces a fresh login on 401.
            return;
        }

        $basic = base64_encode(rawurlencode($this->username) . ':' . rawurlencode($this->password));
        [$status, $data, $cookies] = $this->rawRequest('GET', '/auth/user', null, false, [
            'Authorization' => 'Basic ' . $basic,
        ]);

        if ($status < 200 || $status >= 300) {
            throw new \Exception($this->extractError($data, "Login VRChat gagal (HTTP {$status})"));
        }

        $sessionCookies = $cookies;

        if (is_array($data) && !empty($data['requiresTwoFactorAuth'])) {
            $methods = (array) $data['requiresTwoFactorAuth'];

            if (!in_array('totp', $methods, true) || !$this->totpSecret) {
                throw new \Exception('Akun VRChat memerlukan verifikasi 2FA yang tidak didukung (' . implode(', ', $methods) . '). Hanya Authenticator App (TOTP) yang didukung — isi VRCHAT_TOTP_SECRET di .env.');
            }

            $code = Totp::generate($this->totpSecret);
            [$status2, $data2, $cookies2] = $this->rawRequest(
                'POST',
                '/auth/twofactorauth/totp/verify',
                ['code' => $code],
                false,
                [],
                $sessionCookies,
            );

            if ($status2 < 200 || $status2 >= 300 || empty($data2['verified'])) {
                throw new \Exception($this->extractError($data2, 'Verifikasi 2FA VRChat (TOTP) gagal.'));
            }

            $sessionCookies = array_merge($sessionCookies, $cookies2);
        }

        $this->session = [
            'cookie' => $this->formatCookies($sessionCookies),
            'authenticated_at' => date('Y-m-d H:i:s'),
        ];
        $this->sessionLoaded = true;
        $this->persistSession($this->session);
    }

    private function loadSession(): ?array
    {
        $setting = $this->settings->findOneByKey(self::SESSION_SETTING_KEY);
        if ($setting === null) {
            return null;
        }

        $decoded = json_decode($setting->getValue(), true);
        return is_array($decoded) ? $decoded : null;
    }

    private function persistSession(?array $session): void
    {
        $value = $session === null ? '' : json_encode($session);
        $setting = $this->settings->findOneByKey(self::SESSION_SETTING_KEY);

        if ($setting === null) {
            $setting = new Setting(self::SESSION_SETTING_KEY, $value);
            $this->dm->persist($setting);
        } else {
            $setting->setValue($value);
        }

        $this->dm->flush();
    }

    /**
     * Blocks (if needed) so consecutive outbound requests to VRChat are
     * never spaced closer together than $rateLimitSeconds, then records
     * "now" as the new last-request time — called by rawRequest() and
     * multipartRequest() right before the actual HTTP call, so it covers
     * every request this client makes (login, session refresh, every real
     * API call, every page of a paginated fetch) uniformly. Uses a plain
     * read-then-write against the `settings` collection rather than an
     * atomic findAndModify: this is a courtesy throttle to avoid VRChat's
     * unofficial API flagging/blocking the account for bursty traffic, not
     * a hard concurrency guarantee, and this app's VRChat-touching traffic
     * is low-volume enough (admin actions + periodic cache refreshes) that
     * an occasional race narrowing the gap slightly is an acceptable
     * trade-off against the complexity of a real distributed lock.
     */
    private function throttle(): void
    {
        if ($this->rateLimitSeconds <= 0) {
            return;
        }

        $setting = $this->settings->findOneByKey(self::RATE_LIMIT_SETTING_KEY);
        $lastRequestAt = $setting !== null ? (float) $setting->getValue() : null;

        if ($lastRequestAt !== null) {
            $remaining = $this->rateLimitSeconds - (microtime(true) - $lastRequestAt);
            if ($remaining > 0) {
                usleep((int) round($remaining * 1_000_000));
            }
        }

        $now = (string) microtime(true);
        if ($setting === null) {
            $this->dm->persist(new Setting(self::RATE_LIMIT_SETTING_KEY, $now));
        } else {
            $setting->setValue($now);
        }
        $this->dm->flush();
    }

    private function extractError(mixed $data, string $fallback): string
    {
        if (is_array($data) && isset($data['error']['message'])) {
            return (string) $data['error']['message'];
        }

        return $fallback;
    }

    private function formatCookies(array $cookies): string
    {
        $parts = [];
        foreach ($cookies as $name => $value) {
            $parts[] = "{$name}={$value}";
        }

        return implode('; ', $parts);
    }

    /**
     * @return array{0: int, 1: mixed, 2: array<string, string>} [status, decoded JSON body, cookies set by this response]
     */
    private function rawRequest(string $method, string $path, ?array $body, bool $useSession, array $extraHeaders = [], ?array $extraCookies = null): array
    {
        $url = self::BASE_URL . $path;

        $headers = array_merge([
            'User-Agent' => $this->userAgent,
            'Accept' => 'application/json',
        ], $extraHeaders);

        $cookieHeader = ($useSession && !empty($this->session['cookie'] ?? null)) ? $this->session['cookie'] : '';
        if ($extraCookies) {
            $extra = $this->formatCookies($extraCookies);
            $cookieHeader = $cookieHeader !== '' ? "{$cookieHeader}; {$extra}" : $extra;
        }
        if ($cookieHeader !== '') {
            $headers['Cookie'] = $cookieHeader;
        }

        $options = ['headers' => $headers, 'timeout' => 15];
        if ($body !== null) {
            $options['json'] = $body;
        }

        $this->throttle();

        try {
            $response = $this->httpClient->request($method, $url, $options);
            $status = $response->getStatusCode();
            $responseHeaders = $response->getHeaders(false);
            $rawContent = $response->getContent(false);
        } catch (HttpExceptionInterface $e) {
            throw new \Exception("Gagal menghubungi VRChat API: {$e->getMessage()}");
        }

        $responseCookies = $this->extractCookies($responseHeaders['set-cookie'] ?? []);
        $decoded = json_decode($rawContent, true);

        return [$status, $decoded, $responseCookies];
    }

    /**
     * A hand-built multipart/form-data POST for the one endpoint
     * (upload-gallery-image) that needs a raw file upload rather than a
     * JSON body — rawRequest()/authedRequest() only ever send JSON, and
     * pulling in symfony/mime for a single-field, single-call upload isn't
     * worth the extra dependency. Mirrors rawRequest()'s session-cookie
     * handling but not its 401-retry (callers that need a retry, like
     * uploadGalleryImage(), do it themselves around this call).
     *
     * @return array{0: int, 1: mixed} [status, decoded JSON body]
     */
    private function multipartRequest(string $path, string $fileBytes, string $filename): array
    {
        $boundary = '----VRChessGallery' . bin2hex(random_bytes(16));
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n"
            . "Content-Type: image/png\r\n\r\n"
            . $fileBytes . "\r\n"
            . "--{$boundary}--\r\n";

        $headers = [
            'User-Agent' => $this->userAgent,
            'Accept' => 'application/json',
            'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
        ];

        $cookieHeader = !empty($this->session['cookie'] ?? null) ? $this->session['cookie'] : '';
        if ($cookieHeader !== '') {
            $headers['Cookie'] = $cookieHeader;
        }

        $this->throttle();

        try {
            $response = $this->httpClient->request('POST', self::BASE_URL . $path, [
                'headers' => $headers,
                'body' => $body,
                'timeout' => 30,
            ]);
            $status = $response->getStatusCode();
            $rawContent = $response->getContent(false);
        } catch (HttpExceptionInterface $e) {
            throw new \Exception("Gagal menghubungi VRChat API: {$e->getMessage()}");
        }

        return [$status, json_decode($rawContent, true)];
    }

    /**
     * @param string[] $setCookieHeaders
     * @return array<string, string>
     */
    private function extractCookies(array $setCookieHeaders): array
    {
        $cookies = [];
        foreach ($setCookieHeaders as $line) {
            if (preg_match('/^\s*([^=;]+)=([^;]+)/', $line, $m)) {
                $cookies[trim($m[1])] = trim($m[2]);
            }
        }

        return $cookies;
    }
}
