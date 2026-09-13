<?php

declare(strict_types=1);

namespace App\Tests\Application\Mail;

use App\Application\Mail\SendMail;
use App\Application\Mail\SendMailHandler;
use App\Domain\Mail\MailSender;
use App\Domain\Mail\MailStatus;
use App\Domain\Mail\MailTemplateKey;
use App\Domain\Mail\RenderedMail;
use PHPUnit\Framework\TestCase;

/**
 * The worker step: hand one message over, record how it went.
 *
 * The orderings are what these tests are about. A journal that counts an attempt
 * after a successful send undercounts exactly the failures somebody is hunting, and
 * one that records a failure after rethrowing records nothing at all if the process
 * does not survive the rethrow.
 *
 * The rethrow itself is tested because it is what makes Messenger retry. Swallowed,
 * the failure would leave a journal entry and no second attempt — and the entry would
 * make it look as though retrying had been tried.
 */
final class SendMailHandlerTest extends TestCase
{
    public function testASentMessageEndsAsSent(): void
    {
        $log = new InMemoryMailLog();
        $sender = new RecordingMailSender();
        $id = $log->queue(MailTemplateKey::Invitation, 'ktos@example.com', 'Zaproszenie');

        (new SendMailHandler($sender, $log))(new SendMail($id, 'ktos@example.com', 'Zaproszenie', 'Treść'));

        self::assertSame(MailStatus::Sent, $log->rows[$id]['status']);
        self::assertSame(1, $log->rows[$id]['attempts']);
        self::assertSame([['ktos@example.com', 'Zaproszenie', 'Treść']], $sender->sent);
    }

    public function testTheAttemptIsCountedBeforeTheTransportIsTouched(): void
    {
        $log = new InMemoryMailLog();
        $sender = new RecordingMailSender(fails: 'serwer nie odpowiada');
        $id = $log->queue(MailTemplateKey::Invitation, 'ktos@example.com', 'Zaproszenie');

        try {
            (new SendMailHandler($sender, $log))(new SendMail($id, 'ktos@example.com', 'Zaproszenie', 'Treść'));
        } catch (\RuntimeException) {
            // Expected — asserted in its own test below.
        }

        // Counted even though the send failed: a worker killed mid-send still leaves
        // the attempt visible.
        self::assertSame(1, $log->rows[$id]['attempts']);
        self::assertSame(['queue', 'beginAttempt', 'markFailed'], $log->calls);
    }

    public function testAFailureIsWrittenWithTheTransportsOwnSentence(): void
    {
        $log = new InMemoryMailLog();
        $sender = new RecordingMailSender(fails: 'Connection refused na poczta:587');
        $id = $log->queue(MailTemplateKey::Invitation, 'ktos@example.com', 'Zaproszenie');

        try {
            (new SendMailHandler($sender, $log))(new SendMail($id, 'ktos@example.com', 'Zaproszenie', 'Treść'));
        } catch (\RuntimeException) {
        }

        self::assertSame(MailStatus::Failed, $log->rows[$id]['status']);
        // The reason has to be the server's own words: it is what an administrator
        // reads in the panel instead of going to the container logs.
        self::assertSame('Connection refused na poczta:587', $log->rows[$id]['reason']);
    }

    public function testItRethrowsSoThatMessengerRetries(): void
    {
        $log = new InMemoryMailLog();
        $sender = new RecordingMailSender(fails: 'serwer nie odpowiada');
        $id = $log->queue(MailTemplateKey::Invitation, 'ktos@example.com', 'Zaproszenie');

        $this->expectException(\RuntimeException::class);

        (new SendMailHandler($sender, $log))(new SendMail($id, 'ktos@example.com', 'Zaproszenie', 'Treść'));
    }

    /**
     * A retry that succeeds leaves the row sent, with the attempts counted.
     */
    public function testASecondAttemptCanSucceed(): void
    {
        $log = new InMemoryMailLog();
        $sender = new RecordingMailSender(fails: 'chwilowa awaria');
        $id = $log->queue(MailTemplateKey::Invitation, 'ktos@example.com', 'Zaproszenie');
        $handler = new SendMailHandler($sender, $log);
        $message = new SendMail($id, 'ktos@example.com', 'Zaproszenie', 'Treść');

        try {
            $handler($message);
        } catch (\RuntimeException) {
        }

        $sender->recovers();
        $handler($message);

        self::assertSame(MailStatus::Sent, $log->rows[$id]['status']);
        self::assertSame(2, $log->rows[$id]['attempts']);
    }

    /**
     * A throwable with no message still says something.
     */
    public function testASilentFailureIsRecordedByItsClass(): void
    {
        $log = new InMemoryMailLog();
        $sender = new RecordingMailSender(fails: '');
        $id = $log->queue(MailTemplateKey::Invitation, 'ktos@example.com', 'Zaproszenie');

        try {
            (new SendMailHandler($sender, $log))(new SendMail($id, 'ktos@example.com', 'Zaproszenie', 'Treść'));
        } catch (\RuntimeException) {
        }

        self::assertSame(\RuntimeException::class, $log->rows[$id]['reason']);
    }
}

/**
 * A transport that records what it was given, and refuses on demand.
 */
final class RecordingMailSender implements MailSender
{
    /** @var list<array{0: string, 1: string, 2: string}> */
    public array $sent = [];

    public function __construct(private ?string $fails = null)
    {
    }

    public function recovers(): void
    {
        $this->fails = null;
    }

    public function send(string $recipient, RenderedMail $mail): void
    {
        if (null !== $this->fails) {
            throw new \RuntimeException($this->fails);
        }

        $this->sent[] = [$recipient, $mail->subject, $mail->body];
    }
}
