<?php

declare(strict_types=1);

namespace App\Domain\Mail;

/**
 * A subject and a body with the places already filled in.
 *
 * Exists so that "rendered" is a type rather than a convention. The body of one of
 * these may contain a live invitation token, which is why nothing in this system
 * writes a RenderedMail to a table: it travels from the rendering to the transport
 * and is gone.
 */
final readonly class RenderedMail
{
    public function __construct(
        public string $subject,
        public string $body,
    ) {
    }
}
