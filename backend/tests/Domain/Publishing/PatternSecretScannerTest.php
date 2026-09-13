<?php

declare(strict_types=1);

namespace App\Tests\Domain\Publishing;

use App\Domain\Publishing\PatternSecretScanner;
use App\Domain\Publishing\SecretKind;
use PHPUnit\Framework\TestCase;

/**
 * The filter that sits on every publication path.
 *
 * This file is the most important test in TODO-012 and the reason is D-014:
 * sending is the default, so nobody reads what goes out. A miss here writes a
 * private key into a shared, searchable, backed-up database — and deleting it
 * afterwards does not unsee it.
 *
 * Which is why the suite is deliberately two-sided. Proving that a private key
 * is refused is easy and half the job; the other half is proving that ordinary
 * content, and specifically documentation ABOUT secrets, still gets through. A
 * filter that refuses `.env.example` is a filter people switch off.
 *
 * No fixture in this file contains a real credential. The token shapes are
 * synthetic — correct prefixes, filler bodies — because a test suite is a public
 * file in a public repository, and a secret written down to prove we detect
 * secrets is the joke that writes itself.
 */
final class PatternSecretScannerTest extends TestCase
{
    private PatternSecretScanner $scanner;

    protected function setUp(): void
    {
        $this->scanner = new PatternSecretScanner();
    }

    // ------------------------------------------------------------ refusals

    public function testTheBodyOfAnEnvironmentFileIsRefused(): void
    {
        $report = $this->scanner->scan(<<<'ENV'
            APP_ENV=prod
            APP_SECRET=3f8a2c91d04b7e65a1f0
            WS_DB_PASSWORD=Kf7pQ2mZ9xL1
            MEMPALACE_URL=http://mempalace:8765
            ENV);

        self::assertFalse($report->isClean());
        self::assertContains(SecretKind::EnvFile, $this->kinds($report->findings));
    }

    /**
     * The name alone decides it. A file called `.env` is not published whatever
     * happens to be inside it today.
     */
    public function testAFileNamedEnvIsRefusedOnItsNameAlone(): void
    {
        $report = $this->scanner->scan("# nothing interesting here\n", '/home/kto/projekt/.env');

        self::assertFalse($report->isClean());
        self::assertSame([SecretKind::EnvFile], $this->kinds($report->findings));
    }

    public function testAnEnvironmentFileVariantIsRefusedToo(): void
    {
        $report = $this->scanner->scan("cokolwiek\n", 'projekt/.env.production.local');

        self::assertFalse($report->isClean(), '.env.production.local jest plikiem z sekretami');
    }

    public function testAPrivateKeyIsRefused(): void
    {
        $report = $this->scanner->scan(<<<'PEM'
            Klucz wdrożeniowy:
            -----BEGIN RSA PRIVATE KEY-----
            MIIEowIBAAKCAQEAtestowatrescktoranieniejestkluczem
            -----END RSA PRIVATE KEY-----
            PEM);

        self::assertSame([SecretKind::PrivateKey], $this->kinds($report->findings));
        self::assertSame(2, $report->findings[0]->line, 'wiersz liczony od jedynki, jak w edytorze');
    }

    public function testAnOpenSshPrivateKeyIsRefusedAsWell(): void
    {
        $report = $this->scanner->scan("-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaA\n");

        self::assertFalse($report->isClean());
    }

    public function testAPasswordInsideAUrlIsRefused(): void
    {
        $report = $this->scanner->scan('Połączenie: postgresql://ws_app:Kf7pQ2mZ9xL1@postgres:5432/ws_memory');

        self::assertContains(SecretKind::UrlPassword, $this->kinds($report->findings));
    }

    /**
     * The word for "password" inside a password must not read as a placeholder.
     *
     * Written down because the placeholder list is what makes this filter usable,
     * and the obvious mistake when writing that list is to put `hasło` on it.
     */
    public function testAPasswordThatContainsTheWordPasswordIsStillAPassword(): void
    {
        $report = $this->scanner->scan('ssh://admin:hasloAdmina9@10.0.0.4/');

        self::assertContains(SecretKind::UrlPassword, $this->kinds($report->findings));
    }

