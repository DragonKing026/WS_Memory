<?php

declare(strict_types=1);

namespace App\Infrastructure\PyPi;

use App\Domain\Dependency\CheckFailed;
use App\Domain\Dependency\ReleaseCatalog;
use App\Domain\Dependency\Version;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Adapter: the newest release of a Python package, from PyPI's JSON API.
 *
 * Two decisions here are the difference between a useful panel and a harmful one.
 *
 * **`info.version` is not consulted.** It is the most recently UPLOADED release,
 * which is usually the newest one and is not the same claim — a maintainer
 * publishing a patch to an old branch moves it backwards, and a maintainer who
 * yanks their latest release does not move it at all. So the whole `releases` map
 * is walked and the maximum is taken by numeric comparison.
 *
 * **A yanked release is skipped.** Yanking is how an author says "this one is
 * broken, do not install it" without breaking anyone who pinned it. Offering it as
 * an upgrade target would propose exactly the install the author asked nobody to
 * do — and for MemPalace the install is irreversible in practice, because updating
 * the palace touches vectors. Yanking is per FILE in this API, so a release counts
 * as withdrawn when every one of its files is yanked; a release with no files at
 * all is skipped for the same reason, as nothing can be installed from it.
 *
 * Pre-releases fall out for free: Version::tryParse refuses them, so `3.10.0rc1`
 * is one of the nulls this loop steps over.
 *
 * The timeout is short because this runs behind an administrator pressing a button
 * as well as behind the scheduler. A check that hangs for a minute is worse than
 * one that fails in five seconds: the failure is visible and says to try again,
 * the hang looks like a broken panel.
 */
final readonly class PyPiReleaseCatalog implements ReleaseCatalog
{
    private const DEFAULT_BASE_URL = 'https://pypi.org/pypi';

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $baseUrl = self::DEFAULT_BASE_URL,
        private float $timeoutSeconds = 8.0,
    ) {
    }

    public function latestStable(string $package): ?Version
    {
        $newest = null;

        foreach ($this->releases($package) as $release => $files) {
            $version = Version::tryParse($release);
            if (null === $version) {
                // A pre-release, a post-release, or a spelling we refuse to rank.
                continue;
            }

            if ($this->isWithdrawn($files)) {
                continue;
            }

            if (null === $newest || $version->isNewerThan($newest)) {
                $newest = $version;
            }
        }

        return $newest;
    }

    /**
     * @return array<string, mixed> the `releases` map: version string => list of files
     *
     * @throws CheckFailed
     */
    private function releases(string $package): array
    {
        $url = \sprintf('%s/%s/json', rtrim($this->baseUrl, '/'), rawurlencode($package));

        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => $this->timeoutSeconds,
                'headers' => ['Accept' => 'application/json'],
            ]);

            $status = $response->getStatusCode();
            if (200 !== $status) {
                // 404 included: an unknown package is not "no releases", it is a
                // question we got wrong, and silently answering null would hide
                // a typo in configuration for as long as nobody looked.
                throw CheckFailed::catalogAnswered($package, $status);
            }

            $body = $response->getContent(throw: false);
        } catch (CheckFailed $e) {
            throw $e;
        } catch (HttpExceptionInterface $e) {
            throw CheckFailed::catalogUnreachable($package, $e);
        }

        try {
            /** @var mixed $payload */
            $payload = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw CheckFailed::catalogMalformed($package, 'odpowiedź nie jest JSON-em: ' . $e->getMessage());
        }

        if (!\is_array($payload) || !\is_array($payload['releases'] ?? null)) {
            throw CheckFailed::catalogMalformed($package, 'brak pola releases');
        }

        /** @var array<string, mixed> $releases */
        $releases = $payload['releases'];

        return $releases;
    }

    /**
     * Whether nothing installable is left in this release.
     *
     * @param mixed $files the value PyPI put under one version key
     */
    private function isWithdrawn(mixed $files): bool
    {
        if (!\is_array($files) || [] === $files) {
            return true;
        }

        foreach ($files as $file) {
            if (\is_array($file) && true !== ($file['yanked'] ?? false)) {
                return false;
            }
        }

        return true;
    }
}
