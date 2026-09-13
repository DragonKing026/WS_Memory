<?php

declare(strict_types=1);

namespace App\Domain\Dependency;

/**
 * Whether there is anything on the host able to carry an update out.
 *
 * Two booleans that look redundant and are not. `installed` says a pulse was heard
 * at some point — the deployment happened. `healthy` says one was heard recently.
 * The case worth catching is exactly the gap between them: an agent installed months
 * ago whose systemd timer has been failing since, which reads as "zainstalowany" in
 * every sense a human would check and cannot actually do anything.
 *
 * The freshness window is the decision this class exists to hold in one place. It is
 * used twice — once to tell the panel whether to offer a button, once to refuse an
 * order that would otherwise sit pending forever — and two copies of a five-minute
 * rule would eventually be four and seven.
 */
final readonly class UpdaterState
{
    /**
     * How recently the agent must have reported to count as alive.
     *
     * The host's timer ticks every minute, so five minutes is four missed runs: long
     * enough that a single slow tick or a rebuild holding the console busy does not
     * black the button out, short enough that a dead agent is noticed while the
     * administrator is still looking at the screen.
     */
    public const FRESH_FOR_SECONDS = 5 * 60;

    private function __construct(
        public ?\DateTimeImmutable $lastHeartbeat,
        public bool $healthy,
    ) {
    }

    public static function from(?\DateTimeImmutable $lastHeartbeat, \DateTimeImmutable $now): self
    {
        $healthy = null !== $lastHeartbeat
            && $now->getTimestamp() - $lastHeartbeat->getTimestamp() <= self::FRESH_FOR_SECONDS;

        return new self($lastHeartbeat, $healthy);
    }

    /**
     * Whether an agent was ever here.
     *
     * Derived from the pulse rather than from configuration, because configuration
     * would say what the deployment intended and this has to say what happened.
     */
    public function installed(): bool
    {
        return null !== $this->lastHeartbeat;
    }

    /**
     * @return array{installed: bool, lastHeartbeat: string|null, healthy: bool}
     */
    public function toArray(): array
    {
        return [
            'installed' => $this->installed(),
            'lastHeartbeat' => $this->lastHeartbeat?->format(\DateTimeInterface::ATOM),
            'healthy' => $this->healthy,
        ];
    }
}
