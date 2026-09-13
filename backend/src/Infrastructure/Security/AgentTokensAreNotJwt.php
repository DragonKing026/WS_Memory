<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Application\AgentToken\IssueAgentToken;
use Lexik\Bundle\JWTAuthenticationBundle\TokenExtractor\TokenExtractorInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Mówi bibliotece JWT, że token agenta to nie jest JWT.
 *
 * Bez tego `/api/publish` **nie działa dla żadnego agenta** — i to nie tak, że
 * odmawia: uwierzytelnienie się udaje, po czym wynik jest wyrzucany. Warto
 * zapisać dokładnie, jak, bo objaw kłamie.
 *
 * Firewall `api` ma dwa autentykatory: nasz (token agenta) i JWT. Symfony
 * zbiera **wszystkie**, których `supports()` nie zwróci `false`, i wykonuje je
 * po kolei, aż któryś zwróci odpowiedź (`AuthenticatorManager::executeAuthenticators`).
 * Nasz uwierzytelnia poprawnie i — jak każdy poprawny autentykator, który nie
 * przerywa żądania — zwraca z `onAuthenticationSuccess()` `null`, czyli „nie mam
 * odpowiedzi, niech leci dalej do kontrolera". Pętla rozumie to inaczej: brak
 * odpowiedzi znaczy dla niej „próbuj następnego". Następny jest Lexik, który
 * na ciągu `wsm_…` wywraca się z komunikatem **„Invalid JWT Token"** — i to
 * jego 401 zostaje odesłane, mimo że chwilę wcześniej ktoś już był
 * uwierzytelniony.
 *
 * Objaw jest więc mylący na dwa sposoby: mówi o JWT, choć nikt nie przysłał
 * JWT, i mówi „nieprawidłowy", choć poświadczenie było prawidłowe. Sprawdzone
 * śladem w autentykatorze: `supports()` przechodzi, `authenticate()` się
 * wykonuje, tożsamość jest rozwiązana, a nasze `onAuthenticationFailure()`
 * nie jest wołane ani razu.
 *
 * `JWTAuthenticator::supports()` sprowadza się do `false !== extract($request)`,
 * więc ekstraktor jest jedynym miejscem, w którym da się powiedzieć „to nie do
 * ciebie" **zanim** Lexik dołączy do listy. Ten dekorator to robi: dla nagłówka
 * z prefiksem tokena agenta zwraca `false`, dla wszystkiego innego oddaje
 * decyzję ekstraktorowi biblioteki.
 *
 * Odrzucone alternatywy:
 *
 *   1. **Osobny firewall na `/api/publish` tylko z naszym autentykatorem** —
 *      zabrałby tę trasę zalogowanemu człowiekowi, a ona ma przyjmować oba
 *      poświadczenia (D-036).
 *   2. **Odwrócenie kolejności autentykatorów** — wtedy Lexik wywraca się jako
 *      pierwszy i odsyła swoje 401, zanim nasz w ogóle dostanie żądanie.
 *   3. **Zwracanie odpowiedzi z `onAuthenticationSuccess()`** — pętla by się
 *      zatrzymała, ale kontroler nigdy by się nie wykonał. To jest sposób na
 *      przekierowanie po zalogowaniu, nie na wpuszczenie żądania dalej.
 */
final readonly class AgentTokensAreNotJwt implements TokenExtractorInterface
{
    public function __construct(private TokenExtractorInterface $biblioteczny)
    {
    }

    public function extract(Request $request): string|false
    {
        $naglowek = $request->headers->get('Authorization', '');

        if (str_starts_with($naglowek, 'Bearer ' . IssueAgentToken::PREFIX)) {
            return false;
        }

        return $this->biblioteczny->extract($request);
    }
}
