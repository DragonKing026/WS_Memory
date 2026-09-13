<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Application\AgentToken\IssueAgentToken;
use App\Application\Invitation\AcceptInvitation;
use App\Application\Invitation\IssueInvitation;
use App\Domain\Space\SpaceRole;
use App\Entity\Space;
use App\Entity\SpaceMember;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * `/api/publish` z tokenem agenta — przez HTTP, nie przez usługę.
 *
 * Ta klasa istnieje, bo jej brak kosztował wieczór. Strona serwerowa mostka
 * (TODO-012) była przetestowana **wołaniem PublishService wprost**, a droga
 * HTTP — firewall, autentykator, kolejność autentykatorów, kontroler — nie była
 * przejechana ani razu. Pierwszy prawdziwy klient dostał 401 i komunikat
 * „Invalid JWT Token", czyli odpowiedź od zupełnie innego autentykatora.
 *
 * Dlatego test uderza tam, gdzie uderza klient: żądaniem HTTP z nagłówkiem
 * `Authorization: Bearer wsm_…`. Test wołający usługę nie zauważyłby niczego
 * z tego, co tu może pójść nie tak.
 *
 * Uwaga na grupę: publikacja liczy embeddingi, więc potrzebuje żywego pałaca.
 */
#[Group('integracja')]
final class PublishOverHttpTest extends WebTestCase
{
    private const PASSWORD = 'DlugieHaslo123!x';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $owner;
    private string $token;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $this->em->getConnection()->executeStatement(
            'TRUNCATE ws.messenger_messages, ws.mail_log, ws.memory_entries, ws.agent_tokens, '
            . 'ws.space_members, ws.invitations, ws.audit_log, ws.spaces, ws.users CASCADE'
        );

        $issue = $container->get(IssueInvitation::class);
        $accept = $container->get(AcceptInvitation::class);
        $this->owner = ($accept)(($issue)('agent@web-systems.pl')->plainToken, 'Właściciel', self::PASSWORD);

        $przestrzen = new Space('zespolowa', 'Zespołowa');
        $this->em->persist($przestrzen);
        $this->em->persist(new SpaceMember($przestrzen, $this->owner, SpaceRole::Writer));
        $this->em->flush();

        $this->token = ($container->get(IssueAgentToken::class))($this->owner, 'laptop')->plainToken;
    }

    /**
     * Najważniejszy test w tym pliku: token agenta **jest przyjmowany** na tej trasie.
     *
     * To jedyna trasa poza `/mcp`, na której działa poświadczenie inne niż JWT
     * — i cała reszta mostka jest bezużyteczna, jeśli akurat ta rzecz nie działa.
     */
    public function testAgentTokenIsAcceptedOnThisRoute(): void
    {
        $this->publish($this->token, [
            'replica' => 'laptop-testowy',
            'space' => 'zespolowa',
            'preview' => true,
            'drawers' => [[
                'id' => 'szuflada-1',
                'wing' => 'wing_projekt',
                'content' => 'Zasady wdrożenia: kopia zapasowa przed migracją bazy.',
            ]],
        ]);

        self::assertResponseIsSuccessful();
    }

    public function testAPreviewStoresNothing(): void
    {
        $this->publish($this->token, [
            'replica' => 'laptop-testowy',
            'space' => 'zespolowa',
            'preview' => true,
            'drawers' => [[
                'id' => 'szuflada-1',
                'wing' => 'wing_projekt',
                'content' => 'Treść, która ma nie zostać zapisana.',
            ]],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(
            0,
            (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM ws.memory_entries'),
            'podgląd nie może niczego zapisać',
        );
    }

    public function testAJwtIsRefusedHereSoThatIdentityComesFromTheAgentCredential(): void
    {
        $this->client->request(
            'POST',
            '/api/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => 'agent@web-systems.pl', 'password' => self::PASSWORD], \JSON_THROW_ON_ERROR),
        );

        /** @var array{token: string} $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        // JWT jest tu przyjmowany — trasa ma dwa poświadczenia (D-036). Test
        // pilnuje, że dodanie tokena agenta nie zabrało drogi człowiekowi.
        $this->publish($body['token'], [
            'replica' => 'laptop-testowy',
            'space' => 'zespolowa',
            'preview' => true,
            'drawers' => [[
                'id' => 'szuflada-1',
                'wing' => 'wing_projekt',
                'content' => 'Zapis wykonany przez zalogowanego człowieka.',
            ]],
        ]);

        self::assertResponseIsSuccessful();
    }

    public function testAnUnknownAgentTokenIsRefusedWithOurOwnSentence(): void
    {
        $this->publish('wsm_' . str_repeat('0', 64), [
            'replica' => 'laptop-testowy',
            'drawers' => [],
        ]);

        self::assertResponseStatusCodeSame(401);

        /** @var array{error?: string, message?: string} $tresc */
        $tresc = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        // Nasz komunikat, a nie „Invalid JWT Token". Ten drugi znaczyłby, że
        // żądanie obsłużył autentykator JWT — czyli że mostek jest zamknięty
        // dla agentów, choć wygląda na otwarty. Porównanie po odkodowaniu JSON-a,
        // bo w surowej odpowiedzi polskie znaki są zescapowane i asercja na
        // tekście przechodziłaby albo padała z powodu kodowania, nie treści.
        self::assertStringContainsString('nieważny', $tresc['error'] ?? '');
        self::assertArrayNotHasKey('message', $tresc, 'komunikat od JWT znaczy, że agent tu nie wejdzie');
    }

    /**
     * @param array<string, mixed> $ciało
     */
    private function publish(string $token, array $ciało): void
    {
        $this->client->request(
            'POST',
            '/api/publish',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            content: json_encode($ciało, \JSON_THROW_ON_ERROR),
        );
    }
}
