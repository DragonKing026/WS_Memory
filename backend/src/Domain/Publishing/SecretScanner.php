<?php

declare(strict_types=1);

namespace App\Domain\Publishing;

/**
 * Port: the thing that decides whether a piece of content may be published.
 *
 * An interface rather than a single class, for a reason that is already visible
 * in the task list: the plugin runs the same check before sending and the server
 * runs it again on arrival, because trusting the client would be the whole
 * mistake (D-014). Two implementations of one contract is how those two stay the
 * same rule; a second copy of the regexes is how they stop being one.
 *
 * It also leaves room for the strategy this will eventually need — an
 * organisation-specific pattern list, an allowance for a space that legitimately
 * holds configuration samples — as another adapter rather than another branch
 * inside the one we have.
 *
 * Deliberately takes the source path as well as the text. Some secrets are
 * recognisable only by where they came from: a file literally named `.env` is a
 * secret regardless of what is in it, and a drawer whose body happens to look
 * like configuration is not.
 */
interface SecretScanner
{
    public function scan(string $content, ?string $sourcePath = null): ScanReport;
}
