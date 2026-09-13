<?php

declare(strict_types=1);

namespace App\Domain\Mail;

/**
 * Port: the editable wording of the mails this system sends.
 *
 * There is no `create` and no `delete`. The set of templates is the set of places in
 * the code that send mail — an enum, not data — so the only operation on wording is
 * replacing it. A missing row for a known key is a broken installation rather than a
 * state to design for, and `get()` says so by throwing.
 */
interface MailTemplateLibrary
{
    /**
     * @throws MissingMailTemplate when the installation has no row for this key
     * @throws InvalidTemplate     when the stored row no longer matches the closed list of places
     */
    public function get(MailTemplateKey $key): MailTemplate;

    /**
     * Every template, in the enum's order.
     *
     * @return list<MailTemplate>
     */
    public function all(): array;

    /**
     * Replaces the wording of a template that already exists.
     *
     * Takes a MailTemplate rather than two strings, so the validation in its
     * constructor has already happened by the time anything reaches the database.
     *
     * Who made the change travels inside the template, as an address — see
     * MailTemplate. The authority on who did what is the audit journal.
     *
     * @throws MissingMailTemplate when there is no row for this key
     */
    public function save(MailTemplate $template): void;
}
