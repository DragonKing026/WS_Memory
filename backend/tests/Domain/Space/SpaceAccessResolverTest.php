<?php

declare(strict_types=1);

namespace App\Tests\Domain\Space;

use App\Domain\Identity\Actor;
use App\Domain\Space\SpaceAccessResolver;
use App\Domain\Space\SpaceId;
use App\Domain\Space\SpaceMembershipRepository;
use App\Domain\Space\SpaceRole;
use PHPUnit\Framework\TestCase;

/**
 * The single place that decides who may see what.
 *
 * These tests matter more than most: a bug here is silent. Nothing throws, no
 * log line appears — content simply reaches someone who should not have it.
 * The negative cases below are therefore the point of this file, and the
 * positive ones only guard against locking everyone out.
 */
final class SpaceAccessResolverTest extends TestCase
{
    private const OWNER = 'user-1';
    private const STRANGER = 'user-2';

    public function testStrangerHasNoRoleInSpaceTheyAreNotMemberOf(): void
    {
        $resolver = $this->resolverWith([self::OWNER => ['alfa' => SpaceRole::Writer]]);

        self::assertNull(
            $resolver->roleIn($this->human(self::STRANGER), new SpaceId('alfa')),
            'a non-member must have no role at all',
        );
    }

    public function testStrangerSeesNoSpaces(): void
    {
        $resolver = $this->resolverWith([self::OWNER => ['alfa' => SpaceRole::Writer]]);

        self::assertSame([], $resolver->allowedSpaces($this->human(self::STRANGER)));
    }

    public function testAgentTokenScopeNarrowsOwnerPermissions(): void
    {
        $resolver = $this->resolverWith([
            self::OWNER => ['alfa' => SpaceRole::Writer, 'hr' => SpaceRole::Reader],
        ]);

        $scoped = Actor::agent(self::OWNER, 'token-1', [new SpaceId('alfa')]);

        self::assertSame(['alfa'], $this->slugs($resolver->allowedSpaces($scoped)));
    }

    public function testAgentTokenScopeCannotWidenOwnerPermissions(): void
    {
        // The owner is a member of one space; the token names two. The extra
        // one must not appear — a scope is an intersection, never a union.
        $resolver = $this->resolverWith([self::OWNER => ['alfa' => SpaceRole::Writer]]);

        $overreaching = Actor::agent(
            self::OWNER,
            'token-1',
            [new SpaceId('alfa'), new SpaceId('hr')],
        );

        self::assertSame(['alfa'], $this->slugs($resolver->allowedSpaces($overreaching)));
        self::assertNull($resolver->roleIn($overreaching, new SpaceId('hr')));
    }

    public function testGlobalAdminDoesNotSilentlyReadSpacesTheyAreNotMemberOf(): void
    {
        // A global administrator manages accounts and spaces, but does not get
        // to read other people's content unnoticed. They can grant themselves
        // membership — and that action is recorded in the audit log, unlike a
        // silent read would be.
        $resolver = $this->resolverWith([self::OWNER => ['priv_user-1' => SpaceRole::Admin]]);

        $admin = Actor::human(self::STRANGER, isGlobalAdmin: true);

        self::assertFalse($resolver->canRead($admin, new SpaceId('priv_user-1')));
        self::assertSame([], $resolver->allowedSpaces($admin));
    }

    public function testReaderCannotWrite(): void
    {
        $resolver = $this->resolverWith([self::OWNER => ['alfa' => SpaceRole::Reader]]);
        $reader = $this->human(self::OWNER);

        self::assertTrue($resolver->canRead($reader, new SpaceId('alfa')));
        self::assertFalse($resolver->canWrite($reader, new SpaceId('alfa')));
        self::assertFalse($resolver->canAdminister($reader, new SpaceId('alfa')));
    }

    public function testWriterCannotAdminister(): void
    {
        $resolver = $this->resolverWith([self::OWNER => ['alfa' => SpaceRole::Writer]]);
        $writer = $this->human(self::OWNER);

        self::assertTrue($resolver->canWrite($writer, new SpaceId('alfa')));
        self::assertFalse($resolver->canAdminister($writer, new SpaceId('alfa')));
    }

    public function testAdministratorOfSpaceCanReadAndWriteIt(): void
    {
        $resolver = $this->resolverWith([self::OWNER => ['alfa' => SpaceRole::Admin]]);
        $admin = $this->human(self::OWNER);

        self::assertTrue($resolver->canRead($admin, new SpaceId('alfa')));
        self::assertTrue($resolver->canWrite($admin, new SpaceId('alfa')));
        self::assertTrue($resolver->canAdminister($admin, new SpaceId('alfa')));
    }

    public function testRevokingMembershipRemovesAccessImmediately(): void
    {
        $memberships = [self::OWNER => ['alfa' => SpaceRole::Writer]];
        $repository = new InMemorySpaceMembershipRepository($memberships);
        $resolver = new SpaceAccessResolver($repository);
        $user = $this->human(self::OWNER);

        self::assertTrue($resolver->canRead($user, new SpaceId('alfa')));

        $repository->revoke(self::OWNER, 'alfa');

        self::assertFalse(
            $resolver->canRead($user, new SpaceId('alfa')),
            'access must end the moment the role is revoked, with no cache in between',
        );
    }

    /** @param array<string, array<string, SpaceRole>> $memberships */
    private function resolverWith(array $memberships): SpaceAccessResolver
    {
        return new SpaceAccessResolver(new InMemorySpaceMembershipRepository($memberships));
    }

    private function human(string $userId): Actor
    {
        return Actor::human($userId);
    }

    /**
     * @param list<SpaceId> $spaces
     *
     * @return list<string>
     */
    private function slugs(array $spaces): array
    {
        return array_map(static fn (SpaceId $s): string => $s->value, $spaces);
    }
}

/**
 * A hand-written in-memory double rather than a mock: these tests assert on
 * real resolver behaviour, and a mock would only assert that we called it.
 */
final class InMemorySpaceMembershipRepository implements SpaceMembershipRepository
{
    /** @param array<string, array<string, SpaceRole>> $memberships */
    public function __construct(private array $memberships)
    {
    }

    public function rolesFor(string $userId): array
    {
        return $this->memberships[$userId] ?? [];
    }

    public function revoke(string $userId, string $spaceSlug): void
    {
        unset($this->memberships[$userId][$spaceSlug]);
    }
}
