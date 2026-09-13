<?php

declare(strict_types=1);

namespace App\Presentation\Console;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Finding the account a console command was pointed at.
 *
 * Three commands now take an e-mail address as their first argument, and each of
 * them normalises it, looks it up and says the same thing when there is nothing
 * there. Written out three times, the normalisation is what would drift: an address
 * typed with a capital letter would work in one command and not in the next, and
 * nobody would suspect the lookup.
 *
 * A DomainException rather than null, so the commands handle it the way they already
 * handle a refusal from the use case they call — one catch, one exit code.
 */
final readonly class AccountLookup
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @throws \DomainException when no account has this address
     */
    public function byEmail(string $email): User
    {
        $user = $this->entityManager->getRepository(User::class)
            ->findOneBy(['email' => strtolower(trim($email))]);

        if (!$user instanceof User) {
            throw new \DomainException(\sprintf(
                'Nie ma konta %s. Najpierw zaproś je przez ws:user:invite.',
                $email,
            ));
        }

        return $user;
    }
}
