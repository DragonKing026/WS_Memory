<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A person's account.
 *
 * Accounts are never deleted, only deactivated: revisions and audit entries
 * reference their author, and history that loses its author stops being
 * evidence of anything.
 */
#[ORM\Entity]
#[ORM\Table(name: 'users', schema: 'ws')]
#[ORM\Index(name: 'idx_users_email', columns: ['email'])]
#[UniqueEntity(fields: ['email'], message: 'Konto z tym adresem już istnieje.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    /**
     * The one spelling of the role that administers this installation.
     *
     * Named rather than repeated because it is asked about outside the entity too:
     * the account listing reads `roles` as raw jsonb and has to agree with
     * isGlobalAdmin() about who is an administrator. A listing that disagreed with
     * the permission check would be believed by whoever was looking at it.
     */
    public const GLOBAL_ADMIN_ROLE = 'ROLE_ADMIN';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    #[ORM\Column(name: 'password_hash', length: 255)]
    private string $passwordHash = '';

    #[ORM\Column(name: 'display_name', length: 120)]
    private string $displayName;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'last_login_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    public function __construct(string $email, string $displayName)
    {
        $normalised = strtolower(trim($email));
        if ('' === $normalised) {
            // The identifier the whole security layer keys on cannot be empty.
            // Guaranteeing it here means every consumer downstream may rely on
            // it, instead of each one re-checking.
            throw new \InvalidArgumentException('Adres e-mail konta nie może być pusty.');
        }

        $this->id = Uuid::v7();
        $this->email = $normalised;
        $this->displayName = $displayName;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    /** @return non-empty-string */
    public function getEmail(): string
    {
        \assert('' !== $this->email);

        return $this->email;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    /** @return non-empty-string */
    public function getUserIdentifier(): string
    {
        return $this->getEmail();
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        // ROLE_USER is implicit for everyone; storing it would only create a
        // second place where "is this account ordinary" can be answered.
        return array_values(array_unique([...$this->roles, 'ROLE_USER']));
    }

    public function isGlobalAdmin(): bool
    {
        return in_array(self::GLOBAL_ADMIN_ROLE, $this->roles, true);
    }

    public function promoteToGlobalAdmin(): void
    {
        if (!$this->isGlobalAdmin()) {
            $this->roles[] = self::GLOBAL_ADMIN_ROLE;
        }
    }

    /**
     * Takes the global role away, leaving every other role untouched.
     *
     * array_values because the column is typed as a list: unsetting an element in
     * place would leave a JSON object with numeric keys where the mapping promises
     * an array, and the next read would hydrate something no consumer expects.
     *
     * Whether this is *allowed* is not decided here. An installation left with no
     * administrator cannot be repaired from the application, and that rule needs
     * to see every account — see UserAdministration.
     */
    public function demoteFromGlobalAdmin(): void
    {
        $this->roles = array_values(array_filter(
            $this->roles,
            static fn (string $role): bool => self::GLOBAL_ADMIN_ROLE !== $role,
        ));
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    public function setPasswordHash(string $hash): void
    {
        $this->passwordHash = $hash;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function deactivate(): void
    {
        $this->isActive = false;
    }

    /**
     * Lets the account sign in again.
     *
     * Reactivation deliberately does NOT bring the account's agent tokens back:
     * deactivation revoked them (UserAdministration), and revocation keeps the
     * moment access ended. An account switched off and on again with its agents
     * quietly writing throughout would have been switched off only in appearance.
     */
    public function activate(): void
    {
        $this->isActive = true;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function recordLogin(): void
    {
        $this->lastLoginAt = new \DateTimeImmutable();
    }

    public function eraseCredentials(): void
    {
    }
}
