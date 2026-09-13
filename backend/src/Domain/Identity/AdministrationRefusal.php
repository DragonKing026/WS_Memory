<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/**
 * Why an administration operation on accounts or invitations was refused.
 *
 * A fact, not a status code. The REST surface maps these to 404, 409 and 422
 * (AdministrationRefusalStatus); the console maps them to a printed error and a
 * non-zero exit, and neither mapping belongs in the domain.
 *
 * The two that matter most are LastAdministrator and SelfDemotion, and they are
 * separate cases even though both come out of one endpoint as 409. An installation
 * that lost its last administrator cannot be repaired from the application at all
 * — there is nobody left to grant the role back or to invite anyone — while an
 * administrator who took the role off themselves while a colleague still has it
 * merely has to go and ask.
 */
enum AdministrationRefusal
{
    case UnknownUser;
    case SelfDemotion;
    case LastAdministrator;
    case SelfDeactivation;
    case MalformedEmail;
    case AccountExists;
    case InvitationPending;
    case UnknownInvitation;
    case InvitationAccepted;
}
