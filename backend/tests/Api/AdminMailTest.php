<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Application\Invitation\AcceptInvitation;
use App\Application\Invitation\IssueInvitation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The two mail screens: the wording, and the journal of what went out.
 *
 * Four of these are the ones worth keeping if the rest have to go.
 *
 *   - **a template engine construct survives a round trip through the editor.** Typed
 *     in, saved, read back and previewed, `{% if %}` and `{{ 7 * 7 }}` are still the
 *     characters somebody typed. This is the claim that makes an administrator-editable
 *     template safe, and it is the claim a future "let's just use Twig, it has a
 *     sandbox" would quietly break;
 *   - **`{{ link }}` cannot be put in a subject.** Subjects are stored in the journal,
 *     so "the journal holds no token" is true only while that refusal holds;
 *   - **no route creates or deletes a template.** The set of templates is a set of
 *     places in the code, so their absence is asserted rather than assumed;
 *   - **the test send goes to the administrator's own address**, whatever the request
 *     says. A form that accepted a recipient would be an open relay with editable text.
 *
 * The wording is restored after every test. `ws.mail_templates` holds installation data
 * seeded by a migration rather than fixtures, so it is deliberately not truncated in
 * setUp — and without putting it back, the wording one test saves would leak into the
 * next, failing whichever test PHPUnit happened to run afterwards.
 */
final class AdminMailTest extends WebTestCase
{
    private const PASSWORD = 'DlugieHaslo123!x';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    /** @var list<array<string, mixed>> */
    private array $seeded = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $this->em->getConnection()->executeStatement(
            'TRUNCATE ws.messenger_messages, ws.mail_log, ws.agent_tokens, ws.space_members, '
            . 'ws.invitations, ws.audit_log, ws.spaces, ws.users CASCADE'
        );

        $this->seeded = $this->em->getConnection()->fetchAllAssociative(
            'SELECT template_key, subject, body FROM ws.mail_templates'
        );

        $issue = $container->get(IssueInvitation::class);
        $accept = $container->get(AcceptInvitation::class);

