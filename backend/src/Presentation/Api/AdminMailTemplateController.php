<?php

declare(strict_types=1);

namespace App\Presentation\Api;

use App\Application\Mail\SendTestMail;
use App\Application\Mail\UpdateMailTemplate;
use App\Domain\Mail\InvalidTemplate;
use App\Domain\Mail\MailTemplate;
use App\Domain\Mail\MailTemplateKey;
use App\Domain\Mail\MailTemplateLibrary;
use App\Domain\Mail\MissingMailTemplate;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The wording of the mails this installation sends.
 *
 * **There is no route that creates a template and none that deletes one.** The set of
 * templates is the set of places in the code that decide to send mail — an enum — so
 * the only operation on wording is replacing it. A create endpoint would produce rows
 * nothing renders, and a delete endpoint would silence a mail with no way to tell.
 *
 * Global administrators only, and audited: the wording of an invitation is what a
 * stranger reads before deciding to trust a link (D-016).
 *
 * Validation lives in MailTemplate's constructor, which is why the refusals below are
 * one `catch`. An invalid template never becomes an object, so this controller cannot
 * save one even by mistake — and the message it returns is the one written for the
 * person looking at the text area, naming the places they may use.
 */
final readonly class AdminMailTemplateController
{
    private const FORBIDDEN_BODY = ['error' => 'Szablony maili wymagają uprawnień administratora.'];

    public function __construct(
        private Security $security,
        private MailTemplateLibrary $templates,
        private UpdateMailTemplate $update,
        private SendTestMail $test,
    ) {
    }

    /**
     * Everything the editing screen needs, in one answer.
     *
     * The list carries each template's wording, its allowed places, its sample values
     * and its rendered preview together. There are a handful of templates and the
     * screen shows all of it at once; a second round trip per template would buy
     * nothing but a spinner.
     */
    #[Route('/api/admin/mail-templates', name: 'api_admin_mail_templates', methods: ['GET'])]
    public function list(): JsonResponse
    {
        if (!$this->currentUser()->isGlobalAdmin()) {
            return new JsonResponse(self::FORBIDDEN_BODY, Response::HTTP_FORBIDDEN);
        }

        try {
            $templates = $this->templates->all();
        } catch (MissingMailTemplate|InvalidTemplate $problem) {
            // Reported rather than hidden behind an empty list. Both causes are real
            // and both need a person: migrations not run, or wording left over from a
            // version whose places no longer exist.
            return new JsonResponse(
                ['error' => $problem->getMessage()],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return new JsonResponse([
            'templates' => array_map(self::present(...), $templates),
        ]);
    }

    #[Route(
        '/api/admin/mail-templates/{key}',
        name: 'api_admin_mail_templates_save',
        methods: ['PUT'],
    )]
    public function save(string $key, Request $request): JsonResponse
    {
        $actor = $this->currentUser();
        if (!$actor->isGlobalAdmin()) {
            return new JsonResponse(self::FORBIDDEN_BODY, Response::HTTP_FORBIDDEN);
        }

        $template = MailTemplateKey::tryFrom($key);
        if (null === $template) {
            return new JsonResponse(['error' => self::unknownKey($key)], Response::HTTP_NOT_FOUND);
        }

        $wording = self::wordingFrom($request);
        if (\is_string($wording)) {
            return new JsonResponse(['error' => $wording], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $saved = ($this->update)($template, $wording['subject'], $wording['body'], $actor);
        } catch (InvalidTemplate|MissingMailTemplate $problem) {
            return new JsonResponse(
                ['error' => $problem->getMessage()],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return new JsonResponse(['template' => self::present($saved)]);
    }

    /**
     * What the wording in the editor would look like, without saving it.
     *
     * A separate route rather than a client-side substitution, because rendering is the
     * server's rule: a preview drawn by the browser would be a second implementation of
     * the thing whose whole point is that there is only one, and it would happily show
     * a template the server is about to refuse.
     */
    #[Route(
        '/api/admin/mail-templates/{key}/preview',
        name: 'api_admin_mail_templates_preview',
        methods: ['POST'],
    )]
    public function preview(string $key, Request $request): JsonResponse
    {
        if (!$this->currentUser()->isGlobalAdmin()) {
            return new JsonResponse(self::FORBIDDEN_BODY, Response::HTTP_FORBIDDEN);
        }

        $template = MailTemplateKey::tryFrom($key);
        if (null === $template) {
            return new JsonResponse(['error' => self::unknownKey($key)], Response::HTTP_NOT_FOUND);
        }

        $wording = self::wordingFrom($request);
        if (\is_string($wording)) {
            return new JsonResponse(['error' => $wording], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $candidate = new MailTemplate(
                key: $template,
                subject: $wording['subject'],
                body: $wording['body'],
                updatedAt: new \DateTimeImmutable(),
            );
        } catch (InvalidTemplate $problem) {
            return new JsonResponse(
                ['error' => $problem->getMessage()],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $rendered = $candidate->renderSample();

        return new JsonResponse([
            'preview' => ['subject' => $rendered->subject, 'body' => $rendered->body],
        ]);
    }

    /**
     * Sends the saved wording to the administrator's own address, on sample values.
     *
     * To their own address and to no other: a form accepting any recipient would be a
     * way to send mail from this server to a stranger with text of the sender's
     * choosing. The question being answered is "does the mail server work and does my
     * wording look right", for which one's own inbox is the correct destination.
     */
    #[Route(
        '/api/admin/mail-templates/{key}/test',
        name: 'api_admin_mail_templates_test',
        methods: ['POST'],
    )]
    public function testSend(string $key): JsonResponse
    {
        $actor = $this->currentUser();
        if (!$actor->isGlobalAdmin()) {
            return new JsonResponse(self::FORBIDDEN_BODY, Response::HTTP_FORBIDDEN);
        }

        $template = MailTemplateKey::tryFrom($key);
        if (null === $template) {
            return new JsonResponse(['error' => self::unknownKey($key)], Response::HTTP_NOT_FOUND);
        }

        $mailLogId = ($this->test)($template, $actor);

        // 202: queued, not delivered. Whether it arrives is the mail journal's answer,
        // and claiming 200 "sent" here would be the one lie this whole task is about.
        return new JsonResponse([
            'mailLogId' => $mailLogId,
            'recipient' => $actor->getEmail(),
            'message' => 'Wiadomość próbna trafiła do kolejki. Stan wysyłki pokaże dziennik maili.',
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * @return array{subject: string, body: string}|string the wording, or the sentence refusing it
     */
    private static function wordingFrom(Request $request): array|string
    {
        $payload = $request->getPayload()->all();

        /** @var mixed $subject */
        $subject = $payload['subject'] ?? null;
        /** @var mixed $body */
        $body = $payload['body'] ?? null;

        if (!\is_string($subject) || !\is_string($body)) {
            return 'Pola „subject” i „body” są wymagane i muszą być tekstem.';
        }

        return ['subject' => $subject, 'body' => $body];
    }

    private static function unknownKey(string $key): string
    {
        return \sprintf(
            'Nie ma szablonu „%s”. Znane szablony: %s. Szablonów nie dodaje się w panelu '
            . '— każdy odpowiada miejscu w kodzie, które wysyła maila.',
            $key,
            implode(', ', array_column(MailTemplateKey::cases(), 'value')),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(MailTemplate $template): array
    {
        $sample = $template->renderSample();

        return [
            'key' => $template->key->value,
            'label' => $template->key->label(),
            'whenSent' => $template->key->describeWhenSent(),
            'subject' => $template->subject,
            'body' => $template->body,
            // The closed list, so the screen can show what may be typed instead of
            // leaving somebody to discover it from a refusal.
            'placeholders' => $template->key->placeholders(),
            // Which of those may not go in the subject, and the screen says why.
            'sensitive' => $template->key->sensitive(),
            'sampleValues' => $template->key->sampleValues(),
            'preview' => ['subject' => $sample->subject, 'body' => $sample->body],
            'updatedAt' => $template->updatedAt->format(\DATE_ATOM),
            'updatedByEmail' => $template->updatedByEmail,
        ];
    }

    private function currentUser(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Trasa poza firewallem — kontroler nie powinien tu trafić.');
        }

        return $user;
    }
}
