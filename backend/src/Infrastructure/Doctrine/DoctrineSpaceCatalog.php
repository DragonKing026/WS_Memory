<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use App\Domain\Memory\PalaceWing;
use App\Domain\Space\SpaceCatalog;
use App\Domain\Space\SpaceId;
use Doctrine\DBAL\Connection;

/**
 * Adapter: what a space is, read straight from `ws.spaces`.
 *
 * The private-space lookup checks BOTH the slug convention and the `is_private`
 * flag. Either alone would be enough to find the row; together they make a
 * mistake impossible to exploit. A space someone manually named `priv_<uuid>`
 * without the flag is not a private space, and a write that names no space must
 * not land in it (inviolable rule 6).
 */
final readonly class DoctrineSpaceCatalog implements SpaceCatalog
{
    public function __construct(private Connection $connection)
    {
    }

    public function wingFor(SpaceId $space): ?PalaceWing
    {
        $wing = $this->connection->fetchOne(
            'SELECT palace_wing FROM ws.spaces WHERE slug = :slug',
            ['slug' => $space->value],
        );

        return \is_string($wing) && '' !== trim($wing) ? new PalaceWing($wing) : null;
    }

    public function privateSpaceOf(string $userId): ?SpaceId
    {
        $expected = SpaceId::privateFor($userId);

        $slug = $this->connection->fetchOne(
            'SELECT slug FROM ws.spaces WHERE slug = :slug AND is_private = true',
            ['slug' => $expected->value],
        );

        return \is_string($slug) ? new SpaceId($slug) : null;
    }
}
