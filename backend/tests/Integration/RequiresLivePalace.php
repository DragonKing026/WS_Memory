<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Infrastructure\MemPalace\MemPalaceClient;
use App\Infrastructure\MemPalace\MemPalaceHealthProbe;
use Doctrine\DBAL\Connection;

/**
 * What every test against a real palace needs: a skip when it is absent, and a
 * broom when it is not.
 *
 * The skip answer is cached for the whole process, and that is the point of putting it
 * in a trait. Asking once per test looked harmless until `make test` ran with the
 * sidecar stopped: Docker's DNS does not refuse a stopped container's name, it
 * **times out**, so every check cost three seconds and the suite spent over two
 * minutes deciding to skip. One check per run answers the same question.
 *
 * The cleanup lives here for the same reason: three copies of it would be three
 * places to forget it in, and forgetting it is exactly what happened — see the
 * numbers over self::deleteDrawersFiledByThisTest().
 */
trait RequiresLivePalace
{
    /** null = not asked yet. Per process, deliberately. */
    private static ?bool $palaceAnswers = null;

    /**
     * Drawers this test filed straight through the palace client, so the registry
     * has never heard of them.
     *
     * @var list<string>
     */
    private array $drawersOutsideTheRegistry = [];

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

    /**
     * Books a drawer for deletion that `ws.memory_entries` will never mention.
     *
     * For content pushed straight at the palace, bypassing our own write path —
     * which is a thing a couple of these tests do on purpose, to prove that
     * unregistered content is not handed out.
     */
    protected function alsoDeleteDrawer(string $drawerId): void
    {
        $this->drawersOutsideTheRegistry[] = $drawerId;
    }

    /**
     * Deletes from the palace every drawer this test filed there.
     *
     * **Why this is not optional.** Measured on 2026-09-13, the server's palace held
     * over 1500 drawers, and practically all of it was test litter: **872 wings named
     * `test-integracja-*`** plus **128 orphaned `priv_<uuid>` wings** left behind by
     * test users. Real content: zero. It accumulated in a few days, because every run
     * wrote and nothing ever deleted. Do not "simplify" this away.
     *
     * Two details decide the shape, and each is a simpler version that does not work:
     *
     *  - **Deleting the run's own wing is not enough.** A run does file into its own
     *    `test-…-<hex>` wing, but a write that names no space lands in the author's
     *    private space instead (inviolable rule 6) — which is where those 128
     *    `priv_<uuid>` wings came from.
     *  - **`ws.memory_entries` is the register of what a run actually wrote**, wherever
     *    it landed. In tearDown it is still intact: what clears it is the *next* test's
     *    setUp. `kg_fact` rows are skipped, because their `drawer_id` is derived from
     *    the fact itself (DrawerId::forFact) and names no drawer the palace ever had.
     *
     * Deletion goes through the palace's own API and never SQL against the `palace`
     * schema (D-004). That rule covers tests as well.
     *
     * A failure here never fails the test — a palace that stopped answering at the end
     * of a run says nothing about the code under test — but it is printed on stderr
     * together with the wing's name, because a **silent** cleanup failure is precisely
     * the mechanism that produced the thousand wings above.
     *
     * @param Connection $registry the `ws` connection, i.e. where ws.memory_entries lives
     * @param string     $wing     this run's own wing, for the message when cleanup fails
     */
    protected function deleteDrawersFiledByThisTest(
        Connection $registry,
        MemPalaceClient $palace,
        string $wing,
    ): void {
        $drawers = $this->drawersOutsideTheRegistry;
        $this->drawersOutsideTheRegistry = [];

        try {
            foreach ($registry->fetchFirstColumn("SELECT drawer_id FROM ws.memory_entries WHERE kind <> 'kg_fact'") as $id) {
                if (\is_string($id)) {
                    $drawers[] = $id;
                }
            }
        } catch (\Throwable $e) {
            self::reportCleanupFailure($wing, 'nie udało się odczytać rejestru: ' . $e->getMessage());
        }

        $problems = [];
        foreach (array_unique($drawers) as $drawer) {
            try {
                // tryCall, not call: a tool error here is data, and an already
                // absent drawer is a success from where we stand.
                $outcome = $palace->tryCall('mempalace_delete_drawer', ['drawer_id' => $drawer]);

                if ($outcome->failed() && !$outcome->isMissing()) {
                    $problems[] = $drawer . ': ' . (string) $outcome->error;
                }
            } catch (\Throwable $e) {
                $problems[] = $drawer . ': ' . $e->getMessage();
            }
        }

        if ([] !== $problems) {
            self::reportCleanupFailure($wing, implode('; ', $problems));
        }
    }

    private static function reportCleanupFailure(string $wing, string $reason): void
    {
        // stderr rather than a logger: nobody reads a test run's log, and the whole
        // point is that this cannot pass unnoticed.
        fwrite(
            \STDERR,
            \sprintf("\n[sprzątanie pałaca] skrzydło %s zostaje z szufladami — %s\n", $wing, $reason),
        );
    }
}
