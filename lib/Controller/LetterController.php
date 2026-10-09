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
 * @spec openspec/specs/client-letters/spec.md#requirement-a-user-makes-a-letter-for-a-client-from-a-filinq-template-req-wlt-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\CallerObjectReader;
use OCA\Pipelinq\Service\Letter\FilinqLetterAdapter;
use OCA\Pipelinq\Service\Letter\LetterLog;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use RuntimeException;

/**
 * Letter templates and letters for a client.
 *
 * @spec openspec/specs/client-letters/spec.md#requirement-a-user-makes-a-letter-for-a-client-from-a-filinq-template-req-wlt-001
 */
class LetterController extends Controller
{



    /**
     * Constructor.
     *
     * @param IRequest                $request      The request.
     * @param FilinqLetterAdapter     $letters      filinq's side of the letter.
     * @param LetterLog               $log          Logs the letter as a contact moment.
     * @param IUserSession            $userSession  The acting user.
     * @param CallerObjectReader      $reader       Reads the client, contact and ticket.
     * @param ObjectOwnerAccessPolicy $accessPolicy Who may act on a client.
     */
    public function __construct(
        IRequest $request,
        private readonly FilinqLetterAdapter $letters,
        private readonly LetterLog $log,
        private readonly IUserSession $userSession,
        private readonly CallerObjectReader $reader,
        private readonly ObjectOwnerAccessPolicy $accessPolicy,
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
     * @spec openspec/specs/client-letters/spec.md#requirement-without-filinq-no-letter-is-offered-or-faked-req-wlt-003
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
     * @spec openspec/specs/client-letters/spec.md#requirement-a-user-makes-a-letter-for-a-client-from-a-filinq-template-req-wlt-001
     * @spec openspec/specs/client-letters/spec.md#requirement-a-letter-is-logged-on-the-client-req-wlt-002
     * @spec openspec/specs/client-letters/spec.md#requirement-without-filinq-no-letter-is-offered-or-faked-req-wlt-003
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

        $contactMomentId = $this->log->log(
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
     * @return array<string, mixed>|string The records (`client`, `contactId`, `ticketId`, `refs`: filinq's
     *         references), or the not-found message.
     */
    private function readRecords(string $clientId): array|string
    {
        $client = $this->reader->read(id: $clientId, schemaKey: 'client_schema');
        // Existence is not authorization: OpenRegister answers a read whatever
        // `_rbac` says (see ActivityTimelineController), so this app decides.
        $uid = (string) $this->userSession->getUser()?->getUID();
        if ($client === null || $this->accessPolicy->mayAccess(uid: $uid, object: $client, ownerField: 'ownerId') === false) {
            return 'Client not found';
        }

        $register = $this->reader->register();
        $refs     = [['register' => $register, 'schema' => 'client', 'id' => $clientId]];

        $contactId = $this->optionalId(name: 'contactId');
        if ($contactId !== null) {
            if ($this->reader->read(id: $contactId, schemaKey: 'contact_schema') === null) {
                return 'Contact not found';
            }

            $refs[] = ['register' => $register, 'schema' => 'contact', 'id' => $contactId];
        }

        $ticketId = $this->optionalId(name: 'ticketId');
        if ($ticketId !== null) {
            // A ticket of another client would log the letter on the wrong timeline.
            if ($this->ticketOfClient(ticketId: $ticketId, clientId: $clientId) === false) {
                return 'Ticket not found';
            }

            $refs[] = ['register' => $register, 'schema' => 'ticket', 'id' => $ticketId];
        }

        return ['client' => $client, 'contactId' => $contactId, 'ticketId' => $ticketId, 'refs' => $refs];

    }//end readRecords()


    /**
     * A request parameter holding an id, or null when it is absent or blank.
     *
     * @param string $name The parameter name.
     *
     * @return string|null The id.
     */
    private function optionalId(string $name): ?string
    {
        $value = trim((string) $this->request->getParam($name, ''));
        if ($value === '') {
            return null;
        }

        return $value;

    }//end optionalId()


    /**
     * Whether the ticket exists, is readable and belongs to the client.
     *
     * @param string $ticketId The ticket id.
     * @param string $clientId The client id.
     *
     * @return bool True when the letter may hang under it.
     */
    private function ticketOfClient(string $ticketId, string $clientId): bool
    {
        $ticket = $this->reader->read(id: $ticketId, schemaKey: 'ticket_schema');

        return $ticket !== null && (string) ($ticket['client'] ?? '') === $clientId;

    }//end ticketOfClient()


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
