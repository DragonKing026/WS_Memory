<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Instruction;

use App\Domain\Instruction\InstructionLibrary;
use App\Domain\Instruction\InstructionUnavailable;
use App\Domain\Instruction\UnknownInstruction;
use App\Infrastructure\Instruction\FileInstructionLibrary;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The adapter against the real `plugin/shared/`, and against files built for the
 * cases that directory does not contain.
 *
 * The first test is the completion criterion of TODO-009 — "the MCP resources
 * agree with the content in shared/" — so it compares with the actual file rather
 * than with a copy pasted in here. A pasted copy would keep passing on the day
 * somebody edits the instruction, which is the only day this test matters.
 *
 * It reaches the directory through the container parameter instead of building a
 * path of its own: that is the same value the application uses, and it is the
 * value that differs between a host run and a container (`WS_INSTRUCTIONS_DIR`).
 */
final class FileInstructionLibraryTest extends KernelTestCase
{
    /** @var list<string> */
    private array $temporary = [];

    protected function tearDown(): void
    {
        foreach ($this->temporary as $directory) {
            self::removeDirectory($directory);
        }

        $this->temporary = [];

        parent::tearDown();
    }

    public function testEveryPublishedInstructionIsTheBodyOfItsFileInPluginShared(): void
    {
        $library = $this->fromContainer();
        $directory = $this->configuredDirectory();

        $published = $library->all();
        self::assertNotSame([], $published);

        foreach ($published as $instruction) {
            $text = $library->read($instruction->uri)->text;
            $raw = $this->rawFileBehind($instruction->uri, $directory);

            if ('' === trim($text)) {
                self::fail($instruction->uri . ' jest pusty');
            }

            self::assertStringStartsWith(
                "---\n",
                $raw,
                'plik źródłowy ma frontmatter — bez niego ten test nie sprawdza wycinania',
            );

            // The body is the tail of the file, byte for byte. Anything dropped
            // from the middle or the end of the instruction fails here.
            self::assertStringEndsWith($text, $raw, $instruction->uri . ' nie zgadza się z plikiem w plugin/shared');

            // The wrapper's metadata is not instruction for a model and must not
            // reach its context.
            self::assertStringNotContainsString('description:', $text, $instruction->uri . ' nosi frontmatter w treści');
            self::assertStringNotContainsString('noteId:', $text, $instruction->uri . ' nosi frontmatter w treści');
        }
    }

    public function testDescriptionOnTheListComesFromTheFrontMatterOfTheFile(): void
    {
        $library = $this->fromContainer();
        $raw = $this->rawFileBehind('ws-memory://protokol-recall', $this->configuredDirectory());

        preg_match('/^description:\s*"?(.+?)"?\s*$/m', $raw, $matched);
        $fromFile = $matched[1] ?? null;
        self::assertIsString($fromFile, 'plik źródłowy nie ma opisu we frontmatterze');

        $described = [];
        foreach ($library->all() as $instruction) {
            $described[$instruction->uri] = $instruction->description;
        }

        self::assertSame($fromFile, $described['ws-memory://protokol-recall']);
    }

    public function testNamesOnTheListMatchTheHandlesThePluginUses(): void
    {
        $names = [];
        foreach ($this->fromContainer()->all() as $instruction) {
            $names[$instruction->uri] = $instruction->name;
        }

        // One instruction, one name, whichever way an agent meets it — as a skill
        // shipped by the plugin or as a resource served by the gateway.
        self::assertSame('ws-memory-recall', $names['ws-memory://protokol-recall']);
        self::assertSame('ws-onboarding', $names['ws-memory://agenci/ws-onboarding']);
    }

    public function testADocumentWithoutFrontMatterIsServedWhole(): void
    {
        $library = $this->withFiles(['protokol-recall.md' => "# Bez frontmatteru\n\nTreść.\n"]);

        $read = $library->read('ws-memory://protokol-recall');

        self::assertSame("# Bez frontmatteru\n\nTreść.\n", $read->text, 'brak frontmatteru nie może zjeść pierwszego akapitu');
        // With nothing to take a description from, the title stands in — a blank
        // description on the list tells a client nothing about the resource.
        self::assertNotSame('', $read->instruction->description);
        self::assertSame('protokol-recall', $read->instruction->name);
    }

