<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Domain\Identity\AuthorDirectory;

/**
 * A page of the audit log with its actors named, ready for the panel.
 *
 * The names are handed in as maps, resolved once for the whole page (see
 * AuthorDirectory) — a hundred entries must not become a hundred lookups, and a listing
 * that slows down in proportion to its own page size is how a screen becomes unusable
 * without anybody being able to point at the slow part.
 *
 * Missing names are shown, not hidden. An entry whose actor's account or token has since
 * been removed keeps its place in the list with a sentence saying so: an audit log that
 * quietly drops entries when somebody leaves is worse than one naming nobody, because it
 * reads as evidence that nothing happened.
 */
final readonly class AuditPageView
{
    /** Shown where an agent token has been revoked and removed since it acted. */
    private const LOST_TOKEN = 'agent (token usunięty)';

    /** Shown where the acting account no longer exists. */
    private const LOST_ACCOUNT = 'konto usunięte';

    /** The actor of an entry written by the application on nobody's behalf. */
    private const SYSTEM = 'system';

    /**
     * @param array<string, string> $names       display names by user id
     * @param array<string, string> $tokenLabels token labels by token id, owner included
     */
    public function __construct(
        private AuditPage $page,
        private AuditFilter $filter,
        private array $names = [],
        private array $tokenLabels = [],
    ) {
    }

    /**
     * Builds the view, resolving every actor on the page in two queries.
     */
    public static function resolve(AuditPage $page, AuditFilter $filter, AuthorDirectory $authors): self
    {
        $userIds = [];
        $tokenIds = [];
        foreach ($page->entries as $entry) {
            if (null !== $entry->actorUserId) {
                $userIds[] = $entry->actorUserId;
            }
            if (null !== $entry->actorAgentTokenId) {
                $tokenIds[] = $entry->actorAgentTokenId;
            }
        }

        return new self(
            $page,
            $filter,
            $authors->namesOf($userIds),
            $authors->tokenLabelsOf($tokenIds),
        );
    }

    /**
     * @return array{
     *     entries: list<array<string, mixed>>,
     *     count: int,
     *     limit: int,
     *     offset: int,
     *     hasMore: bool,
     *     actions: list<string>,
     * }
     */
    public function toArray(): array
    {
        return [
            'entries' => array_map($this->present(...), $this->page->entries),
            // The total matching the filter, not the size of this page: it is printed
            // next to the paging controls, and a page-sized number there would tell a
            // reader that a log of a million entries holds a hundred.
            'count' => $this->page->total,
            'limit' => $this->filter->limit,
            'offset' => $this->filter->offset,
            'hasMore' => $this->filter->offset + \count($this->page->entries) < $this->page->total,
            'actions' => $this->page->actions,
        ];
    }

    /** @return array<string, mixed> */
    private function present(AuditEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'action' => $entry->action,
            'actor' => $this->actorOf($entry),
            'actorKind' => $entry->actorKind()->value,
            'space' => $entry->spaceSlug,
            'target' => $entry->target,
            'ip' => $entry->ip,
            'createdAt' => $entry->createdAt->format(\DATE_ATOM),
        ];
    }

    private function actorOf(AuditEntry $entry): string
    {
        return match ($entry->actorKind()) {
            // A token's label carries its owner's name, so an agent reads as
            // "etykieta (właściciel)" — an agent acts for somebody, and the label
            // alone does not say whose authority it wrote under.
            AuditActorKind::Agent => $this->tokenLabels[(string) $entry->actorAgentTokenId] ?? self::LOST_TOKEN,
            AuditActorKind::Human => $this->names[(string) $entry->actorUserId] ?? self::LOST_ACCOUNT,
            AuditActorKind::System => self::SYSTEM,
        };
    }
}
