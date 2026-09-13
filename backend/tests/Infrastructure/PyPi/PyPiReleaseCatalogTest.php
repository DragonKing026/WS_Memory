<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\PyPi;

use App\Domain\Dependency\CheckFailed;
use App\Infrastructure\PyPi\PyPiReleaseCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * What we are willing to offer as an update target.
 *
 * Every test here is a way of getting this wrong that a naive reading of PyPI's
 * JSON invites: taking `info.version` on trust, ranking release candidates, and —
 * the one that would actually hurt — proposing a release its own author withdrew.
 * Updating the palace touches vectors and is not casually reversible, so the cost
 * of one bad recommendation is not a rollback but a rebuild.
 *
 * The last test is the reason ReleaseCatalog throws instead of returning null: an
 * unreachable PyPI must never read as "brak nowszej wersji".
 */
final class PyPiReleaseCatalogTest extends TestCase
{
    public function testTakesTheHighestStableRelease(): void
    {
        $catalog = $this->catalogAnswering([
            // Note 3.10.0 against 3.9.0: string ordering would pick the wrong one,
            // and picking the wrong one here means never offering an upgrade again.
            'info' => ['version' => '3.9.0'],
            'releases' => [
                '3.8.0' => [$this->file()],
                '3.10.0' => [$this->file()],
                '3.9.0' => [$this->file()],
            ],
        ]);

        self::assertSame('3.10.0', (string) $catalog->latestStable('mempalace'));
    }

    public function testSkipsYankedReleases(): void
    {
        // Yanking is how an author says "do not install this one". Offering it
        // would propose exactly the install they asked nobody to do.
        $catalog = $this->catalogAnswering([
            'info' => ['version' => '3.10.0'],
            'releases' => [
                '3.9.0' => [$this->file()],
                '3.10.0' => [$this->file(yanked: true), $this->file(yanked: true)],
            ],
        ]);

        self::assertSame('3.9.0', (string) $catalog->latestStable('mempalace'));
    }

    public function testAReleaseCountsAsUsableWhileAnyOfItsFilesStands(): void
    {
        // Yanking is per file in this API. A maintainer who withdraws a broken
        // wheel and leaves the source archive has not withdrawn the release.
        $catalog = $this->catalogAnswering([
            'releases' => [
                '3.9.0' => [$this->file()],
                '3.10.0' => [$this->file(yanked: true), $this->file()],
            ],
        ]);

        self::assertSame('3.10.0', (string) $catalog->latestStable('mempalace'));
    }

    public function testSkipsAReleaseWithNoFilesAtAll(): void
    {
        // Nothing to install from, so nothing to offer — the same situation as a
        // fully yanked release, reached a different way.
        $catalog = $this->catalogAnswering([
            'releases' => [
                '3.9.0' => [$this->file()],
                '3.10.0' => [],
            ],
        ]);

        self::assertSame('3.9.0', (string) $catalog->latestStable('mempalace'));
    }

    public function testSkipsPreReleases(): void
    {
        $catalog = $this->catalogAnswering([
            'info' => ['version' => '3.11.0rc1'],
            'releases' => [
                '3.10.0' => [$this->file()],
                '3.11.0rc1' => [$this->file()],
                '3.11.0b1' => [$this->file()],
                '3.11.0.dev3' => [$this->file()],
                '3.10.1.post1' => [$this->file()],
            ],
        ]);

        self::assertSame('3.10.0', (string) $catalog->latestStable('mempalace'));
    }

    public function testIgnoresInfoVersionEntirelyWhenItPointsAtSomethingWithdrawn(): void
    {
        // `info.version` is the most recently UPLOADED release, which is a
        // different claim from "the newest one you should install".
        $catalog = $this->catalogAnswering([
            'info' => ['version' => '3.11.0'],
            'releases' => [
                '3.10.0' => [$this->file()],
                '3.11.0' => [$this->file(yanked: true)],
            ],
        ]);

        self::assertSame('3.10.0', (string) $catalog->latestStable('mempalace'));
    }

    public function testAnEmptyReleaseMapIsNullAndNotAFailure(): void
    {
        // We reached the catalogue; it holds nothing installable. That is a real
        // answer, distinct from not having reached it.
        $catalog = $this->catalogAnswering(['releases' => []]);

        self::assertNull($catalog->latestStable('mempalace'));
    }

    public function testNoNetworkIsACheckFailedAndNeverAQuietNull(): void
    {
        // The case the whole distinction exists for: a null here would reach the
        // panel as "brak nowszej wersji" while the truth is that we have no idea.
        $catalog = new PyPiReleaseCatalog(
            new MockHttpClient(static function (): MockResponse {
                throw new TransportException('Nie ma sieci.');
            }),
            'http://pypi.invalid/pypi',
        );

        $this->expectException(CheckFailed::class);
        $this->expectExceptionMessageMatches('/Nie udało się odpytać PyPI/');

        $catalog->latestStable('mempalace');
    }

    public function testAnUnknownPackageIsAFailureAndNotAnAbsenceOfReleases(): void
    {
        // A 404 means we asked the wrong question — most likely a typo in
        // configuration. Answering null would hide it until somebody looked.
        $catalog = new PyPiReleaseCatalog(
            new MockHttpClient(new MockResponse('{"message": "Not Found"}', ['http_code' => 404])),
            'http://pypi.invalid/pypi',
        );

        $this->expectException(CheckFailed::class);

        $catalog->latestStable('mempalace-typo');
    }

    public function testAnAnswerThatIsNotTheExpectedShapeIsAFailure(): void
    {
        $catalog = new PyPiReleaseCatalog(
            new MockHttpClient(new MockResponse('{"info": {"version": "3.9.0"}}')),
            'http://pypi.invalid/pypi',
        );

        $this->expectException(CheckFailed::class);
        $this->expectExceptionMessageMatches('/brak pola releases/');

        $catalog->latestStable('mempalace');
    }

    public function testAsksTheJsonApiForThePackageItWasGiven(): void
    {
        $urls = [];
        $http = new MockHttpClient(function (string $method, string $url) use (&$urls): MockResponse {
            $urls[] = $method . ' ' . $url;

            return new MockResponse((string) json_encode(['releases' => ['3.9.0' => [$this->file()]]]));
        });

        (new PyPiReleaseCatalog($http, 'https://pypi.org/pypi/'))->latestStable('mempalace');

        self::assertSame(['GET https://pypi.org/pypi/mempalace/json'], $urls);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function catalogAnswering(array $payload): PyPiReleaseCatalog
    {
        return new PyPiReleaseCatalog(
            new MockHttpClient(new MockResponse((string) json_encode($payload))),
            'http://pypi.invalid/pypi',
        );
    }

    /**
     * One entry of a release's file list, trimmed to the field we read.
     *
     * @return array<string, mixed>
     */
    private function file(bool $yanked = false): array
    {
        return [
            'filename' => 'mempalace-x.y.z-py3-none-any.whl',
            'packagetype' => 'bdist_wheel',
            'yanked' => $yanked,
        ];
    }
}
