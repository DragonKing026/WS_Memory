<?php

declare(strict_types=1);

namespace App\Domain\Health;

/**
 * Status of a single dependency — a value object.
 *
 * A dedicated type rather than an array: this status travels across layers,
 * and an associative array loses track of what it actually contains.
 */
final readonly class DependencyStatus
{
    private function __construct(
        public bool $available,
        public ?string $error = null,
    ) {
    }

    public static function available(): self
    {
        return new self(true);
    }

    public static function unavailable(string $error): self
    {
        return new self(false, $error);
    }

    /**
     * @return array{available: bool, error?: string}
     */
    public function toArray(): array
    {
        return null === $this->error
            ? ['available' => $this->available]
            : ['available' => $this->available, 'error' => $this->error];
    }
}
