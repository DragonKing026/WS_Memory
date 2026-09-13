<?php

declare(strict_types=1);

namespace App\Tests\Application\Mail;

use App\Application\Invitation\AcceptInvitation;
use App\Application\Invitation\IssueInvitation;
use App\Application\Mail\QueueMail;
use App\Application\Mail\SendInvitationMail;
use App\Application\Mail\SendMail;
use App\Application\Mail\UpdateMailTemplate;
use App\Domain\Mail\MailStatus;
use App\Domain\Mail\MailTemplateKey;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * The invitation mail, end to end.
 *
 * The queue is worked the way the worker works it — through the real transport —
 * rather than by calling the handler with arguments this test made up. The point of
 * the whole design is that issuing an invitation and sending its mail are separated
 * by a queue, and a test that called the handler directly would be testing a system
 * where they are not.
 *
 * Two claims here are about things NOT happening, and both are checked against the
 * database rather than against the code: that the mail journal contains no token, and
 * that a dead mail server leaves the invitation perfectly usable.
 */
final class InvitationMailTest extends KernelTestCase
{
    private const PASSWORD = 'DlugieHaslo123!x';

    private Connection $db;
    private IssueInvitation $issue;
    private AcceptInvitation $accept;

    /** @var list<array<string, mixed>> */
    private array $seededTemplates = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->db = $container->get(EntityManagerInterface::class)->getConnection();
        // `ws.mail_templates` is deliberately NOT in this list: it holds installation
        // data seeded by a migration, not test fixtures, and truncating it would leave
        // every later test sending "no such template".
        $this->db->executeStatement(
            'TRUNCATE ws.messenger_messages, ws.mail_log, ws.proposals, ws.memory_entries, '
            . 'ws.document_revisions, ws.documents, ws.agent_tokens, ws.space_members, '
            . 'ws.invitations, ws.audit_log, ws.spaces, ws.users CASCADE'
        );

        // Which is exactly why the wording is snapshotted and put back. One test here
        // edits a template, and without this the wording it wrote would leak into
        // every test that ran afterwards — a failure that points at the wrong test and
        // depends on the order PHPUnit happened to pick.
        $this->seededTemplates = $this->db->fetchAllAssociative(
            'SELECT template_key, subject, body FROM ws.mail_templates'
        );