        ($accept)(($issue)('piszacy@web-systems.pl')->plainToken, 'Jan Piszący', self::PASSWORD);
        ($accept)(
            ($issue)('admin@web-systems.pl', grantsGlobalAdmin: true)->plainToken,
            'Artur Ograbek',
            self::PASSWORD,
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->seeded as $template) {
            $this->em->getConnection()->executeStatement(
                'UPDATE ws.mail_templates SET subject = :subject, body = :body, '
                . 'updated_at = NOW(), updated_by_email = NULL WHERE template_key = :key',
                [
                    'subject' => $template['subject'],
                    'body' => $template['body'],
                    'key' => $template['template_key'],
                ],
            );
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------- dostęp

    public function testOrdinaryUserCannotReadTheTemplates(): void
    {
        $this->get('/api/admin/mail-templates', $this->tokenFor('piszacy@web-systems.pl'));

        self::assertResponseStatusCodeSame(403);
        self::assertStringContainsString('administratora', $this->json()['error']);
    }

    public function testOrdinaryUserCannotEditATemplate(): void
    {
        $this->putJson(
            '/api/admin/mail-templates/invitation',
            ['subject' => 'Cokolwiek', 'body' => 'Cokolwiek'],
            $this->tokenFor('piszacy@web-systems.pl'),
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testOrdinaryUserCannotReadTheMailJournal(): void
    {
        $this->get('/api/admin/mail-log', $this->tokenFor('piszacy@web-systems.pl'));

        self::assertResponseStatusCodeSame(403);
    }

    // ---------------------------------------------------------------- szablony

    public function testTheListCarriesTheClosedListOfPlacesAndAPreview(): void
    {
        $this->get('/api/admin/mail-templates', $this->adminToken());

        self::assertResponseIsSuccessful();
        $templates = $this->json()['templates'];

        self::assertCount(1, $templates);
        $invitation = $templates[0];

        self::assertSame('invitation', $invitation['key']);
        self::assertContains('link', $invitation['placeholders']);
        self::assertSame(['link'], $invitation['sensitive']);
        // The preview is rendered, so the screen shows a message rather than a template.
        self::assertStringNotContainsString('{{', $invitation['preview']['body']);
        // And on sample values: the link in a preview must not be a usable token.
        self::assertDoesNotMatchRegularExpression(
            '#/zaproszenie/[0-9a-f]{64}#',
            $invitation['preview']['body'],
        );
    }

    public function testSavingChangesTheWording(): void
    {
        $this->putJson('/api/admin/mail-templates/invitation', [
            'subject' => 'Zapraszamy do {{ instancja }}',
            'body' => 'Wejdź na {{ link }}, {{ adres_email }}.',
        ], $this->adminToken());

        self::assertResponseIsSuccessful();
        self::assertSame('Zapraszamy do {{ instancja }}', $this->json()['template']['subject']);
        self::assertSame('admin@web-systems.pl', $this->json()['template']['updatedByEmail']);

        $this->get('/api/admin/mail-templates', $this->adminToken());
        self::assertSame('Zapraszamy do {{ instancja }}', $this->json()['templates'][0]['subject']);
    }

    public function testChangingATemplateIsAudited(): void
    {
        $this->putJson('/api/admin/mail-templates/invitation', [
            'subject' => 'Zapraszamy do {{ instancja }}',
            'body' => 'Wejdź na {{ link }}.',
        ], $this->adminToken());

        self::assertResponseIsSuccessful();

        $trail = (string) $this->em->getConnection()->fetchOne(
            "SELECT coalesce(string_agg(action, ' '), '') FROM ws.audit_log"
        );

        self::assertStringContainsString('mail_template.changed', $trail);
    }

    public function testAnUnknownPlaceIsRefusedWithTheAllowedOnesNamed(): void
    {
        $this->putJson('/api/admin/mail-templates/invitation', [
            'subject' => 'Zaproszenie',
            'body' => 'Cześć {{ nieistniejace }}',
        ], $this->adminToken());

        self::assertResponseStatusCodeSame(422);
        $error = $this->json()['error'];

        self::assertStringContainsString('{{ nieistniejace }}', $error);
        // The way out has to be in the message too, not only the mistake.
        self::assertStringContainsString('{{ link }}', $error);

        // And nothing was saved.
        $this->get('/api/admin/mail-templates', $this->adminToken());
        self::assertStringNotContainsString('nieistniejace', $this->json()['templates'][0]['body']);
    }

    public function testASensitivePlaceIsRefusedInTheSubject(): void
    {
        $this->putJson('/api/admin/mail-templates/invitation', [
            'subject' => 'Twój link: {{ link }}',
            'body' => 'Wejdź na {{ link }}.',
        ], $this->adminToken());

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('dziennik', $this->json()['error']);
    }

    public function testABlankSubjectIsRefused(): void
    {
        $this->putJson('/api/admin/mail-templates/invitation', [
            'subject' => '   ',
            'body' => 'Wejdź na {{ link }}.',
        ], $this->adminToken());

        self::assertResponseStatusCodeSame(422);
    }

    public function testMissingFieldsAreRefusedAsSuch(): void
    {
        $this->putJson('/api/admin/mail-templates/invitation', ['subject' => 'Cokolwiek'], $this->adminToken());

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('body', $this->json()['error']);
    }

    public function testAnUnknownTemplateSaysTemplatesAreNotAddedHere(): void
    {
        $this->putJson('/api/admin/mail-templates/wymyslony', [
            'subject' => 'Cokolwiek',
            'body' => 'Cokolwiek',
        ], $this->adminToken());

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('invitation', $this->json()['error']);
    }

    /**
     * Typed in, saved, read back, previewed — still characters.
     */
    public function testATemplateEngineConstructSurvivesTheRoundTripLiterally(): void
    {
        $wrogi = '{% if 1 %}nie{% endif %} {{ 7 * 7 }} <?php system("id"); ?> {{ link|upper }}';

        $this->putJson('/api/admin/mail-templates/invitation', [
            'subject' => 'Zaproszenie',
            'body' => $wrogi . ' {{ link }}',
        ], $this->adminToken());

        self::assertResponseIsSuccessful();

        $this->get('/api/admin/mail-templates', $this->adminToken());
        $template = $this->json()['templates'][0];

        self::assertStringContainsString($wrogi, $template['body']);
        // Rendered, the constructs are still there; only `{{ link }}` was substituted.
        self::assertStringContainsString('{% if 1 %}', $template['preview']['body']);
        self::assertStringContainsString('{{ 7 * 7 }}', $template['preview']['body']);
        self::assertStringContainsString('<?php system("id"); ?>', $template['preview']['body']);
        self::assertStringContainsString('{{ link|upper }}', $template['preview']['body']);
    }

    public function testThePreviewRendersWordingThatWasNotSaved(): void
    {
        $this->postJson('/api/admin/mail-templates/invitation/preview', [
            'subject' => 'Podgląd dla {{ instancja }}',
            'body' => 'Niezapisana treść: {{ link }}',
        ], $this->adminToken());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Niezapisana treść', $this->json()['preview']['body']);
        self::assertStringNotContainsString('{{ link }}', $this->json()['preview']['body']);

        // Nothing was saved by previewing.
        $this->get('/api/admin/mail-templates', $this->adminToken());
        self::assertStringNotContainsString('Niezapisana treść', $this->json()['templates'][0]['body']);
    }

    public function testThePreviewRefusesWhatSavingWouldRefuse(): void
    {
        $this->postJson('/api/admin/mail-templates/invitation/preview', [
            'subject' => 'Zaproszenie',
            'body' => '{{ nieistniejace }}',
        ], $this->adminToken());

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * The set of templates is a set of places in the code, so there is no route for
     * adding one and none for deleting one.
     */
    public function testThereIsNoRouteThatCreatesOrDeletesATemplate(): void
    {
        $token = $this->adminToken();

        $this->postJson('/api/admin/mail-templates', ['subject' => 'x', 'body' => 'y'], $token);
        self::assertResponseStatusCodeSame(405);

        $this->client->request(
            'DELETE',
            '/api/admin/mail-templates/invitation',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );
        self::assertResponseStatusCodeSame(405);
    }

    // --------------------------------------------------------- wysyłka próbna

    public function testTheTestSendGoesToTheAdministratorsOwnAddress(): void
    {
        $this->postJson(
            '/api/admin/mail-templates/invitation/test',
            // A recipient in the request is ignored — this endpoint has no such field,
            // and asserting that is the point: it must not become one.
            ['recipient' => 'ktos-obcy@example.com'],
            $this->adminToken(),
        );

        self::assertResponseStatusCodeSame(202);
        self::assertSame('admin@web-systems.pl', $this->json()['recipient']);

        // Asked by recipient rather than by "the newest row". `queued_at` has second
        // precision and the fixture accounts were invited in the same second as this
        // send, so ordering by it alone picks an arbitrary one of them — which is how
        // this assertion first failed, pointing at the wrong recipient for the right
        // reason.
        self::assertSame('queued', $this->em->getConnection()->fetchOne(
            'SELECT status FROM ws.mail_log WHERE recipient = :email',
            ['email' => 'admin@web-systems.pl'],
        ));

        // And nothing was sent to the address the request named.
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne(
            'SELECT count(*) FROM ws.mail_log WHERE recipient = :email',
            ['email' => 'ktos-obcy@example.com'],
        ));
    }

    // ------------------------------------------------------------ dziennik maili

    public function testTheJournalListsWhatWasQueuedWithPolishStateNames(): void
    {
        // Two invitations, so the journal has something to show and to filter.
        $this->postJson('/api/admin/invitations', ['email' => 'jeden@web-systems.pl'], $this->adminToken());
        $this->postJson('/api/admin/invitations', ['email' => 'dwa@web-systems.pl'], $this->adminToken());

        $this->get('/api/admin/mail-log', $this->adminToken());

        self::assertResponseIsSuccessful();
        $answer = $this->json();

        // Four: two fixture accounts from setUp and the two invitations above.
        self::assertSame(4, $answer['count']);
        self::assertSame('w kolejce', $answer['entries'][0]['statusLabel']);
        self::assertSame('Zaproszenie do bazy wiedzy', $answer['entries'][0]['templateLabel']);
        self::assertContains(
            ['value' => 'failed', 'label' => 'nieudany'],
            $answer['statuses'],
        );
    }

    public function testTheJournalNeverCarriesAMessageBody(): void
    {
        $this->postJson('/api/admin/invitations', ['email' => 'jeden@web-systems.pl'], $this->adminToken());

        $this->get('/api/admin/mail-log', $this->adminToken());

        $entry = $this->json()['entries'][0];

        // Asserted on the shape of the answer, not on its contents: a body field that
        // happened to be empty today would pass a content check and ship a token
        // tomorrow.
        self::assertArrayNotHasKey('body', $entry);
        self::assertArrayNotHasKey('content', $entry);
        self::assertArrayNotHasKey('link', $entry);
    }

    public function testTheRecipientFilterMatchesAFragment(): void
    {
        $this->postJson('/api/admin/invitations', ['email' => 'szukany@web-systems.pl'], $this->adminToken());

        $this->get('/api/admin/mail-log?recipient=SZUKAN', $this->adminToken());

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->json()['count']);
        self::assertSame('szukany@web-systems.pl', $this->json()['entries'][0]['recipient']);
    }

    /**
     * A wildcard typed into the search box is a character, not a wildcard.
     *
     * Unescaped, `_` matches every address in the table and the filter reads as doing
     * nothing at all.
     */
    public function testAnUnderscoreInTheFilterIsALiteralCharacter(): void
    {
        $this->postJson('/api/admin/invitations', ['email' => 'jeden@web-systems.pl'], $this->adminToken());

        $this->get('/api/admin/mail-log?recipient=_', $this->adminToken());

        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->json()['count']);
    }

    public function testTheStatusFilterSelectsOnlyThatState(): void
    {
        $this->get('/api/admin/mail-log?status=failed', $this->adminToken());

        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->json()['count']);

        $this->get('/api/admin/mail-log?status=queued', $this->adminToken());
        self::assertGreaterThan(0, $this->json()['count']);
    }

