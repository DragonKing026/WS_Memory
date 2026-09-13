<?php

declare(strict_types=1);

namespace App\Domain\Mail;

/**
 * One editable mail: its subject, its body, and who last touched it.
 *
 * **Validation happens in the constructor**, which means an invalid template cannot
 * exist as an object — not in the editing screen, not on its way to the database,
 * not on the way back out of it. The alternative, a `validate()` method somebody has
 * to remember to call, is the same rule with a hole in it at whichever call site was
 * written last.
 *
 * `updatedByEmail` is an address rather than a reference to an account, so it survives
 * the account being deleted — "last changed by ktos@web-systems.pl" is what somebody
 * looking at unexpected wording wants to read, and a foreign key with `SET NULL` loses
 * exactly that. The authority on who did what is the audit journal.
 *
 * Checking on the way **out** of the database matters as much as on the way in: the
 * closed list of places lives in code, so it can shrink under a row that was
 * perfectly valid when it was saved. Reading is where that is discovered — while
 * an administrator is looking at the editor and can fix it — rather than at three
 * in the morning when an invitation fails to render.
 */
final readonly class MailTemplate
{
    /** The column is VARCHAR(300); the check is here so the failure is a sentence, not a driver error. */
    public const SUBJECT_LIMIT = 300;

    /**
     * @throws InvalidTemplate
     */
    public function __construct(
        public MailTemplateKey $key,
        public string $subject,
        public string $body,
        public \DateTimeImmutable $updatedAt,
        public ?string $updatedByEmail = null,
    ) {
        if ('' === trim($subject)) {
            throw InvalidTemplate::blankSubject();
        }

        if ('' === trim($body)) {
            throw InvalidTemplate::blankBody();
        }

        if (mb_strlen($subject) > self::SUBJECT_LIMIT) {
            throw InvalidTemplate::tooLongSubject(self::SUBJECT_LIMIT);
        }

        $allowed = $key->placeholders();

        $unknown = Placeholders::unknownIn($subject . "\n" . $body, $allowed);
        if ([] !== $unknown) {
            throw InvalidTemplate::unknownPlaceholders($unknown, $allowed);
        }

        // The subject is copied into `ws.mail_log`, so a place carrying a credential
        // is refused there and allowed in the body. Checked after the unknown-place
        // check, because "{{ link }} is not allowed in the subject" is a confusing
        // thing to be told about a template that also uses places that do not exist.
        $inSubject = array_intersect(Placeholders::usedIn($subject), $key->sensitive());
        if ([] !== $inSubject) {
            throw InvalidTemplate::sensitiveInSubject(array_values($inSubject));
        }
    }

    /**
     * Subject and body rendered with the given values.
     *
     * @param array<string, string> $values
     *
     * @throws InvalidTemplate
     */
    public function render(array $values): RenderedMail
    {
        return new RenderedMail(
            subject: Placeholders::render($this->subject, $values),
            body: Placeholders::render($this->body, $values),
        );
    }

    /**
     * What the panel shows as a preview, and what a test send contains.
     *
     * @throws InvalidTemplate
     */
    public function renderSample(): RenderedMail
    {
        return $this->render($this->key->sampleValues());
    }
}
