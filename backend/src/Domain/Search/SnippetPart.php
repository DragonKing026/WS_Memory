<?php

declare(strict_types=1);

namespace App\Domain\Search;

/**
 * One stretch of a snippet, either matched or not.
 *
 * A snippet is carried as a list of these rather than as a string with markup
 * because the alternative is sending HTML from the database to the browser. That
 * is the classic route to an injection bug: the content is Markdown written by
 * people and agents, it can contain anything, and a template rendering it as HTML
 * would render whatever it contains. Structure cannot be injected into.
 */
final readonly class SnippetPart
{
    public function __construct(
        public string $text,
        public bool $match,
    ) {
    }
}
