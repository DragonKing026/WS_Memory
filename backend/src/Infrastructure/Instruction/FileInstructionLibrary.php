<?php

declare(strict_types=1);

namespace App\Infrastructure\Instruction;

use App\Domain\Instruction\Instruction;
use App\Domain\Instruction\InstructionLibrary;
use App\Domain\Instruction\InstructionText;
use App\Domain\Instruction\InstructionUnavailable;
use App\Domain\Instruction\UnknownInstruction;

/**
 * The instruction texts read from `plugin/shared/`, the single source of them all.
 *
 * The directory is a parameter rather than a path built into the code, because it
 * sits somewhere different in each environment: a sibling of `backend/` when the
 * test suite runs on a host, a read-only mount in the containers, a copy baked
 * into the production image. See `WS_INSTRUCTIONS_DIR` in docs/05-deployment.md.
 */
final class FileInstructionLibrary implements InstructionLibrary
{
    /**
     * Markdown, declared as such: a client that knows the type renders the
     * document instead of showing an agent a wall of asterisks.
     */
    private const MIME_TYPE = 'text/markdown';

    /**
     * The whole published catalogue, written out.
     *
     * **Deliberately not a directory scan.** A scan publishes whatever happens to
     * land in the directory — a draft, a copy left by an editor, a file somebody
     * dropped there to look at — and it publishes it to every agent that connects.
     * Adding a resource has to be a visible change in this file.
     *
     * The title is here rather than taken from the document's heading: several of
     * these files begin with prose, and "the first line of the file" is not a
     * contract anybody maintains.
     *
     * @var array<string, array{file: string, title: string}>
     */
    private const PUBLISHED = [
        'ws-memory://protokol-recall' => [
            'file' => 'protokol-recall.md',
            'title' => 'Protokół recall — szukaj, zanim odpowiesz',
        ],
        'ws-memory://jak-dokumentowac' => [
            'file' => 'jak-dokumentowac.md',
            'title' => 'Jak pisać do firmowej bazy wiedzy',
        ],
        'ws-memory://konfiguracja' => [
            'file' => 'konfiguracja.md',
            'title' => 'Podłączenie klienta AI do WS_Memory',
        ],
        'ws-memory://agenci/ws-recall' => [
            'file' => 'agenci/ws-recall.md',
            'title' => 'Podagent ws-recall — całość ustaleń przed decyzją',
        ],
        'ws-memory://agenci/ws-dokumentalista' => [
            'file' => 'agenci/ws-dokumentalista.md',
            'title' => 'Podagent ws-dokumentalista — spisanie wyniku zadania',
        ],
        'ws-memory://agenci/ws-archiwista' => [
            'file' => 'agenci/ws-archiwista.md',
            'title' => 'Podagent ws-archiwista — porządki przez propozycje',
        ],
        'ws-memory://agenci/ws-onboarding' => [
            'file' => 'agenci/ws-onboarding.md',
            'title' => 'Podagent ws-onboarding — odpowiedzi wyłącznie z bazy',
        ],
    ];

    /**
     * Read once per request: listing resources touches every published file.
     *
     * @var array<string, InstructionText>
     */
    private array $loaded = [];

    public function __construct(
        private readonly string $directory,
    ) {
    }

    public function all(): array
    {
        $instructions = [];
        foreach (array_keys(self::PUBLISHED) as $uri) {
            $instructions[] = $this->load($uri)->instruction;
        }

        return $instructions;
    }

    public function read(string $uri): InstructionText
    {
        if (!isset(self::PUBLISHED[$uri])) {
            throw UnknownInstruction::uri($uri);
        }

        return $this->load($uri);
    }

    /**
     * @throws InstructionUnavailable
     */
    private function load(string $uri): InstructionText
    {
        if (isset($this->loaded[$uri])) {
            return $this->loaded[$uri];
        }

        $entry = self::PUBLISHED[$uri];
        $path = rtrim($this->directory, '/') . '/' . $entry['file'];

        if (!is_file($path) || !is_readable($path)) {
            // Loud, never an empty document: the likely cause is a container
            // started without the mount, and an agent served a blank protocol
            // behaves as if there were no protocol.
            throw InstructionUnavailable::source($uri, $path, 'nie ma takiego pliku albo jest nieczytelny');
        }

        $raw = file_get_contents($path);
        if (false === $raw) {
            throw InstructionUnavailable::source($uri, $path, 'odczyt pliku zawiódł');
        }

        [$fields, $body] = self::split($raw);

        $instruction = new Instruction(
            uri: $uri,
            // The front matter's `name` is the handle the plugin packagings use,
            // so an agent seeing the resource and the skill sees one name. The
            // fallback keeps a file without front matter usable.
            name: $fields['name'] ?? self::handleFrom($uri),
            title: $entry['title'],
            description: $fields['description'] ?? $entry['title'],
            mimeType: self::MIME_TYPE,
        );

        return $this->loaded[$uri] = new InstructionText($instruction, $body);
    }

    /**
     * Splits YAML front matter from the body.
     *
     * Hand-written rather than pulled in with a YAML parser: the fields we read
     * are two flat strings, and a dependency needs a decision (AGENTS.md). It has
     * to survive a file with no front matter at all, which is why the pattern is
     * anchored and a miss returns the document untouched instead of eating its
     * first paragraph.
     *
     * The front matter is dropped from the body on purpose. It is packaging
     * metadata — `name`, `description`, whatever an editor added — and passing it
     * on would spend the agent's context on fields meant for a plugin loader.
     *
     * @return array{array<string, string>, string} the fields, and the body
     */
    private static function split(string $raw): array
    {
        $text = str_replace("\r\n", "\n", $raw);

        // A closing delimiter on its own line, the shortest match: a horizontal
        // rule later in the document must not be mistaken for the end of the
        // block. `\A` and not `^`, or any line starting with --- would qualify.
        if (1 !== preg_match("/\A---\n(.*?)\n---[ \t]*(?:\n|\z)/s", $text, $matched)) {
            return [[], $text];
        }

        $fields = [];
        foreach (explode("\n", $matched[1]) as $line) {
            $colon = strpos($line, ':');
            if (false === $colon || 0 === $colon) {
                continue;
            }

            $key = trim(substr($line, 0, $colon));
            $value = trim(substr($line, $colon + 1));

            // Flat `key: value` only. Anything nested, listed or indented is
            // skipped rather than guessed at — a wrong guess would show up as a
            // resource description, where nobody would look for it.
            if ('' === $value || 1 !== preg_match('/\A[A-Za-z_][A-Za-z0-9_-]*\z/', $key)) {
                continue;
            }

            $fields[$key] = trim($value, "\"'");
        }

        return [$fields, ltrim(substr($text, \strlen($matched[0])), "\n")];
    }

    private static function handleFrom(string $uri): string
    {
        $segments = explode('/', $uri);

        return (string) end($segments);
    }
}
