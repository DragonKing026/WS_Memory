<?php

declare(strict_types=1);

namespace App\Presentation\Api;

use App\Domain\Mail\MailLog;
use App\Domain\Mail\MailLogEntry;
use App\Domain\Mail\MailLogFilter;
use App\Domain\Mail\MailStatus;
use App\Domain\Mail\MailTemplateKey;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The mail journal, read.
 *
 * One verb, like the audit log, and for a related reason: this is the answer to "the
 * invitation never arrived", and a surface that can edit or clear it answers nothing.
 * Retention is an operator's job, described in `docs/05-deployment.md`.
 *
 * **The journal holds no message bodies**, so there is no route here that could return
 * one. An invitation mail carries a working token; what a message said is reconstructed
 * from its template, which is a different screen (D-038).
 *
 * An unknown status is 422 rather than ignored, for the reason the audit screen gives:
 * a filter that silently widens makes the screen say "these are the failures" while
 * showing everything, and the reader has no way to notice.
 */
final readonly class AdminMailLogController
{
    private const FORBIDDEN_BODY = ['error' => 'Dziennik maili wymaga uprawnień administratora.'];

    public function __construct(
        private Security $security,
        private MailLog $log,
    ) {
    }

    #[Route('/api/admin/mail-log', name: 'api_admin_mail_log', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        if (!$this->currentUser()->isGlobalAdmin()) {
            return new JsonResponse(self::FORBIDDEN_BODY, Response::HTTP_FORBIDDEN);
        }

        $rawStatus = self::text($request, 'status');
        $status = null === $rawStatus ? null : MailStatus::tryFrom($rawStatus);

        if (null !== $rawStatus && null === $status) {
            return new JsonResponse([
                'error' => \sprintf(
                    'Nieznany stan „%s”. Dozwolone: %s.',
                    $rawStatus,
                    implode(', ', array_column(MailStatus::cases(), 'value')),
                ),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $filter = new MailLogFilter(
            status: $status,
            recipient: self::text($request, 'recipient'),
            limit: $request->query->getInt('limit', MailLogFilter::PAGE_SIZE),
            offset: $request->query->getInt('offset'),
        );

        $page = $this->log->page($filter);

        return new JsonResponse([
            'entries' => array_map(self::present(...), $page->entries),
            // Rows matching the filter, not rows in this answer — the paging controls
            // need the former, and the latter is a number the reader can already see.
            'count' => $page->total,
            'limit' => $filter->limit,
            'offset' => $filter->offset,
            'hasMore' => $filter->offset + \count($page->entries) < $page->total,
            // The vocabulary of states, with the Polish word for each. Sent from here so
            // the interface has no dictionary of its own to keep in step.
            'statuses' => array_map(
                static fn (MailStatus $s): array => ['value' => $s->value, 'label' => $s->label()],
                MailStatus::cases(),
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(MailLogEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'recipient' => $entry->recipient,
            'templateKey' => $entry->templateKey,
            // The template's own name when this version still knows it, and the raw key
            // when it does not. A journal entry for a mail a later version stopped
            // sending must stay readable rather than become a blank.
            'templateLabel' => MailTemplateKey::tryFrom($entry->templateKey)?->label() ?? $entry->templateKey,
            'subject' => $entry->subject,
            'status' => $entry->status->value,
            'statusLabel' => $entry->status->label(),
            'attempts' => $entry->attempts,
            'failureReason' => $entry->failureReason,
            'queuedAt' => $entry->queuedAt->format(\DATE_ATOM),
            'lastAttemptAt' => $entry->lastAttemptAt?->format(\DATE_ATOM),
            'sentAt' => $entry->sentAt?->format(\DATE_ATOM),
        ];
    }

    private static function text(Request $request, string $name): ?string
    {
        $raw = $request->query->all()[$name] ?? null;
        $value = \is_scalar($raw) ? trim((string) $raw) : '';

        return '' === $value ? null : $value;
    }

    private function currentUser(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Trasa poza firewallem — kontroler nie powinien tu trafić.');
        }

        return $user;
    }
}