    /**
     * @param non-empty-string $token
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('tokenShapes')]
    public function testACredentialWithAKnownPrefixIsRefused(string $label, string $token): void
    {
        $report = $this->scanner->scan("Konfiguracja klienta:\nklucz = {$token}\n");

        self::assertContains(
            SecretKind::ApiToken,
            $this->kinds($report->findings),
            \sprintf('nie rozpoznano tokena: %s', $label),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function tokenShapes(): iterable
    {
        // Synthetic bodies with the real prefixes — see the class docblock.
        yield 'GitHub PAT' => ['ghp_', 'ghp_0123456789abcdefghijklmnopqrstuvwxyz'];
        yield 'GitHub fine-grained' => ['github_pat_', 'github_pat_11ABCDEFG0abcdefghij_KLMNOPQRSTUVWX'];
        yield 'OpenAI' => ['sk-', 'sk-abcdefghij0123456789ABCDEFGHIJ'];
        yield 'token agenta WS_Memory' => ['wsm_', 'wsm_0123456789abcdef0123456789abcdef'];
        yield 'AWS' => ['AKIA', 'AKIAIOSFODNN7EXAMPLE'];
        yield 'JWT' => ['eyJ', 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dBjftJeZ4CVPmB92K27uhbUJU1p1r'];
    }

    public function testASecretBearingNameWithARealValueIsRefused(): void
    {
        $report = $this->scanner->scan("Ustawienia workera:\nsmtp_password: Kf7pQ2mZ9xL1\nsmtp_host: localhost\n");

        self::assertContains(SecretKind::CredentialAssignment, $this->kinds($report->findings));
    }

    public function testEveryReasonIsReportedSoTheAuthorKnowsWhatToFix(): void
    {
        $report = $this->scanner->scan(<<<'TXT'
            -----BEGIN EC PRIVATE KEY-----
            MHcCAQEEIGtestowa
            -----END EC PRIVATE KEY-----
            DATABASE_URL=postgresql://ws_app:Kf7pQ2mZ9xL1@postgres:5432/ws
            TXT);

        self::assertContains(SecretKind::PrivateKey, $this->kinds($report->findings));
        self::assertContains(SecretKind::UrlPassword, $this->kinds($report->findings));
        self::assertStringContainsString('klucz prywatny', $report->describe());
        self::assertStringContainsString('hasło w adresie URL', $report->describe());
    }

    /**
     * The report travels into `publish_batches.skipped_reasons` and onto a screen.
     * A filter that copies the secret into its own report has moved it, not
     * stopped it — and moved it somewhere nobody searches for secrets.
     */
    public function testTheReportRepeatsNoneOfTheSecretItFound(): void
    {
        $report = $this->scanner->scan('postgresql://ws_app:Kf7pQ2mZ9xL1@postgres:5432/ws');

        $serialised = json_encode($report->toArray(), \JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('Kf7pQ2mZ9xL1', $serialised);
        self::assertStringNotContainsString('ws_app', $serialised);
    }

    // ------------------------------------------------------------ passages

    public function testOrdinaryProseGoesThrough(): void
    {
        $report = $this->scanner->scan(<<<'TXT'
            Ustaliliśmy, że publikacja idzie zawsze przez API, nigdy wprost do bazy.
            Token agenta dziedziczy uprawnienia właściciela i nigdy nie ma ich więcej.
            TXT);

        self::assertTrue($report->isClean(), $report->describe());
    }

    /**
     * Mined source code is the main volume of this system (D-012). A filter that
     * trips on it refuses almost everything.
     */
    public function testMinedSourceCodeGoesThrough(): void
    {
        $report = $this->scanner->scan(<<<'PHP'
            final readonly class SpaceId implements \Stringable
            {
                public const PRIVATE_PREFIX = 'priv_';

                public function __construct(public string $value)
                {
                    if ('' === trim($value)) {
                        throw new \InvalidArgumentException('A space identifier cannot be empty.');
                    }
                }
            }
            PHP);

        self::assertTrue($report->isClean(), $report->describe());
    }

    /**
     * The example file exists to be read. Refusing it would refuse the one
     * document that tells people where the real values go.
     */
    public function testAnExampleEnvironmentFileGoesThrough(): void
    {
        $report = $this->scanner->scan(<<<'ENV'
            APP_SECRET=ustaw-w-compose
            WS_DB_PASSWORD=USTAW_W_COMPOSE
            DATABASE_URL=postgresql://ws_app:USTAW_W_COMPOSE@postgres:5432/ws_memory
            MEMPALACE_MCP_HTTP_TOKEN=${MEMPALACE_TOKEN}
            ENV, 'projekt/.env.example');

        self::assertTrue($report->isClean(), $report->describe());
    }

    /**
     * Documentation quoting a couple of configuration lines is documentation, not
     * a configuration file. The structural test — what proportion of the lines are
     * assignments — is what tells the two apart.
     */
    public function testDocumentationQuotingConfigurationGoesThrough(): void
    {
        $report = $this->scanner->scan(<<<'MD'
            ## Konfiguracja usługi embeddings

            Model jest jeden dla całego systemu i nie zmienia się bez migracji, bo
            zmiana unieważnia wszystkie wektory w bazie (D-003). Ustawia się go tak:

                EMBEDDINGS_MODEL=BAAI/bge-m3
                EMBEDDINGS_DIM=1024

            Po zmianie trzeba przeliczyć całą bazę od nowa, co przy kilkuset
            tysiącach szuflad zajmuje godziny — dlatego jest to decyzja, nie
            ustawienie. Wartości powyżej odpowiadają obrazowi z docker-compose.
            MD);

        self::assertTrue($report->isClean(), $report->describe());
    }

    public function testProseThatMentionsATokenWithoutQuotingOneGoesThrough(): void
    {
        $report = $this->scanner->scan('Token: wygasa po ośmiu godzinach, czyli po dniu pracy (D-017).');

        self::assertTrue($report->isClean(), $report->describe());
    }

    public function testAUrlWithoutAPasswordGoesThrough(): void
    {
        $report = $this->scanner->scan("Panel: https://wiedza.web-systems.pl/spaces/kadry\nRedis: redis://cache:6379/0");

        self::assertTrue($report->isClean(), $report->describe());
    }

    public function testAShortExampleValueIsNotTreatedAsACredential(): void
    {
        $report = $this->scanner->scan('password: abc');

        self::assertTrue($report->isClean(), 'trzy znaki to przykład, nie hasło');
    }

    public function testEmptyContentIsClean(): void
    {
        self::assertTrue($this->scanner->scan('')->isClean());
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param list<\App\Domain\Publishing\SecretFinding> $findings
     *
     * @return list<SecretKind>
     */
    private function kinds(array $findings): array
    {
        return array_values(array_unique(array_map(
            static fn (\App\Domain\Publishing\SecretFinding $finding): SecretKind => $finding->kind,
            $findings,
        ), \SORT_REGULAR));
    }
}
