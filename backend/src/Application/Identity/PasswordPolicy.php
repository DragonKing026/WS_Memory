<?php

declare(strict_types=1);

namespace App\Application\Identity;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The one password rule of this installation, and the one way to generate a
 * password that satisfies it.
 *
 * It became a class the moment a second door appeared. Accepting an invitation over
 * HTTP used to be the only way a password was set, so the rule lived in that
 * controller; `ws:user:password` is the second, and a rule written down twice is
 * two rules as soon as somebody relaxes one of them — while believing the other
 * still holds. Whoever tightens this list tightens it for both doors.
 *
 * Twelve characters and no composition rules: length beats character classes, and
 * rules about digits and punctuation only push people towards Haslo123!.
 */
final readonly class PasswordPolicy
{
    public const MINIMUM_LENGTH = 12;

    /**
     * Long enough that nothing else about it matters.
     *
     * Twenty-four characters out of this alphabet is around 140 bits of entropy —
     * more than the hash behind it — so there is no rule about digits or case to
     * satisfy and no need to check it against anything.
     */
    private const GENERATED_LENGTH = 24;

    /**
     * Letters and digits only, and no `l`, `I`, `1`, `O` or `0`.
     *
     * A generated password is read off a terminal and typed into a login form,
     * often by somebody on the phone with whoever ran the command. The characters
     * that get mistyped, and the punctuation that needs quoting in a shell, are
     * worth more than the handful of bits they contribute — with twenty-four
     * characters there are bits to spare.
     */
    private const ALPHABET = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private ValidatorInterface $validator)
    {
    }

    /**
     * Static so that the HTTP surface can drop it straight into its field map and
     * keep answering with per-field messages, while the console asks the same list
     * through violations() below.
     *
     * @return list<Constraint>
     */
    public static function constraints(): array
    {
        return [
            new Assert\NotBlank(),
            new Assert\Length(
                min: self::MINIMUM_LENGTH,
                minMessage: 'Hasło musi mieć co najmniej {{ limit }} znaków.',
            ),
            // Refuses passwords known from public breaches. Checked against Have I
            // Been Pwned by k-anonymity: only the first five characters of the hash
            // leave the server. `skipOnError` means an unreachable service lets the
            // password through — deliberate, because the alternative is an
            // installation where nobody can set a password while somebody else's
            // API is down.
            new Assert\NotCompromisedPassword(
                message: 'To hasło wyciekło już w znanych wyciekach danych. Wybierz inne.',
                skipOnError: true,
            ),
        ];
    }

    /**
     * What is wrong with this password, in the words the user should see.
     *
     * An empty list means it passes. Returning messages rather than throwing is
     * what lets a caller report every problem at once instead of the first one.
     *
     * @return list<string>
     */
    public function violations(string $password): array
    {
        $messages = [];

        foreach ($this->validator->validate($password, self::constraints()) as $violation) {
            $messages[] = (string) $violation->getMessage();
        }

        return $messages;
    }

    /**
     * A password nobody has to invent.
     *
     * Generating is the default on the console, and the reason is not convenience:
     * a password passed as an argument is written to the shell history of the
     * machine it was typed on, which is the one place a recovery password must not
     * end up.
     *
     * Deliberately not run through violations(): it satisfies the length rule by
     * construction, and asking Have I Been Pwned about a freshly drawn 24-character
     * string would put a network call on the path of a command whose whole purpose
     * is regaining access to a broken installation.
     */
    public static function generate(): string
    {
        $alphabet = self::ALPHABET;
        $last = \strlen($alphabet) - 1;
        $password = '';

        for ($i = 0; $i < self::GENERATED_LENGTH; ++$i) {
            $password .= $alphabet[random_int(0, $last)];
        }

        return $password;
    }
}
