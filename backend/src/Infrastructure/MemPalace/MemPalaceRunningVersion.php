<?php

declare(strict_types=1);

namespace App\Infrastructure\MemPalace;

use App\Domain\Dependency\CheckFailed;
use App\Domain\Dependency\RunningVersion;
use App\Domain\Dependency\Version;

/**
 * Adapter: the palace version, taken from the MCP handshake.
 *
 * `MEMPALACE_VERSION` would be easier to read and would answer a different
 * question — what the image was meant to be built from. This asks the process
 * that is serving requests, which is the only source that cannot be out of date.
 * Both numbers are shown in the panel precisely so the gap between them is
 * visible; see DependencyCheckService.
 *
 * A version the parser refuses becomes CheckFailed rather than `null`. The palace
 * naming a release we cannot compare against is a real problem — every later
 * decision about updating rests on that comparison — and it needs to be said out
 * loud rather than shown as an empty field.
 */
final readonly class MemPalaceRunningVersion implements RunningVersion
{
    public function __construct(private MemPalaceClient $client)
    {
    }

    public function current(): ?Version
    {
        try {
            $serverInfo = $this->client->serverInfo();
        } catch (MemPalaceUnavailable $e) {
            throw CheckFailed::serviceSilent('MemPalace', $e);
        }

        $reported = $serverInfo['version'] ?? null;
        if (!\is_string($reported) || '' === trim($reported)) {
            // The handshake succeeded and named no version. Nothing is broken;
            // there is simply nothing to report, which is what null is for.
            return null;
        }

        $version = Version::tryParse($reported);
        if (null === $version) {
            throw CheckFailed::serviceMalformed('MemPalace', \sprintf('„%s"', $reported));
        }

        return $version;
    }
}
