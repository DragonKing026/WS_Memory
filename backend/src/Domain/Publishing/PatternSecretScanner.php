<?php

declare(strict_types=1);

namespace App\Domain\Publishing;

/**
 * The secret filter: patterns, and nothing but patterns.
 *
 * It lives in Domain rather than Infrastructure, which looks like a violation
 * until you ask what it depends on. The answer is nothing — no HTTP, no
 * database, no MemPalace, not even a clock. What counts as a secret is a rule of
 * this business, exactly like "a write with no space lands in the private one",
 * and rules of the business are what Domain is. The port next to it is there for
 * the reason ports are there: a second strategy is another class, not another
 * branch in this one.
 *
 * It sits on EVERY publication path since D-014 made sending the default. That
 * changes the failure modes it has to be judged on:
 *
 *   - a MISS publishes a secret into a shared, searchable, backed-up database,
 *     where deleting it later does not unsee it;
 *   - a FALSE POSITIVE costs one drawer, reported by name in the batch's skip
 *     report, with the local original untouched (D-015).
 *
 * The two are not comparable, so the patterns lean towards refusing. What keeps
 * that from eating ordinary content is one idea applied throughout:
 * self::looksLikePlaceholder(). `DATABASE_URL=postgres://ws_app:USTAW_W_COMPOSE@db`
 * is documentation and `postgres://ws_app:8fd1…@db` is an incident, and the
 * difference is the value, not the shape. Without that distinction this file
 * would refuse our own `.env.example`, which is to say: it would refuse the
 * documentation that exists to stop people writing real secrets down.
 *
 * The patterns are deliberately anchored to prefixes issuers actually mint
 * (`ghp_`, `sk-`, `AKIA`, `wsm_`, a JWT's `eyJ`) instead of hunting for
 * "anything with high entropy". Entropy scoring flags hashes, UUIDs, minified
 * code and base64 images — which in a system whose main volume is mined
 * repositories means flagging most of it.
 */
