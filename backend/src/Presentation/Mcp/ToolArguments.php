<?php

declare(strict_types=1);

namespace App\Presentation\Mcp;

use App\Domain\Space\SpaceId;

/**
 * Typed, validated access to whatever an agent sent.
 *
 * Written once rather than per tool, because the failure it prevents is the same
 * every time and is silent: a model that sends `limit: "5"` or `spaces: "alfa"`
 * gets a clear refusal instead of a mysterious result.
 *
 * The part worth reading is rejectUnknown(). An unrecognised argument is an
 * **error**, not something to ignore — because the argument an agent is most
 * likely to invent is `wing`, having learned it from MemPalace's own tools, and
 * silently dropping it would let the agent believe it had filtered its search
 * when it had not. Being told "no such parameter" costs one retry; being ignored
 * costs a wrong conclusion about what it just read.
 */
final readonly class ToolArguments
{
    /** @param array<string, mixed> $raw */
    public function __construct(private array $raw)
    {
    }

    /**
     * @param list<string> $known
     *
     * @throws McpError
     */
    public function rejectUnknown(array $known): void
    {
        $unknown = array_diff(array_keys($this->raw), $known);

        if ([] !== $unknown) {
            throw McpError::invalidParams(\sprintf(
                'Nieznane parametry: %s. Dozwolone: %s.',
                implode(', ', $unknown),
                implode(', ', $known),
            ));
        }
    }

    public function requiredString(string $key, int $maxLength = 100_000): string
    {
        $value = $this->optionalString($key, $maxLength);

        if (null === $value) {
            throw McpError::invalidParams(\sprintf('Parametr „%s" jest wymagany i musi być niepustym tekstem.', $key));
        }

        return $value;
    }

    public function optionalString(string $key, int $maxLength = 100_000): ?string
    {
        if (!isset($this->raw[$key])) {
            return null;
        }

        $value = $this->raw[$key];
        if (!\is_string($value)) {
            throw McpError::invalidParams(\sprintf('Parametr „%s" musi być tekstem.', $key));
        }

        $value = trim($value);
        if ('' === $value) {
            return null;
        }

        if (mb_strlen($value) > $maxLength) {
            throw McpError::invalidParams(\sprintf(
                'Parametr „%s" jest dłuższy niż %d znaków.',
                $key,
                $maxLength,
            ));
        }

        return $value;
    }

    public function optionalBool(string $key): ?bool
    {
        if (!isset($this->raw[$key])) {
            return null;
        }

        $value = $this->raw[$key];
        if (!\is_bool($value)) {
            // Deliberately strict: "false" and 0 are both truthy-looking strings in
            // a JSON payload, and a flag read the wrong way round is a silent bug.
            throw McpError::invalidParams(\sprintf('Parametr „%s" musi być true albo false.', $key));
        }

        return $value;
    }

    public function optionalInt(string $key, int $min, int $max): ?int
    {
        if (!isset($this->raw[$key])) {
            return null;
        }

        $value = $this->raw[$key];
        if (!\is_int($value)) {
            throw McpError::invalidParams(\sprintf('Parametr „%s" musi być liczbą całkowitą.', $key));
        }

        if ($value < $min || $value > $max) {
            throw McpError::invalidParams(\sprintf('Parametr „%s" musi być z zakresu %d–%d.', $key, $min, $max));
        }

        return $value;
    }

    public function optionalFloat(string $key, float $min, float $max): ?float
    {
        if (!isset($this->raw[$key])) {
            return null;
        }

        $value = $this->raw[$key];
        if (!\is_int($value) && !\is_float($value)) {
            throw McpError::invalidParams(\sprintf('Parametr „%s" musi być liczbą.', $key));
        }

        $value = (float) $value;
        if ($value < $min || $value > $max) {
            throw McpError::invalidParams(\sprintf('Parametr „%s" musi być z zakresu %s–%s.', $key, $min, $max));
        }

        return $value;
    }

    /**
     * @param list<string> $allowed
     */
    public function optionalEnum(string $key, array $allowed): ?string
    {
        $value = $this->optionalString($key);

        if (null !== $value && !\in_array($value, $allowed, true)) {
            throw McpError::invalidParams(\sprintf(
                'Parametr „%s" przyjmuje wartości: %s.',
                $key,
                implode(', ', $allowed),
            ));
        }

        return $value;
    }

    /**
     * @return list<string>|null
     */
    public function optionalStringList(string $key): ?array
    {
        if (!isset($this->raw[$key])) {
            return null;
        }

        $value = $this->raw[$key];
        if (!\is_array($value) || !array_is_list($value)) {
            throw McpError::invalidParams(\sprintf('Parametr „%s" musi być listą tekstów.', $key));
        }

        $items = [];
        foreach ($value as $item) {
            if (!\is_string($item) || '' === trim($item)) {
                throw McpError::invalidParams(\sprintf('Parametr „%s" zawiera element, który nie jest tekstem.', $key));
            }

            $items[] = trim($item);
        }

        return $items;
    }

    /**
     * Spaces as identifiers, or null when the agent named none.
     *
     * Null and an empty list mean different things downstream: null is "wherever
     * I may look", an empty list is "nowhere", and the second is a legitimate
     * thing for a caller to ask even though the answer is always empty.
     *
     * @return list<SpaceId>|null
     */
    public function optionalSpaces(string $key = 'spaces'): ?array
    {
        $slugs = $this->optionalStringList($key);

        return null === $slugs
            ? null
            : array_map(static fn (string $slug): SpaceId => new SpaceId($slug), $slugs);
    }

    public function optionalDate(string $key): ?\DateTimeImmutable
    {
        $value = $this->optionalString($key, 40);
        if (null === $value) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw McpError::invalidParams(\sprintf(
                'Parametr „%s" nie jest datą (oczekiwane RRRR-MM-DD albo RRRR-MM-DDTGG:MM:SS).',
                $key,
            ));
        }
    }
}
