<?php

declare(strict_types=1);

namespace App\Presentation\Api;

use App\Domain\Identity\AdministrationRefusal;
use Symfony\Component\HttpFoundation\Response;

/**
 * The one place an account or invitation refusal becomes a status code.
 *
 * Shared by both administration controllers rather than written twice, because the
 * codes are the half of the answer a script reads and two copies would drift: the
 * same refusal answering 409 on one screen and 422 on the other is a difference no
 * test would notice and every client would.
 *
 * The distinctions are what make this worth a class. 404 means there is nothing
 * there; 409 means the request was understood and the system refuses because of its
 * own state — change the state, not the request; 422 means change what you sent.
 * A browser shows the sentence either way, but a script can only act on the number.
 */
final class AdministrationRefusalStatus
{
    public static function of(AdministrationRefusal $reason): int
    {
        return match ($reason) {
            AdministrationRefusal::UnknownUser,
            AdministrationRefusal::UnknownInvitation => Response::HTTP_NOT_FOUND,
            AdministrationRefusal::MalformedEmail => Response::HTTP_UNPROCESSABLE_ENTITY,
            // Every one of these is a state conflict, not a malformed request. The
            // body sent was perfectly valid; the installation is what refuses.
            AdministrationRefusal::SelfDemotion,
            AdministrationRefusal::LastAdministrator,
            AdministrationRefusal::SelfDeactivation,
            AdministrationRefusal::AccountExists,
            AdministrationRefusal::InvitationPending,
            AdministrationRefusal::InvitationAccepted => Response::HTTP_CONFLICT,
        };
    }
}
