<?php

declare(strict_types=1);

namespace App\Domain\Space;

/**
 * Why a membership change was refused, as a fact rather than as a status code.
 *
 * The domain has no opinion about HTTP; the REST surface maps these to 404, 409 and
 * 422 in one readable place, and a console command would be free to map them to
 * exit codes. Same arrangement as UpdateRefusal, and for the same reason: the
 * mapping belongs to whoever knows what a status code is.
 *
 * UnknownSpace and UnknownMember are separate cases even though both end up as 404.
 * The sentences differ, and so does what the reader should do next — reload the list
 * of spaces, or reload the list of members of the space they are looking at.
 */
enum MembershipRefusal
{
    case UnknownSpace;
    case UnknownMember;
    case MalformedRole;
    case PrivateSpace;
    case LastAdministrator;
}