    public function testAHorizontalRuleInTheBodyIsNotTakenForTheEndOfTheFrontMatter(): void
    {
        $library = $this->withFiles([
            'protokol-recall.md' => "---\nname: ws-memory-recall\ndescription: Opis\n---\n\nPierwszy akapit.\n\n---\n\nDrugi.\n",
        ]);

        $read = $library->read('ws-memory://protokol-recall');

        self::assertSame("Pierwszy akapit.\n\n---\n\nDrugi.\n", $read->text);
        self::assertSame('Opis', $read->instruction->description);
    }

    public function testFrontMatterClosedAfterABlankLineIsStillFrontMatter(): void
    {
        // How the files in plugin/shared actually look: an editor left a blank
        // line before the closing delimiter.
        $library = $this->withFiles([
            'protokol-recall.md' => "---\nnoteId: \"894f53d1\"\ntags: []\nname: \"ws-memory-recall\"\ndescription: \"Opis z cudzysłowami\"\n\n---\n\n# Nagłówek\n",
        ]);

        $read = $library->read('ws-memory://protokol-recall');

        self::assertSame("# Nagłówek\n", $read->text);
        self::assertSame('Opis z cudzysłowami', $read->instruction->description);
        self::assertSame('ws-memory-recall', $read->instruction->name);
    }

    public function testAPublishedInstructionWithNoFileIsAnErrorRatherThanAnEmptyResource(): void
    {
        // The likely cause is a container started without the mount. An empty
        // document would read to an agent as "there is no protocol" and it would
        // carry on; that has to be loud instead.
        $library = $this->withFiles([]);

        $this->expectException(InstructionUnavailable::class);
        $library->read('ws-memory://protokol-recall');
    }

    public function testAnUnknownUriIsRefused(): void
    {
        $this->expectException(UnknownInstruction::class);
        $this->withFiles([])->read('ws-memory://czego-nie-ma');
    }

    public function testOnlyTheDeclaredFilesArePublished(): void
    {
        // A directory scan would publish this file to every agent that connects.
        $library = $this->withFiles([
            'protokol-recall.md' => "Treść.\n",
            'brudnopis.md' => "Notatka, która wpadła do katalogu.\n",
        ]);

        // Nothing reaches it under any URI derived from its name — the catalogue
        // is the constant in the adapter, not the contents of the directory.
        $this->expectException(UnknownInstruction::class);
        $library->read('ws-memory://brudnopis');
    }

    // ------------------------------------------------------------------ setup

    private function fromContainer(): InstructionLibrary
    {
        // Fetched by its port and asserted to be this adapter: the test speaks
        // about the interface, and the wiring is part of what it checks.
        $library = $this->container()->get(InstructionLibrary::class);
        self::assertInstanceOf(FileInstructionLibrary::class, $library);

        return $library;
    }

    private function configuredDirectory(): string
    {
        $directory = $this->container()->getParameter('app.instructions_dir');
        self::assertIsString($directory);

        return $directory;
    }

    private function container(): ContainerInterface
    {
        if (null === self::$kernel) {
            self::bootKernel();
        }

        return static::getContainer();
    }

    /**
     * The file behind a URI, found by the last segment of that URI. Deliberately
     * not read from the adapter's own map: a test that asked the code under test
     * where the content lives would agree with it no matter what it said.
     */
    private function rawFileBehind(string $uri, string $directory): string
    {
        $relative = substr($uri, \strlen('ws-memory://')) . '.md';
        $path = rtrim($directory, '/') . '/' . $relative;

        self::assertFileExists($path, 'zasób MCP wskazuje na plik, którego nie ma w plugin/shared');

        return (string) file_get_contents($path);
    }

    /**
     * A library over a directory holding exactly these files.
     *
     * @param array<string, string> $files relative path => content
     */
    private function withFiles(array $files): FileInstructionLibrary
    {
        $directory = sys_get_temp_dir() . '/ws-instrukcje-' . bin2hex(random_bytes(6));
        $this->temporary[] = $directory;

        foreach ($files as $relative => $content) {
            $path = $directory . '/' . $relative;
            @mkdir(\dirname($path), 0o777, true);
            file_put_contents($path, $content);
        }

        @mkdir($directory, 0o777, true);

        return new FileInstructionLibrary($directory);
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $path = $directory . '/' . $entry;
            is_dir($path) ? self::removeDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }
}
