<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Application\Invitation\AcceptInvitation;
use App\Application\Invitation\IssueInvitation;
use App\Domain\Space\SpaceRole;
use App\Entity\Space;
use App\Entity\SpaceMember;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * What the API refuses, and how it refuses it.
 *
 * The distinction between 404 and 403 is the point of this file. "You have no
 * access to the HR space" confirms that an HR space exists and that it is worth
 * asking about — so a space outside your permissions must be indistinguishable
 * from a space that does not exist.
 */
final class SpaceAccessTest extends WebTestCase
{
    private const PASSWORD = 'DlugieHaslo123!x';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $member;
    private Space $teamSpace;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $this->em->getConnection()->executeStatement(
            'TRUNCATE ws.space_members, ws.invitations, ws.audit_log, ws.spaces, ws.users CASCADE'
        );

        $issue = $container->get(IssueInvitation::class);
        $accept = $container->get(AcceptInvitation::class);

        $this->member = ($accept)(($issue)('czlonek@web-systems.pl')->plainToken, 'Członek', self::PASSWORD);
        ($accept)(($issue)('obcy@web-systems.pl')->plainToken, 'Obcy', self::PASSWORD);

        $this->teamSpace = new Space('alfa', 'Alfa');
        $this->em->persist($this->teamSpace);
        $this->em->persist(new SpaceMember($this->teamSpace, $this->member, SpaceRole::Writer));
        $this->em->flush();
    }

    public function testMemberSeesTheSpace(): void
    {
        $this->get('/api/spaces/alfa', $this->tokenFor('czlonek@web-systems.pl'));

        self::assertResponseIsSuccessful();
        self::assertSame('alfa', $this->json()['slug']);
        self::assertSame('writer', $this->json()['role']);
    }

    public function testStrangerGetsNotFoundRatherThanForbidden(): void
    {
        $this->get('/api/spaces/alfa', $this->tokenFor('obcy@web-systems.pl'));

        self::assertResponseStatusCodeSame(
            404,
            'a space outside your permissions must look exactly like one that does not exist',
        );
    }

    public function testNonExistentSpaceAnswersIdenticallyToAForbiddenOne(): void
    {
        $strangerToken = $this->tokenFor('obcy@web-systems.pl');

        $this->get('/api/spaces/alfa', $strangerToken);
        $forbidden = $this->client->getResponse()->getContent();

        $this->get('/api/spaces/nie-ma-takiej', $strangerToken);
        $missing = $this->client->getResponse()->getContent();

        self::assertSame(
            $forbidden,
            $missing,
            'the two answers must be byte-identical, or the difference itself discloses existence',
        );
    }

    public function testSpaceListContainsOnlyOwnSpaces(): void
    {
        $this->get('/api/spaces', $this->tokenFor('obcy@web-systems.pl'));

        self::assertResponseIsSuccessful();
        $slugs = array_column($this->json()['spaces'], 'slug');

        self::assertNotContains('alfa', $slugs);
        self::assertCount(1, $slugs, 'only their own private space');
    }

    public function testRevokingMembershipCutsAccessImmediately(): void
    {
        $token = $this->tokenFor('czlonek@web-systems.pl');

        $this->get('/api/spaces/alfa', $token);
        self::assertResponseIsSuccessful();

        $this->em->getConnection()->executeStatement(
            'DELETE FROM ws.space_members WHERE space_id = :space',
            ['space' => $this->teamSpace->getId()->toRfc4122()],
        );

        $this->get('/api/spaces/alfa', $token);
        self::assertResponseStatusCodeSame(
            404,
            'a still-valid token must not outlive the membership it depended on',
        );
    }

    public function testAcceptingInvitationThroughTheApiCreatesAnAccount(): void
    {
        $issued = static::getContainer()->get(IssueInvitation::class)('przez-api@web-systems.pl');

        $this->postJson('/api/invitations/accept', [
            'token' => $issued->plainToken,
            'displayName' => 'Przez API',
            'password' => self::PASSWORD,
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('przez-api@web-systems.pl', $this->json()['email']);
    }

    public function testShortPasswordIsRefused(): void
    {
        $issued = static::getContainer()->get(IssueInvitation::class)('krotkie@web-systems.pl');

        $this->postJson('/api/invitations/accept', [
            'token' => $issued->plainToken,
            'displayName' => 'Krótkie',
            'password' => 'Krotkie1!',
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testInvalidInvitationTokenIsRefused(): void
    {
        $this->postJson('/api/invitations/accept', [
            'token' => 'nie-ma-takiego-tokena',
            'displayName' => 'Nikt',
            'password' => self::PASSWORD,
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    private function tokenFor(string $email): string
    {
        $this->postJson('/api/login', ['email' => $email, 'password' => self::PASSWORD]);

        return $this->json()['token'];
    }

    private function get(string $uri, string $token): void
    {
        $this->client->request('GET', $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
    }

    /** @param array<string, mixed> $payload */
    private function postJson(string $uri, array $payload): void
    {
        $this->client->request(
            'POST',
            $uri,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }
}