    public function testAnUnknownStateIsRefusedRatherThanIgnored(): void
    {
        $this->get('/api/admin/mail-log?status=wymyslony', $this->adminToken());

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('queued', $this->json()['error']);
    }

    public function testThereIsNoRouteThatDeletesFromTheJournal(): void
    {
        $token = $this->adminToken();

        $this->client->request(
            'DELETE',
            '/api/admin/mail-log',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );

        self::assertResponseStatusCodeSame(405);
    }

    // ------------------------------------------------------------------ narzędzia

    private function adminToken(): string
    {
        return $this->tokenFor('admin@web-systems.pl');
    }

    private function tokenFor(string $email): string
    {
        $this->postJson('/api/login', ['email' => $email, 'password' => self::PASSWORD]);

        return $this->json()['token'];
    }

    private function get(string $uri, string $token): void
    {
        $this->client->request('GET', $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
    }

    /** @param array<string, mixed> $payload */
    private function postJson(string $uri, array $payload, ?string $token = null): void
    {
        $this->send('POST', $uri, $payload, $token);
    }

    /** @param array<string, mixed> $payload */
    private function putJson(string $uri, array $payload, ?string $token = null): void
    {
        $this->send('PUT', $uri, $payload, $token);
    }

    /** @param array<string, mixed> $payload */
    private function send(string $method, string $uri, array $payload, ?string $token): void
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }

        $this->client->request(
            $method,
            $uri,
            server: $server,
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );
    }
}
