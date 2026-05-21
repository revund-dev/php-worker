<?php

declare(strict_types=1);

namespace Revund\PHPWorker;

/**
 * Fetcher clones the repo into a local cache directory when the
 * worker is dispatched in self-fetch mode (RepoSource on the
 * request). Returns the absolute path to the cached checkout so the
 * rest of the worker (Parser) can operate against it exactly as if
 * the bot had cloned it.
 *
 * # Cache layout
 *
 *   $REVUND_WORKER_CACHE_DIR/<sha256(url@ref)>/
 *
 * Default cache dir is `/var/cache/revund-worker`. The hash key
 * includes both URL and ref so two reviews targeting different
 * commits of the same repo share nothing — keeps tenant blast-
 * radius to one cache entry.
 *
 * # Token hygiene (security)
 *
 * The token is used at clone time only:
 *
 *   1. Compose the authenticated URL via x-access-token convention.
 *   2. Run `git clone --filter=blob:none --no-checkout <auth-url>`.
 *   3. Immediately rewrite the remote URL to the un-authenticated
 *      form via `git remote set-url`. After this step the on-disk
 *      .git/config carries no token.
 *   4. Fetch the requested ref and check it out.
 *
 * Errors and log messages NEVER include the URL with the embedded
 * token; the sanitizer strips it before throwing.
 */
final class Fetcher
{
    private const DEFAULT_CACHE_DIR = '/var/cache/revund-worker';
    private const DEFAULT_IDLE_TTL_MS = 600_000; // 10 min

    /**
     * @var array<string, int>  cache-dir → last-touched epoch ms
     */
    private static array $lastTouched = [];

    /**
     * Resolve the local checkout for the given source. Clones if
     * cold, returns the cached path if warm.
     *
     * @param array{url:string, ref:string, auth_token:string, auth_user?:string} $src
     */
    public static function fetchOrCache(array $src): string
    {
        if (($src['url'] ?? '') === '') {
            throw new \RuntimeException('fetcher: repo_source.url is required');
        }
        if (($src['ref'] ?? '') === '') {
            throw new \RuntimeException('fetcher: repo_source.ref is required');
        }

        $cacheDir = getenv('REVUND_WORKER_CACHE_DIR') ?: self::DEFAULT_CACHE_DIR;
        if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0o755, true) && !is_dir($cacheDir)) {
            throw new \RuntimeException("fetcher: cannot create cache dir {$cacheDir}");
        }

        $key = self::cacheKey($src['url'], $src['ref']);
        $repoDir = $cacheDir . DIRECTORY_SEPARATOR . $key;

        if (is_dir($repoDir . DIRECTORY_SEPARATOR . '.git')) {
            self::touch($repoDir);
            return $repoDir;
        }

        $cleanURL = $src['url'];
        $authURL = self::injectToken(
            $cleanURL,
            $src['auth_token'] ?? '',
            $src['auth_user'] ?? '',
        );
        if (!@mkdir($repoDir, 0o755, true) && !is_dir($repoDir)) {
            throw new \RuntimeException('fetcher: cannot create repo dir');
        }

        self::run('git', ['clone', '--filter=blob:none', '--no-checkout', $authURL, $repoDir]);

        // Strip the token BEFORE doing anything else. From this
        // point the on-disk state contains no token.
        self::run('git', ['-C', $repoDir, 'remote', 'set-url', 'origin', $cleanURL]);

        self::run('git', ['-C', $repoDir, 'fetch', 'origin', $src['ref']]);
        self::run('git', ['-C', $repoDir, 'checkout', $src['ref']]);

        self::touch($repoDir);

        // Lazy eviction so cold entries don't linger. Best-effort.
        self::evictIdle($cacheDir);

        return $repoDir;
    }

    private static function cacheKey(string $url, string $ref): string
    {
        return substr(hash('sha256', $url . '@' . $ref), 0, 32);
    }

    private static function touch(string $dir): void
    {
        self::$lastTouched[$dir] = (int) (microtime(true) * 1000);
    }

    private static function evictIdle(string $cacheDir): void
    {
        $ttlEnv = (int) (getenv('REVUND_WORKER_CACHE_TTL_SEC') ?: 0);
        $ttlMs = $ttlEnv > 0 ? $ttlEnv * 1000 : self::DEFAULT_IDLE_TTL_MS;
        $cutoff = (int) (microtime(true) * 1000) - $ttlMs;

        $entries = @scandir($cacheDir) ?: [];
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $full = $cacheDir . DIRECTORY_SEPARATOR . $e;
            $t = self::$lastTouched[$full] ?? null;
            if ($t === null) {
                $stat = @stat($full);
                $t = $stat !== false ? (int) ($stat['mtime'] * 1000) : (int) (microtime(true) * 1000);
            }
            if ($t > $cutoff) {
                continue;
            }
            self::rmrf($full);
            unset(self::$lastTouched[$full]);
        }
    }

    private static function rmrf(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $e) {
                if ($e !== '.' && $e !== '..') {
                    self::rmrf($path . DIRECTORY_SEPARATOR . $e);
                }
            }
            @rmdir($path);
            return;
        }
        @unlink($path);
    }

    /**
     * Compose the authenticated clone URL by inserting a basic-auth
     * pair into the https URL. The username comes from
     * RepoSource.auth_user; when empty it defaults to
     * "x-access-token" (GitHub). Other platforms set it explicitly:
     * GitLab → "oauth2", Bitbucket → "x-token-auth".
     *
     * When token is empty the URL passes through untouched (caller
     * already embedded credentials upstream).
     */
    private static function injectToken(string $cloneURL, string $token, string $user = ''): string
    {
        if ($token === '') {
            return $cloneURL;
        }
        $prefix = 'https://';
        if (strncmp($cloneURL, $prefix, strlen($prefix)) !== 0) {
            return $cloneURL;
        }
        $u = $user !== '' ? $user : 'x-access-token';
        return $prefix . $u . ':' . $token . '@' . substr($cloneURL, strlen($prefix));
    }

    /**
     * Run a command, capture stderr, throw a sanitized error on
     * non-zero exit. The thrown message NEVER contains an auth URL.
     *
     * @param array<int, string> $args
     */
    private static function run(string $bin, array $args): void
    {
        $cmd = array_merge([$bin], $args);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            throw new \RuntimeException("fetcher: {$bin} could not start");
        }
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($proc);
        if ($status !== 0) {
            $redactedArgs = self::redactArgs($args);
            throw new \RuntimeException(sprintf(
                'fetcher: %s %s exited %d: %s',
                $bin,
                implode(' ', $redactedArgs),
                $status,
                self::redact($stderr),
            ));
        }
    }

    /**
     * @param array<int, string> $args
     * @return array<int, string>
     */
    private static function redactArgs(array $args): array
    {
        return array_map(static function (string $a): string {
            return self::looksAuthenticated($a) ? self::redact($a) : $a;
        }, $args);
    }

    private static function looksAuthenticated(string $s): bool
    {
        return (bool) preg_match('#^https?://[^/@]+:[^/@]+@#', $s);
    }

    private static function redact(string $s): string
    {
        return (string) preg_replace('#(https?://)[^/@\s]+:[^/@\s]+@#', '$1[redacted]@', $s);
    }
}
