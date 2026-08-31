<?php

namespace VRchessIndo\Connection;

use VRchessIndo\Logic\Totp;

/**
 * Thin client for VRChat's unofficial API (documented at https://vrchat.community/,
 * hosted at api.vrchat.cloud — VRChat does not officially support or document it,
 * so endpoints and behavior may change without notice).
 *
 * Handles login (including authenticator-app TOTP 2FA), reuses the resulting
 * session cookie via injected load/save callbacks so we don't burn a fresh
 * VRChat session on every request, and exposes only the two read endpoints
 * this app needs: searching users by display name and fetching a user by ID.
 */
class VRChatClient
{
    private const BASE_URL = 'https://api.vrchat.cloud/api/1';

    private string $username;
    private string $password;
    private ?string $totpSecret;
    private string $userAgent;

    /** @var callable(): ?array{cookie: string, authenticated_at: string} */
    private $loadSession;
    /** @var callable(?array): void */
    private $saveSession;

    private ?array $session = null;
    private bool $sessionLoaded = false;

    public function __construct(
        string $username,
        string $password,
        ?string $totpSecret,
        string $userAgent,
        callable $loadSession,
        callable $saveSession
    ) {
        $this->username = $username;
        $this->password = $password;
        $this->totpSecret = $totpSecret ?: null;
        $this->userAgent = $userAgent;
        $this->loadSession = $loadSession;
        $this->saveSession = $saveSession;
    }

    /**
     * Builds a client from VRCHAT_* environment variables.
     *
     * @param callable(): ?array $loadSession Returns the last persisted session, or null.
     * @param callable(?array): void $saveSession Persists a session (or clears it, when null).
     */
    public static function fromEnv(callable $loadSession, callable $saveSession): self
    {
        $username = (string) ($_ENV['VRCHAT_USERNAME'] ?? getenv('VRCHAT_USERNAME') ?: '');
        $password = (string) ($_ENV['VRCHAT_PASSWORD'] ?? getenv('VRCHAT_PASSWORD') ?: '');
        $totpSecret = (string) ($_ENV['VRCHAT_TOTP_SECRET'] ?? getenv('VRCHAT_TOTP_SECRET') ?: '');
        $contact = (string) ($_ENV['VRCHAT_CONTACT'] ?? getenv('VRCHAT_CONTACT') ?: '');

        if (trim($username) === '' || trim($password) === '') {
            throw new \Exception('VRChat belum dikonfigurasi. Isi VRCHAT_USERNAME dan VRCHAT_PASSWORD di .env');
        }

        $userAgent = 'VRchessIndo/1.0' . ($contact !== '' ? " ({$contact})" : '');

        return new self($username, $password, $totpSecret !== '' ? $totpSecret : null, $userAgent, $loadSession, $saveSession);
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
    private function authedRequest(string $method, string $path, ?array $body = null)
    {
        $this->ensureLoggedIn();

        [$status, $data] = $this->rawRequest($method, $path, $body, true);

        if ($status === 401) {
            $this->session = null;
            ($this->saveSession)(null);
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
            $this->session = ($this->loadSession)();
            $this->sessionLoaded = true;
        }

        if (!$force && !empty($this->session['cookie'] ?? null)) {
            // Trust the cached session; authedRequest() forces a fresh login on 401.
            return;
        }

        $basic = base64_encode(rawurlencode($this->username) . ':' . rawurlencode($this->password));
        [$status, $data, $cookies] = $this->rawRequest('GET', '/auth/user', null, false, [
            'Authorization: Basic ' . $basic,
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
                $sessionCookies
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
        ($this->saveSession)($this->session);
    }

    private function extractError($data, string $fallback): string
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
            'User-Agent: ' . $this->userAgent,
            'Accept: application/json',
        ], $extraHeaders);

        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        $cookieHeader = ($useSession && !empty($this->session['cookie'] ?? null)) ? $this->session['cookie'] : '';
        if ($extraCookies) {
            $extra = $this->formatCookies($extraCookies);
            $cookieHeader = $cookieHeader !== '' ? "{$cookieHeader}; {$extra}" : $extra;
        }
        if ($cookieHeader !== '') {
            $headers[] = 'Cookie: ' . $cookieHeader;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HEADER => false,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $responseCookies = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $headerLine) use (&$responseCookies) {
            if (preg_match('/^Set-Cookie:\s*([^=;]+)=([^;]+)/i', $headerLine, $m)) {
                $responseCookies[trim($m[1])] = trim($m[2]);
            }
            return strlen($headerLine);
        });

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \Exception("Gagal menghubungi VRChat API: {$error}");
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode((string) $raw, true);
        return [$status, $decoded, $responseCookies];
    }
}
