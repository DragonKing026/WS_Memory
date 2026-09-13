<?php

declare(strict_types=1);

namespace App\Domain\Memory;

/**
 * Which question the search is answering.
 *
 * The two modes are not "better" and "worse" — they answer different questions,
 * and a user who picks the wrong one gets a useless answer from a working system:
 *
 *  - Semantic: "does anybody know anything about this?" Runs in the palace, on
 *    vectors, and finds content described in other words entirely. It also never
 *    returns nothing — only progressively weaker matches, which is why the
 *    interface has to show relevance rather than a bare list.
 *  - Lexical: "where exactly does this name appear?" Runs in PostgreSQL, on the
 *    text we hold ourselves, and matches terms rather than meaning. Asking it for
 *    `PalaceWing` finds `PalaceWing`, not "things about wings".
 *
 * The split in where they run is not an implementation detail that might be
 * tidied away later: the palace exposes no lexical mode at all (D-029), so this
 * enum is also the boundary between two genuinely different data sources — with
 * genuinely different coverage. See LexicalIndex for what the lexical side can
 * and cannot see.
 */
enum SearchMode: string
{
    case Semantic = 'semantic';

    case Lexical = 'lexical';
}
