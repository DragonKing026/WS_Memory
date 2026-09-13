<?php

declare(strict_types=1);

namespace App\Application\Mail;

use App\Domain\Audit\AuditTrail;
use App\Domain\Identity\Actor;
use App\Domain\Mail\InvalidTemplate;
use App\Domain\Mail\MailTemplate;
use App\Domain\Mail\MailTemplateKey;
use App\Domain\Mail\MailTemplateLibrary;
use App\Entity\User;

/**
 * Changes the wording of a mail, with a trace of who changed it.
 *
 * Audited for the reason membership changes are (D-016): the wording of an invitation
 * is what a stranger reads before they decide to trust a link, so "who made it say
 * that" has to be answerable. The entry records the template and its new subject and
 * **not** the body: a body is long, versioning it here would duplicate the table next
 * to it, and the audit journal is a log of actions rather than a revision history.
 *
 * Validation happens in MailTemplate's constructor, which is why this method can be
 * this short — an invalid template never becomes an object, so there is nothing to
 * check again before saving.
 */
final readonly class UpdateMailTemplate
{
    public function __construct(
        private MailTemplateLibrary $templates,
        private AuditTrail $audit,
    ) {
    }

    /**
     * @throws InvalidTemplate when the wording uses places this template does not have
     */
    public function __invoke(MailTemplateKey $key, string $subject, string $body, User $by): MailTemplate
    {
        $template = new MailTemplate(
            key: $key,
            subject: $subject,
            body: $body,
            updatedAt: new \DateTimeImmutable(),
            updatedByEmail: $by->getEmail(),
        );

        $this->templates->save($template);

        $this->audit->record(
            action: 'mail_template.changed',
            actor: Actor::human($by->getId()->toRfc4122()),
            target: ['template' => $key->value, 'subject' => $subject],
        );

        return $template;
    }
}
