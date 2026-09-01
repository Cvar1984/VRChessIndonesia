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
 */
class VRChatClient
{
    private const string BASE_URL = 'https://api.vrchat.cloud/api/1';
    private const string SESSION_SETTING_KEY = 'vrchat_session';

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