final readonly class PatternSecretScanner implements SecretScanner
{
    /**
     * File names whose contents are secret by virtue of the name.
     *
     * `.env.example` and `.env.dist` are deliberately absent: they exist to be
     * read, and refusing them would refuse the one file that tells people where
     * the real values go.
     */
    private const ENV_FILE_PATTERN = '/(?:^|\/)\.env(?:\.(?!example|dist|sample|template)[A-Za-z0-9_-]+)*$/';

    /** An `export FOO=` / `FOO=` line, as an environment file is made of. */
    private const ASSIGNMENT_LINE = '/^[ \t]*(?:export[ \t]+)?[A-Z][A-Z0-9_]{2,}=/';

    /** A name that says the value next to it is a credential. */
    private const SECRET_NAME = '/(?:SECRET|PASSWORD|PASSWD|PASSPHRASE|TOKEN|API_?KEY|PRIVATE_?KEY|ACCESS_?KEY|CREDENTIALS?|DSN)/i';

    /** `NAME = value` or `NAME: value`, in a shell file, an ini file or YAML. */
    private const NAMED_VALUE = '/^[ \t]*(?:export[ \t]+|-[ \t]+)?["\']?([A-Za-z_][A-Za-z0-9_.\-]*)["\']?[ \t]*[:=][ \t]*(\S+)[ \t]*$/';

    /** Any PEM private key, including OpenSSH and PGP blocks. */
    private const PRIVATE_KEY = '/-----BEGIN[ A-Z0-9]* PRIVATE KEY(?: BLOCK)?-----/';

    /** `scheme://user:password@host` — the password is the third group. */
    private const URL_PASSWORD = '#\b[a-z][a-z0-9+.\-]{1,15}://[^\s:/?\#@\[\]]+:([^\s:/?\#@\[\]]+)@#i';

    /**
     * Credentials recognisable from their first characters.
     *
     * Lengths are lower bounds rather than the exact ones each issuer uses. A
     * pattern that insists on GitHub's current 36 characters stops matching the
     * day GitHub changes it, and it would be a silent stop.
     *
     * @var list<string>
     */
    private const TOKEN_PATTERNS = [
        // GitHub: personal, OAuth, user-to-server, server-to-server, refresh.
        '/\bgh[pousr]_[A-Za-z0-9]{16,}\b/',
        '/\bgithub_pat_[A-Za-z0-9_]{20,}\b/',
        // OpenAI and everything that copied the shape.
        '/\bsk-[A-Za-z0-9_\-]{20,}\b/',
        // Our own agent tokens (IssueAgentToken::PREFIX). Worth naming: the
        // likeliest way one ends up in a local palace is an agent writing its own
        // configuration down while working on this repository.
        '/\bwsm_[A-Za-z0-9]{16,}\b/',
        // AWS access key id. Exactly 20 characters, and that one IS fixed.
        '/\bAKIA[0-9A-Z]{16}\b/',
        // A JWT: three base64url segments, the first always starting `eyJ`
        // because every header begins `{"`.
        '/\beyJ[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}/',
    ];

    /**
     * Shortest value worth treating as a real credential.
     *
     * Below this, a "password" is a word somebody typed into an example. The
     * tokens above are matched by prefix and are not subject to it.
     */
    private const MIN_CREDENTIAL_LENGTH = 8;

    public function scan(string $content, ?string $sourcePath = null): ScanReport
    {
        $findings = [];

        $namedEnvFile = null !== $sourcePath && 1 === preg_match(self::ENV_FILE_PATTERN, $sourcePath);
        if ($namedEnvFile || $this->readsLikeAFilledInEnvFile($content)) {
            $findings[] = new SecretFinding(SecretKind::EnvFile);
        }

        $lines = preg_split('/\R/', $content);
        foreach (false === $lines ? [] : $lines as $index => $line) {
            foreach ($this->scanLine($line) as $kind) {
                $findings[] = new SecretFinding($kind, $index + 1);
            }
        }

        return new ScanReport($findings);
    }

    /**
     * The kinds of secret one line carries.
     *
     * @return list<SecretKind>
     */
    private function scanLine(string $line): array
    {
        $kinds = [];

        if (1 === preg_match(self::PRIVATE_KEY, $line)) {
            $kinds[] = SecretKind::PrivateKey;
        }

        if (1 === preg_match(self::URL_PASSWORD, $line, $url) && !$this->looksLikePlaceholder($url[1])) {
            $kinds[] = SecretKind::UrlPassword;
        }

        foreach (self::TOKEN_PATTERNS as $pattern) {
            if (1 === preg_match($pattern, $line)) {
                $kinds[] = SecretKind::ApiToken;

                // One finding per line is enough — the drawer is refused either
                // way, and a line matching two token shapes is one mistake.
                break;
            }
        }

        if ($this->isCredentialAssignment($line)) {
            $kinds[] = SecretKind::CredentialAssignment;
        }

        return $kinds;
    }

    /**
     * `DB_PASSWORD=8fd1c0…` — a secret-bearing name with a value that is not an example.
     *
     * The value must contain no whitespace, and that single condition is what
     * keeps prose out. "Token: wygasa po ośmiu godzinach" reads to a regex
     * exactly like an assignment; it stops reading that way the moment the value
     * has to be one word.
     */
    private function isCredentialAssignment(string $line): bool
    {
        if (1 !== preg_match(self::NAMED_VALUE, $line, $matched)) {
            return false;
        }

        [, $name, $value] = $matched;

        if (1 !== preg_match(self::SECRET_NAME, $name)) {
            return false;
        }

        $value = $this->unquote($value);

        return mb_strlen($value) >= self::MIN_CREDENTIAL_LENGTH && !$this->looksLikePlaceholder($value);
    }

    /**
     * Whether the text IS a filled-in environment file, as opposed to a blank one.
     *
     * Two conditions, and both are needed.
     *
     * The first is structural — what proportion of the lines are assignments —
     * because the alternative is a list of variable names to look for, and such a
     * list is always missing the one that matters. A page of prose quoting three
     * configuration lines stays under the threshold; a file made of them does not.
     * It also catches the case the name-based patterns cannot: `ADMIN_HASH=…` is a
     * secret whose name says nothing.
     *
     * The second is that at least one value must actually look like a secret,
     * and it is what makes the first usable. `.env.example` has precisely the
     * shape of `.env` — that is the point of it — and refusing it would refuse
     * the file whose whole job is to stop people writing real values down.
     */
    private function readsLikeAFilledInEnvFile(string $content): bool
    {
        $lines = preg_split('/\R/', $content);
        if (false === $lines) {
            return false;
        }

        $meaningful = 0;
        $assignments = 0;
        $filledIn = false;
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ('' === $trimmed || str_starts_with($trimmed, '#')) {
                continue;
            }

            ++$meaningful;
            if (1 !== preg_match(self::ASSIGNMENT_LINE, $line)) {
                continue;
            }

            ++$assignments;
            $value = $this->unquote(substr($trimmed, (int) strpos($trimmed, '=') + 1));
            $filledIn = $filledIn || $this->looksLikeSecretValue($value);
        }

        // Two is the smallest count that can show a shape at all; a single
        // `FOO=bar` in a sentence is not a file.
        return $filledIn && $assignments >= 2 && $assignments * 10 >= $meaningful * 6;
    }

    /**
     * A value long and mixed enough to be a generated credential.
     *
     * Sixteen characters with at least one letter and one digit, no whitespace,
     * and not a placeholder. Crude, and that is the intent: this runs only on
     * lines that are already inside something shaped like an environment file, so
     * it needs to separate `prod` and `1024` from `3f8a2c91d04b7e65`, not to score
     * entropy over arbitrary prose.
     */
    private function looksLikeSecretValue(string $value): bool
    {
        return mb_strlen($value) >= 16
            && 1 !== preg_match('/\s/', $value)
            && 1 === preg_match('/[A-Za-z]/', $value)
            && 1 === preg_match('/\d/', $value)
            && !$this->looksLikePlaceholder($value);
    }

    /**
     * A value that is standing in for a secret rather than being one.
     *
     * This is the method that decides whether the filter is usable. Every pattern
     * above matches documentation as readily as it matches an incident, and
     * documentation about secrets is the last thing a knowledge base should
     * refuse — a team that cannot publish "put the password here" writes the
     * password somewhere worse.
     */
    private function looksLikePlaceholder(string $value): bool
    {
        $value = trim($this->unquote($value));

        if ('' === $value) {
            return true;
        }

        // Interpolation of any dialect: shell, Symfony, Docker Compose, Twig,
        // Ansible. None of these is a value; all of them are a reference to one.
        if (1 === preg_match('/\$\{|%env\(|\{\{|<[^>]*>|\$\(/', $value)) {
            return true;
        }

        // Masked by hand: xxxxx, *****, ……, ---.
        if (1 === preg_match('/^(?:[x*.\-_\x{2026}]|\.{3})+$/iu', $value)) {
            return true;
        }

        // Words people write where a secret goes, in both languages this team
        // reads. Substring rather than whole-value, because they arrive glued to
        // context: `USTAW_W_COMPOSE`, `your-token-here`, `zmien-to`.
        //
        // The words for the secret itself — `password`, `hasło`, `sekret` — are
        // deliberately NOT here, tempting as they look. They occur inside real
        // passwords far more often than they occur as placeholders, and treating
        // them as harmless would let `://admin:hasloAdmina9@db` through: the exact
        // shape this filter exists to catch.
        return 1 === preg_match(
            '/(?:change[ _-]?me|changeme|ustaw|wpisz|zmien|zmień|placeholder|example|przyklad|przykład|'
            . 'your[ _-]|twoj|twój|to[ _-]?do|fixme|redacted|dummy|sample|tutaj|xxxx)/iu',
            $value,
        );
    }

    /**
     * Strips one layer of matching quotes, and a trailing inline comment.
     */
    private function unquote(string $value): string
    {
        $value = (string) preg_replace('/[ \t]+#.*$/', '', $value);
        $value = trim($value);

        foreach (['"', "'"] as $quote) {
            if (mb_strlen($value) >= 2 && str_starts_with($value, $quote) && str_ends_with($value, $quote)) {
                return mb_substr($value, 1, -1);
            }
        }

        return $value;
    }
}
