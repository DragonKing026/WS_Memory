<?php

declare(strict_types=1);

namespace App\Domain\Space;

/**
 * The membership was not changed, and here is the sentence to show the person who asked.
 *
 * One exception carrying a reason rather than five classes: every caller does the same
 * two things with it — turn the reason into a status code, print the message — and five
 * classes would mean five `catch` blocks to keep in step.
 *
 * The messages are Polish, written for a person, and each says what to DO about it.
 * Two of them are worth reading in full before changing anything here, because they are
 * the rules this whole surface exists to hold:
 *
 *   - **the last administrator.** A space whose last administrator is demoted or removed
 *     has nobody who can hand out roles in it. There is exactly one way back — a global
 *     administrator reaching in from this panel — and that is a workaround, not a
 *     design. So the operation is refused while the space has one administrator left,
 *     and the way to remove that person is to appoint somebody else first;
 *   - **a private space.** A private space belongs to one account by definition
 *     (inviolable rule 6: a write naming no space lands there). Adding, demoting or
 *     removing people in it would break that assumption quietly — the space would keep
 *     receiving its owner's unclassified writes while somebody else could read them.
 *     No role unlocks this, global administrator included.
 */
final class MembershipRefused extends \RuntimeException
{
    private function __construct(
        public readonly MembershipRefusal $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function unknownSpace(string $slug): self
    {
        return new self(
            MembershipRefusal::UnknownSpace,
            \sprintf('Nie ma przestrzeni o slugu „%s”.', $slug),
        );
    }

    public static function unknownMember(string $slug): self
    {
        return new self(
            MembershipRefusal::UnknownMember,
            \sprintf('Ta osoba nie ma żadnej roli w przestrzeni „%s”.', $slug),
        );
    }

    public static function malformedRole(string $given): self
    {
        return new self(
            MembershipRefusal::MalformedRole,
            \sprintf('Nieznana rola „%s”. Dozwolone: reader, writer, admin.', $given),
        );
    }

    /**
     * Note the status code this becomes on the admin surface: 422, where
     * `POST /api/spaces/{slug}/members` answers 403 for the same space. The two are not
     * inconsistent. There, the caller's authority is what fails — an ordinary member
     * asking to share somebody's private space. Here the caller already is a global
     * administrator, so authority is not the problem: the request itself is one that
     * cannot be carried out on this kind of space, whoever asks.
     */
    public static function privateSpace(string $slug): self
    {
        return new self(
            MembershipRefusal::PrivateSpace,
            \sprintf(
                'Przestrzeń „%s” jest prywatna — należy do jednej osoby i nie zmienia się w niej członkostwa.',
                $slug,
            ),
        );
    }

    public static function lastAdministrator(string $slug): self
    {
        return new self(
            MembershipRefusal::LastAdministrator,
            \sprintf(
                'To ostatni administrator przestrzeni „%s”. Najpierw nadaj rolę administratora komuś innemu — '
                . 'bez administratora nikt nie zarządza w niej dostępem.',
                $slug,
            ),
        );
    }
}
