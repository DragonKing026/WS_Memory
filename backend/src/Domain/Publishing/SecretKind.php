<?php

declare(strict_types=1);

namespace App\Domain\Publishing;

/**
 * What kind of secret a scanner recognised in a drawer.
 *
 * An enum rather than a free string because the value is written into
 * `publish_batches.skipped_reasons` and read back on a screen. A free string
 * would drift between the two paths, and a skip report nobody can group by
 * reason is a report nobody reads.
 *
 * The labels are Polish on purpose: unlike the rest of the code, this text is
 * shown to the person whose drawer was refused, and it is the only explanation
 * they get.
 */
enum SecretKind: string
{
    /** The drawer is, or contains, the body of an environment file. */
    case EnvFile = 'env_file';

    /** `-----BEGIN … PRIVATE KEY-----`, in any of its flavours. */
    case PrivateKey = 'private_key';

    /** A password embedded in a URL: `scheme://user:password@host`. */
    case UrlPassword = 'url_password';

    /** A credential with a recognisable prefix: ghp_, sk-, wsm_, AKIA, a JWT. */
    case ApiToken = 'api_token';

    /** `SOMETHING_PASSWORD = …` — a secret-bearing name with a real value. */
    case CredentialAssignment = 'credential_assignment';

    public function label(): string
    {
        return match ($this) {
            self::EnvFile => 'treść pliku ze zmiennymi środowiskowymi',
            self::PrivateKey => 'klucz prywatny',
            self::UrlPassword => 'hasło w adresie URL',
            self::ApiToken => 'token o rozpoznawalnym prefiksie',
            self::CredentialAssignment => 'przypisanie wartości do nazwy wskazującej na sekret',
        };
    }
}
