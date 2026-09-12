<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Infrastructure\MemPalace\MemPalaceHealthProbe;

/**
 * Skips a test class when there is no palace to talk to.
 *
 * The answer is cached for the whole process, and that is the point of putting it
 * in a trait. Asking once per test looked harmless until `make test` ran with the
 * sidecar stopped: Docker's DNS does not refuse a stopped container's name, it
 * **times out**, so every check cost three seconds and the suite spent over two
 * minutes deciding to skip. One check per run answers the same question.
 */
trait RequiresLivePalace
{
    /** null = not asked yet. Per process, deliberately. */
    private static ?bool $palaceAnswers = null;

    private static function skipUnlessPalaceAnswers(MemPalaceHealthProbe $probe): void
    {
        self::$palaceAnswers ??= $probe->check()->available;

        if (false === self::$palaceAnswers) {
            self::markTestSkipped(
                'Pałac nie odpowiada — pomijam test integracyjny. '
                . 'Uruchom `docker compose up -d mempalace embeddings`, żeby go wykonać.',
            );
        }
    }
}