        $this->issue = $container->get(IssueInvitation::class);
        $this->accept = $container->get(AcceptInvitation::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->seededTemplates as $template) {
            $this->db->executeStatement(
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

    public function testIssuingAnInvitationQueuesAMailToTheInvitedAddress(): void
    {
        ($this->issue)('nowy@web-systems.pl');

        $row = $this->onlyJournalRow();

        self::assertSame('nowy@web-systems.pl', $row['recipient']);
        self::assertSame('invitation', $row['template_key']);
        self::assertSame(MailStatus::Queued->value, $row['status']);
        // Nothing has been attempted yet: the request only put it in the queue.
        self::assertSame(0, (int) $row['attempts']);
        self::assertStringContainsString('Zaproszenie', (string) $row['subject']);
    }

    /**
     * The link in the mail is a link that works.
     *
     * Proven by using it: the token taken out of the message body creates the account.
     * Asserting that the body merely contains something link-shaped would pass just as
     * happily on a link built from the wrong base address, or on yesterday's token.
     */
    public function testTheLinkInTheMailCreatesTheAccount(): void
    {
        ($this->issue)('nowy@web-systems.pl');

        $sent = $this->workTheQueue();
        self::assertCount(1, $sent);

        self::assertMatchesRegularExpression(
            '#https://wiedza\.test/zaproszenie/[0-9a-f]{64}#',
            $sent[0]->body,
            'Link musi być zbudowany z publicznego adresu instancji.',
        );

        preg_match('#/zaproszenie/([0-9a-f]{64})#', $sent[0]->body, $match);
        $token = $match[1] ?? '';
        self::assertNotSame('', $token, 'W treści maila musi być token zaproszenia.');

        $user = ($this->accept)($token, 'Nowy', self::PASSWORD);

        self::assertInstanceOf(User::class, $user);
        self::assertSame('nowy@web-systems.pl', $user->getEmail());
    }

    public function testAfterSendingTheJournalSaysSent(): void
    {
        ($this->issue)('nowy@web-systems.pl');
        $this->workTheQueue();

        $row = $this->onlyJournalRow();

        self::assertSame(MailStatus::Sent->value, $row['status']);
        self::assertSame(1, (int) $row['attempts']);
        self::assertNotNull($row['sent_at']);
        self::assertNull($row['failure_reason']);
    }

    /**
     * The journal holds no token and no message body — checked over the whole table.
     *
     * Every column of every row is cast to text and searched, so the assertion does
     * not depend on knowing which column somebody might have added. A review of the
     * code would prove only that the code we have today does not store it.
     */
    public function testTheJournalContainsNeitherTheTokenNorTheBody(): void
    {
        $issued = ($this->issue)('nowy@web-systems.pl');
        $this->workTheQueue();

        $rows = $this->db->fetchFirstColumn('SELECT (m.*)::text FROM ws.mail_log m');

        self::assertCount(1, $rows);
        foreach ($rows as $row) {
            self::assertStringNotContainsString($issued->plainToken, (string) $row);
            // A fragment of the body that is not in the subject: its presence would
            // mean the rendered message had been stored somewhere in this row.
            self::assertStringNotContainsString('Konto zakładasz sam', (string) $row);
        }
    }

    public function testChangingTheTemplateChangesTheNextMail(): void
    {
        $admin = $this->accept->__invoke(
            ($this->issue)('admin@web-systems.pl', grantsGlobalAdmin: true)->plainToken,
            'Administrator',
            self::PASSWORD,
        );
        $this->workTheQueue();

        static::getContainer()->get(UpdateMailTemplate::class)->__invoke(
            MailTemplateKey::Invitation,
            'Nowy temat dla {{ instancja }}',
            'Zupełnie inna treść. Wejdź na {{ link }}.',
            $admin,
        );

        ($this->issue)('nowy@web-systems.pl');
        $sent = $this->workTheQueue();

        self::assertCount(1, $sent);
        self::assertStringStartsWith('Nowy temat dla', $sent[0]->subject);
        self::assertStringContainsString('Zupełnie inna treść', $sent[0]->body);
        self::assertStringNotContainsString('Konto zakładasz sam', $sent[0]->body);
    }

    /**
     * With no public address, nothing is sent and the journal says why.
     *
     * The service is built here rather than taken from the container, because the
     * absence being tested is a configuration state the test environment deliberately
     * does not have — and a mail with a link to somebody's own localhost is the one
     * outcome worse than no mail.
     */
    public function testWithoutAPublicAddressTheMailIsRefusedWithAReason(): void
    {
        $container = static::getContainer();
        $withoutUrl = new SendInvitationMail(
            $container->get(QueueMail::class),
            linkBaseUrl: '',
            instanceName: 'Baza wiedzy',
        );

        $issued = ($this->issue)('nowy@web-systems.pl');
        $this->db->executeStatement('TRUNCATE ws.mail_log');

        $withoutUrl($issued, null);

        $row = $this->onlyJournalRow();
        self::assertSame(MailStatus::Failed->value, $row['status']);
        self::assertStringContainsString('WS_PUBLIC_URL', (string) $row['failure_reason']);
        // Never attempted, so no attempt is claimed.
        self::assertSame(0, (int) $row['attempts']);

        // And the invitation is untouched: the link still works.
        self::assertInstanceOf(User::class, ($this->accept)($issued->plainToken, 'Nowy', self::PASSWORD));
    }

    /**
     * A dead mail server does not cost anybody their invitation.
     *
     * The send is never attempted here — the message sits in the queue, exactly as it
     * would while an SMTP server is down — and the invitation is accepted anyway. This
     * is the property the whole asynchronous shape exists for.
     */
    public function testAnUnsentMailDoesNotBlockTheInvitation(): void
    {
        $issued = ($this->issue)('nowy@web-systems.pl');

        self::assertSame(MailStatus::Queued->value, $this->onlyJournalRow()['status']);
        self::assertInstanceOf(User::class, ($this->accept)($issued->plainToken, 'Nowy', self::PASSWORD));
    }

    public function testTheSeededTemplateMatchesTheClosedListOfPlaces(): void
    {
        // Rendering with the sample values is the whole check: it throws when the
        // wording uses a place the code does not fill. The migration writes that
        // wording by hand, so nothing but a test keeps the two in step.
        foreach (static::getContainer()->get(\App\Domain\Mail\MailTemplateLibrary::class)->all() as $template) {
            $rendered = $template->renderSample();

            self::assertStringNotContainsString('{{', $rendered->subject);
            self::assertStringNotContainsString('{{', $rendered->body);
        }
    }

    // ------------------------------------------------------------------ narzędzia

    /**
     * Works the queue the way the worker does, and returns what was handed over.
     *
     * @return list<SendMail>
     */
    private function workTheQueue(): array
    {
        $container = static::getContainer();
        $transport = $container->get('messenger.transport.async');
        self::assertInstanceOf(TransportInterface::class, $transport);
        $bus = $container->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        /** @var list<SendMail> $handled */
        $handled = [];

        foreach ($transport->get() as $envelope) {
            self::assertInstanceOf(Envelope::class, $envelope);
            $message = $envelope->getMessage();

            if (!$message instanceof SendMail) {
                continue;
            }

            $bus->dispatch($envelope->with(new \Symfony\Component\Messenger\Stamp\ReceivedStamp('async')));
            $transport->ack($envelope);
            $handled[] = $message;
        }

        return $handled;
    }

    /**
     * @return array<string, mixed>
     */
    private function onlyJournalRow(): array
    {
        $rows = $this->db->fetchAllAssociative('SELECT * FROM ws.mail_log');

        self::assertCount(1, $rows, 'Dziennik maili ma mieć dokładnie jeden wiersz.');

        return $rows[0];
    }
}
