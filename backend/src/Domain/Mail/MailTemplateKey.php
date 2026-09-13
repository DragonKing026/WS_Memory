<?php

declare(strict_types=1);

namespace App\Domain\Mail;

/**
 * The mails this system can send, and what may appear in each one.
 *
 * An enum rather than a table of keys, and that is the whole security model of the
 * editing screen: an administrator edits the **wording** of a known mail and cannot
 * invent a new kind of mail, because a kind of mail is a place in the code that
 * decides to send one. A row somebody added by hand would be a template nothing
 * ever renders.
 *
 * `placeholders()` is the closed list. Anything else in a template is a mistake
 * caught when the template is saved, never a blank left in a message somebody
 * already received.
 *
 * `sensitive()` is the shorter list of places carrying something that must not be
 * copied anywhere else. `{{ link }}` contains a working invitation token, and the
 * subject line is stored in `ws.mail_log`, so a sensitive place is allowed in the
 * body and refused in the subject. Nobody puts a link in a subject line on purpose;
 * the rule is there so nobody does it by accident either.
 */
enum MailTemplateKey: string
{
    case Invitation = 'invitation';

    /**
     * Every place this template may use.
     *
     * Polish, because these are typed by a Polish-speaking administrator in the
     * panel — they are interface, like the labels next to them. The case names and
     * everything around them stay English, as the rest of the code does.
     *
     * @return list<string>
     */
    public function placeholders(): array
    {
        return match ($this) {
            self::Invitation => ['zapraszajacy', 'instancja', 'link', 'wygasa', 'adres_email'],
        };
    }

    /**
     * Places allowed in the body and refused in the subject.
     *
     * @return list<string>
     */
    public function sensitive(): array
    {
        return match ($this) {
            self::Invitation => ['link'],
        };
    }

    /**
     * What a preview and a test send are rendered with.
     *
     * Sample values, never the real ones — a preview built from a live invitation
     * would put a usable token on the screen of whoever opened the editor, and a
     * test send would mail it to them. The sample link goes nowhere on purpose:
     * `przykladowy-token` is not 64 hex characters and no invitation will ever
     * match it.
     *
     * @return array<string, string>
     */
    public function sampleValues(): array
    {
        return match ($this) {
            self::Invitation => [
                'zapraszajacy' => 'Anna Przykładowa',
                'instancja' => 'Baza wiedzy',
                'link' => 'https://przyklad.example.com/zaproszenie/przykladowy-token',
                'wygasa' => '20 września 2026, 12:00',
                'adres_email' => 'ktos@example.com',
            ],
        };
    }

    /** The name of this mail in the panel. */
    public function label(): string
    {
        return match ($this) {
            self::Invitation => 'Zaproszenie do bazy wiedzy',
        };
    }

    /** When it is sent — the one thing an editor needs to know before editing. */
    public function describeWhenSent(): string
    {
        return match ($this) {
            self::Invitation => 'Wysyłane w chwili wystawienia zaproszenia, na adres z zaproszenia.',
        };
    }
}
