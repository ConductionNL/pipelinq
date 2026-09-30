<?php

/**
 * Pipelinq LetterController.
 *
 * Makes a printable letter for one client from a filinq template
 * (work-letter-from-filinq-template). The browser talks only to pipelinq;
 * pipelinq reads the client, contact and ticket as the caller, hands filinq
 * references to them, and logs the letter as an outgoing contact moment.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/work-letter-from-filinq-template/specs/client-letters/spec.md#requirement-a-user-makes-a-letter-for-a-client-from-a-filinq-template-req-wlt-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\Letter\FilinqLetterAdapter;
use OCA\Pipelinq\Service\TicketService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Letter templates and letters for a client.
 *
 * @spec openspec/changes/work-letter-from-filinq-template/specs/client-letters/spec.md#requirement-a-user-makes-a-letter-for-a-client-from-a-filinq-template-req-wlt-001
 */
class LetterController extends Controller
{

    /**
     * The channel a letter is logged with, in the contact moment vocabulary.
     *
     * @var string
     */
    public const LETTER_CHANNEL = 'brief';


    /**
     * Constructor.
     *
     * @param IRequest            $request       The request.
     * @param FilinqLetterAdapter $letters       filinq's side of the letter.
     * @param TicketService       $tickets       Saves the contact moment.
     * @param IUserSession        $userSession   The acting user.
     * @param IAppConfig          $appConfig     The register and schema ids.
     * @param ContainerInterface      $container     OpenRegister's ObjectService.
     * @param ObjectOwnerAccessPolicy $accessPolicy  Who may act on a client.
     * @param LoggerInterface         $logger        Logger.
     */
    public function __construct(
        IRequest $request,
        private readonly FilinqLetterAdapter $letters,
        private readonly TicketService $tickets,
        private readonly IUserSession $userSession,
        private readonly IAppConfig $appConfig,
        private readonly ContainerInterface $container,
        private readonly ObjectOwnerAccessPolicy $accessPolicy,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()


    /**
     * GET /api/letters/templates: whether filinq can make a letter, and pipelinq's templates.
     *
     * Always 200 for a signed-in user, so the header action's `visibleWhen`
     * can read `available`. When filinq is absent the answer says why.
     *
     * @return JSONResponse `{available, reason?, templates}`.
     *
     * @spec openspec/changes/work-letter-from-filinq-template/specs/client-letters/spec.md#requirement-without-filinq-no-letter-is-offered-or-faked-req-wlt-003
     */
    #[NoAdminRequired]
    public function templates(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        $reason = $this->letters->unavailableReason();
        if ($reason !== null) {
            return new JSONResponse(['available' => false, 'reason' => $reason, 'templates' => []]);
        }

        try {
            $templates = $this->letters->listTemplates();
        } catch (RuntimeException $e) {
            return new JSONResponse(['available' => false, 'reason' => $e->getMessage(), 'templates' => []]);
        }

        return new JSONResponse(['available' => true, 'templates' => $templates]);

    }//end templates()


    /**
     * POST /api/clients/{id}/letters: make a letter for this client.
     *
     * Body: `templateId`, and optionally `contactId` and `ticketId`. Every
     * record is read as the caller, so one the caller may not read answers
     * 404. The answer carries the PDF (base64), the id of the copy in the
     * caller's Files, filinq's warnings and the logged contact moment.
     *
     * @param string $id The client id.
     *
     * @return JSONResponse The letter, or an error.
     *
     * @spec openspec/changes/work-letter-from-filinq-template/specs/client-letters/spec.md#requirement-a-user-makes-a-letter-for-a-client-from-a-filinq-template-req-wlt-001
     * @spec openspec/changes/work-letter-from-filinq-template/specs/client-letters/spec.md#requirement-a-letter-is-logged-on-the-client-req-wlt-002
     * @spec openspec/changes/work-letter-from-filinq-template/specs/client-letters/spec.md#requirement-without-filinq-no-letter-is-offered-or-faked-req-wlt-003
     */
    #[NoAdminRequired]
    public function create(string $id): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        $templateId = trim((string) $this->request->getParam('templateId', ''));
        if ($templateId === '') {
            return new JSONResponse(['message' => 'Missing required parameter: templateId'], Http::STATUS_BAD_REQUEST);
        }

        $reason = $this->letters->unavailableReason();
        if ($reason !== null) {
            return new JSONResponse(
                ['message' => 'filinq is not available, so no letter can be made.', 'reason' => $reason],
                Http::STATUS_SERVICE_UNAVAILABLE
            );
        }

        $records = $this->readRecords(clientId: $id);
        if (is_string($records) === true) {
            return new JSONResponse(['message' => $records], Http::STATUS_NOT_FOUND);
        }

        $template = $this->letters->findTemplate(templateId: $templateId);
        if ($template === null) {
            return new JSONResponse(['message' => 'Template not found'], Http::STATUS_NOT_FOUND);
        }

        $filename = $this->filename(templateName: $template['name'], clientName: (string) ($records['client']['name'] ?? ''));
        try {
            $letter = $this->letters->render(
                templateId: $templateId,
                dataRefs: $records['refs'],
                filename: $filename
            );
        } catch (RuntimeException $e) {
            return new JSONResponse(['message' => 'filinq could not make the letter.', 'reason' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
        }

        $contactMomentId = $this->logLetter(
            clientId: $id,
            contactId: $records['contactId'],
            ticketId: $records['ticketId'],
            templateName: $template['name'],
            path: $letter['path']
        );

        return new JSONResponse(
            [
                'filename'        => $filename.'.pdf',
                'mimeType'        => 'application/pdf',
                'content'         => base64_encode($letter['content']),
                'fileId'          => $letter['fileId'],
                'path'            => $letter['path'],
                'warnings'        => $letter['warnings'],
                'contactMomentId' => $contactMomentId,
            ]
        );

    }//end create()


    /**
     * Read the client, and the contact and ticket named in the request, as the caller.
     *
     * @param string $clientId The client id.
     *
     * @return array{client: array<string, mixed>, contactId: string|null, ticketId: string|null, refs: list<array{register: string, schema: string, id: string}>}|string
     *         The records and filinq's references, or the not-found message.
     */
    private function readRecords(string $clientId): array|string
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $client   = $this->readAs(id: $clientId, register: $register, schemaKey: 'client_schema');
        // Existence is not authorization: OpenRegister answers a read whatever
        // `_rbac` says (see ActivityTimelineController), so this app decides.
        $uid = (string) $this->userSession->getUser()?->getUID();
        if ($client === null || $this->accessPolicy->mayAccess(uid: $uid, object: $client, ownerField: 'ownerId') === false) {
            return 'Client not found';
        }

        $refs = [['register' => $register, 'schema' => 'client', 'id' => $clientId]];

        $contactId = trim((string) $this->request->getParam('contactId', ''));
        if ($contactId !== '') {
            if ($this->readAs(id: $contactId, register: $register, schemaKey: 'contact_schema') === null) {
                return 'Contact not found';
            }

            $refs[] = ['register' => $register, 'schema' => 'contact', 'id' => $contactId];
        }

        $ticketId = trim((string) $this->request->getParam('ticketId', ''));
        if ($ticketId !== '') {
            $ticket = $this->readAs(id: $ticketId, register: $register, schemaKey: 'ticket_schema');
            // A ticket of another client would log the letter on the wrong timeline.
            if ($ticket === null || (string) ($ticket['client'] ?? '') !== $clientId) {
                return 'Ticket not found';
            }

            $refs[] = ['register' => $register, 'schema' => 'ticket', 'id' => $ticketId];
        }

        return [
            'client'    => $client,
            'contactId' => $contactId === '' ? null : $contactId,
            'ticketId'  => $ticketId === '' ? null : $ticketId,
            'refs'      => $refs,
        ];

    }//end readRecords()


    /**
     * One object read through OpenRegister with the caller's rights, or null.
     *
     * Fails closed: missing configuration or an OpenRegister error reads as
     * not found.
     *
     * @param string $id        The object id.
     * @param string $register  The register id.
     * @param string $schemaKey The app config key holding the schema id.
     *
     * @return array<string, mixed>|null The object's data.
     */
    private function readAs(string $id, string $register, string $schemaKey): ?array
    {
        $schema = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
        if ($register === '' || $schema === '' || $id === '') {
            return null;
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $object        = $objectService->find(id: $id, register: $register, schema: $schema, _rbac: true);
        } catch (Throwable $e) {
            $this->logger->warning(
                'Pipelinq: letter read-guard refused an object',
                ['app' => Application::APP_ID, 'id' => $id, 'exception' => $e->getMessage()]
            );
            return null;
        }

        if ($object === null) {
            return null;
        }

        if (is_array($object) === true) {
            return $object;
        }

        return (array) $object->getObject();

    }//end readAs()


    /**
     * Log the letter as an outgoing contact moment on the client.
     *
     * A failure here does not undo the letter: it is already in the user's
     * Files. The answer then carries no contact moment id, and the dialog says
     * so.
     *
     * @param string      $clientId     The client id.
     * @param string|null $contactId    The contact person, if one was chosen.
     * @param string|null $ticketId     The ticket the letter was made from, if any.
     * @param string      $templateName The template's name.
     * @param string|null $path         Where filinq filed the copy.
     *
     * @return string|null The contact moment id, or null when it could not be saved.
     *
     * @spec openspec/changes/work-letter-from-filinq-template/specs/client-letters/spec.md#requirement-a-letter-is-logged-on-the-client-req-wlt-002
     */
    private function logLetter(string $clientId, ?string $contactId, ?string $ticketId, string $templateName, ?string $path): ?string
    {
        $payload = self::contactMomentPayload(
            clientId: $clientId,
            contactId: $contactId,
            ticketId: $ticketId,
            templateName: $templateName,
            path: $path,
            occurredAt: new DateTimeImmutable()
        );

        try {
            $saved = $this->tickets->save(ticketType: TicketService::TYPE_CONTACTMOMENT, payload: $payload);
        } catch (Throwable $e) {
            $this->logger->error(
                'Pipelinq: the letter is made but its contact moment was not saved',
                ['app' => Application::APP_ID, 'clientId' => $clientId, 'exception' => $e->getMessage()]
            );
            return null;
        }

        $data = method_exists($saved, 'jsonSerialize') === true ? (array) $saved->jsonSerialize() : (array) $saved;
        $self = (array) ($data['@self'] ?? []);

        return (string) ($data['id'] ?? ($self['id'] ?? ($data['uuid'] ?? ''))) ?: null;

    }//end logLetter()


    /**
     * The contact moment a letter is logged as, before `ticketType` is forced.
     *
     * @param string            $clientId     The client id.
     * @param string|null       $contactId    The contact person, if one was chosen.
     * @param string|null       $ticketId     The ticket the letter was made from, if any.
     * @param string            $templateName The template's name.
     * @param string|null       $path         Where filinq filed the copy.
     * @param DateTimeImmutable $occurredAt   When the letter was made.
     *
     * @return array<string, string> The payload.
     *
     * @spec openspec/changes/work-letter-from-filinq-template/specs/client-letters/spec.md#requirement-a-letter-is-logged-on-the-client-req-wlt-002
     */
    public static function contactMomentPayload(
        string $clientId,
        ?string $contactId,
        ?string $ticketId,
        string $templateName,
        ?string $path,
        DateTimeImmutable $occurredAt
    ): array {
        $description = 'Letter made from the filinq template "'.$templateName.'".';
        if ($path !== null && $path !== '') {
            $description .= ' A copy is filed at '.$path.'.';
        }

        $payload = [
            'client'      => $clientId,
            'title'       => mb_substr('Letter: '.$templateName, 0, 255),
            'direction'   => 'outbound',
            'channel'     => self::LETTER_CHANNEL,
            'outcome'     => 'handled',
            'description' => $description,
            'occurredAt'  => $occurredAt->format(DateTimeInterface::ATOM),
        ];

        if ($contactId !== null) {
            $payload['contact'] = $contactId;
        }

        if ($ticketId !== null) {
            $payload['parentTicket'] = $ticketId;
        }

        return $payload;

    }//end contactMomentPayload()


    /**
     * A file name for the letter: template, client and date, safe for Files.
     *
     * @param string $templateName The template's name.
     * @param string $clientName   The client's name.
     *
     * @return string The name, without extension.
     */
    private function filename(string $templateName, string $clientName): string
    {
        $name = trim($templateName.' '.$clientName).' '.date('Y-m-d');
        $name = (string) preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', ' ', $name);

        return trim((string) preg_replace('/\s+/u', ' ', $name));

    }//end filename()


}//end class
