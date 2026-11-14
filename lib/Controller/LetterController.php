<?php

/**
 * Pipelinq LetterController.
 *
 * Makes a printable letter for one client from a filinq template
 * (work-letter-from-filinq-template). The browser talks only to pipelinq;
 * pipelinq reads the client, contact, ticket and appointment as the caller, hands filinq
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

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\CallerObjectReaderInterface;
use OCA\Pipelinq\Kernel\Contracts\FilinqLetterAdapterInterface;
use OCA\Pipelinq\Kernel\Contracts\LetterLogInterface;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
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
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The total is the sum of small private steps; each method stays under the method thresholds.
 */
class LetterController extends Controller
{



    /**
     * Constructor.
     *
     * @param IRequest                $request      The request.
     * @param FilinqLetterAdapterInterface     $letters      filinq's side of the letter.
     * @param LetterLogInterface               $log          Logs the letter as a contact moment.
     * @param IUserSession            $userSession  The acting user.
     * @param CallerObjectReaderInterface      $reader       Reads the client, contact and ticket.
     * @param ObjectOwnerAccessPolicy $accessPolicy Who may act on a client.
     */
    public function __construct(
        IRequest $request,
        private readonly FilinqLetterAdapterInterface $letters,
        private readonly LetterLogInterface $log,
        private readonly IUserSession $userSession,
        private readonly CallerObjectReaderInterface $reader,
        private readonly ObjectOwnerAccessPolicy $accessPolicy,
    ) {
        parent::__construct(appName: AppIdentity::APP_ID, request: $request);

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
     * Body: `templateId`, and optionally `contactId`, `ticketId`,
     * `appointmentId` and `downloadOnly`. Every record is read as the caller, so one the caller
     * may not read answers 404. The answer carries the PDF (base64), the id
     * of the copy in the caller's Files, filinq's warnings and the logged
     * contact moment. With `downloadOnly` the PDF is only returned: no copy in
     * Files and no contact moment.
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
        $run = $this->letterRun(clientId: $id, action: 'made');
        if ($run instanceof JSONResponse) {
            return $run;
        }

        $downloadOnly = filter_var($this->request->getParam('downloadOnly', false), FILTER_VALIDATE_BOOLEAN);
        $run->filename = $this->filename(templateName: $run->template['name'], clientName: (string) ($run->records['client']['name'] ?? ''));
        $letter        = $this->renderLetter(run: $run, downloadOnly: $downloadOnly);
        if ($letter instanceof JSONResponse) {
            return $letter;
        }

        $letter['contactMomentId'] = null;
        if ($downloadOnly === false) {
            $letter['contactMomentId'] = $this->logLetter(run: $run, path: $letter['path']);
        }

        return $this->letterResponse(run: $run, letter: $letter);

    }//end create()


    /**
     * The made letter: the PDF (base64), its copy in Files, filinq's warnings
     * and the logged contact moment.
     *
     * @param LetterRun            $run    The run.
     * @param array<string, mixed> $letter The rendered letter and its contact moment id.
     *
     * @return JSONResponse The response.
     */
    private function letterResponse(LetterRun $run, array $letter): JSONResponse
    {
        return new JSONResponse(
            [
                'filename'        => $run->filename.'.pdf',
                'mimeType'        => 'application/pdf',
                'content'         => base64_encode($letter['content']),
                'fileId'          => $letter['fileId'],
                'path'            => $letter['path'],
                'warnings'        => $letter['warnings'],
                'contactMomentId' => $letter['contactMomentId'],
            ]
        );

    }//end letterResponse()


    /**
     * POST /api/clients/{id}/letters/preview: the letter as it would be made, and what was filled in.
     *
     * Body as for create(). Nothing is made, filed or logged. The answer
     * carries filinq's preview HTML, the fields it filled (label, value,
     * source, filled), whether all are filled, and filinq's warnings.
     *
     * @param string $id The client id.
     *
     * @return JSONResponse The preview, or an error.
     *
     * @spec openspec/specs/client-letters/spec.md
     */
    #[NoAdminRequired]
    public function preview(string $id): JSONResponse
    {
        $run = $this->letterRun(clientId: $id, action: 'previewed');
        if ($run instanceof JSONResponse) {
            return $run;
        }

        try {
            return new JSONResponse($this->letters->preview(templateId: $run->templateId, dataRefs: $run->records['refs']));
        } catch (RuntimeException $e) {
            return new JSONResponse(['message' => 'filinq could not preview the letter.', 'reason' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
        }

    }//end preview()


    /**
     * Check a letter or preview request and read what it is made from.
     *
     * Refuses, in this order: no user (401), no template id (400), no
     * filinq (503), a record the caller may not read (404), an unknown
     * template (404).
     *
     * @param string $clientId The client id.
     * @param string $action   How the refusal without filinq names the action: made or previewed.
     *
     * @return LetterRun|JSONResponse The run, or the refusal.
     */
    private function letterRun(string $clientId, string $action): LetterRun|JSONResponse
    {
        $templateId = $this->requestedTemplateId();
        if ($templateId instanceof JSONResponse) {
            return $templateId;
        }

        $refusal = $this->unavailableRefusal(action: $action);
        if ($refusal !== null) {
            return $refusal;
        }

        $run = new LetterRun(clientId: $clientId, templateId: $templateId);
        return $this->withRecordsAndTemplate(run: $run);

    }//end letterRun()


    /**
     * The template id asked for, by a logged-in user.
     *
     * @return string|JSONResponse The template id, or the 401 / 400 refusal.
     */
    private function requestedTemplateId(): string|JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        $templateId = trim((string) $this->request->getParam('templateId', ''));
        if ($templateId === '') {
            return new JSONResponse(['message' => 'Missing required parameter: templateId'], Http::STATUS_BAD_REQUEST);
        }

        return $templateId;

    }//end requestedTemplateId()


    /**
     * The 503 refusal when filinq is not available.
     *
     * @param string $action How the message names the action: made or previewed.
     *
     * @return JSONResponse|null The refusal, or null when filinq is available.
     */
    private function unavailableRefusal(string $action): ?JSONResponse
    {
        $reason = $this->letters->unavailableReason();
        if ($reason === null) {
            return null;
        }

        return new JSONResponse(
            ['message' => 'filinq is not available, so no letter can be '.$action.'.', 'reason' => $reason],
            Http::STATUS_SERVICE_UNAVAILABLE
        );

    }//end unavailableRefusal()


    /**
     * Read the records as the caller, then the template.
     *
     * @param LetterRun $run The run.
     *
     * @return LetterRun|JSONResponse The run with its records and template, or the 404.
     */
    private function withRecordsAndTemplate(LetterRun $run): LetterRun|JSONResponse
    {
        $records = $this->readRecords(clientId: $run->clientId);
        if (is_string($records) === true) {
            return new JSONResponse(['message' => $records], Http::STATUS_NOT_FOUND);
        }

        $template = $this->letters->findTemplate(templateId: $run->templateId);
        if ($template === null) {
            return new JSONResponse(['message' => 'Template not found'], Http::STATUS_NOT_FOUND);
        }

        $run->records  = $records;
        $run->template = $template;
        return $run;

    }//end withRecordsAndTemplate()


    /**
     * Have filinq render the letter; a download-only letter is only returned.
     *
     * @param LetterRun $run          The run.
     * @param bool      $downloadOnly Whether to skip the copy in Files.
     *
     * @return array<string, mixed>|JSONResponse The letter, or the 502.
     */
    private function renderLetter(LetterRun $run, bool $downloadOnly): array|JSONResponse
    {
        $outputMode = match ($downloadOnly) {
            true    => 'return',
            default => 'both',
        };

        try {
            return $this->letters->render(
                templateId: $run->templateId,
                dataRefs: $run->records['refs'],
                filename: $run->filename,
                outputMode: $outputMode
            );
        } catch (RuntimeException $e) {
            return new JSONResponse(['message' => 'filinq could not make the letter.', 'reason' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
        }

    }//end renderLetter()


    /**
     * Log the letter on the client as a contact moment.
     *
     * @param LetterRun   $run  The run.
     * @param string|null $path The path of the copy in Files.
     *
     * @return string|null The contact moment id.
     */
    private function logLetter(LetterRun $run, ?string $path): ?string
    {
        return $this->log->log(
            clientId: $run->clientId,
            contactId: $run->records['contactId'],
            ticketId: $run->records['ticketId'],
            templateName: $run->template['name'],
            path: $path
        );

    }//end logLetter()


    /**
     * Read the client, and the contact, ticket and appointment named in the request, as the caller.
     *
     * @param string $clientId The client id.
     *
     * @return array<string, mixed>|string The records (`client`, `contactId`, `ticketId`, `refs`: filinq's
     *         references), or the not-found message.
     *
     * @spec openspec/specs/client-letters/spec.md#requirement-a-letter-reads-the-clients-appointment
     */
    private function readRecords(string $clientId): array|string
    {
        $client = $this->readOwnClient(clientId: $clientId);
        if ($client === null) {
            return 'Client not found';
        }

        $register = $this->reader->register();
        $refs     = [['register' => $register, 'schema' => 'client', 'id' => $clientId]];

        $linked = $this->linkedRecords(clientId: $clientId, register: $register);
        if (is_string($linked) === true) {
            return $linked;
        }

        return [
            'client'    => $client,
            'contactId' => $linked['contactId'],
            'ticketId'  => $linked['ticketId'],
            'refs'      => array_merge($refs, $linked['refs']),
        ];

    }//end readRecords()


    /**
     * Read the client as the caller, or null when it is absent or not theirs.
     *
     * @param string $clientId The client id.
     *
     * @return array<string, mixed>|null
     */
    private function readOwnClient(string $clientId): array|null
    {
        $client = $this->reader->read(id: $clientId, schemaKey: 'client_schema');
        // Existence is not authorization: OpenRegister answers a read whatever
        // `_rbac` says (see ActivityTimelineController), so this app decides.
        $uid = (string) $this->userSession->getUser()?->getUID();
        if ($client === null || $this->accessPolicy->mayAccess(uid: $uid, object: $client, ownerField: 'ownerId') === false) {
            return null;
        }

        return $client;

    }//end readOwnClient()


    /**
     * The contact, ticket and appointment named in the request, checked in that order.
     *
     * @param string $clientId The client id.
     * @param string $register The register.
     *
     * @return array{contactId: string|null, ticketId: string|null, refs: array<int, array<string, string>>}|string
     *         The ids and filinq references, or the not-found message.
     */
    private function linkedRecords(string $clientId, string $register): array|string
    {
        $contactId  = $this->optionalId(name: 'contactId');
        $contactRef = $this->contactRef(contactId: $contactId, register: $register);
        if (is_string($contactRef) === true) {
            return $contactRef;
        }

        $ticketId  = $this->optionalId(name: 'ticketId');
        $ticketRef = $this->ticketRef(ticketId: $ticketId, clientId: $clientId, register: $register);
        if (is_string($ticketRef) === true) {
            return $ticketRef;
        }

        $appointmentId  = $this->optionalId(name: 'appointmentId');
        $appointmentRef = $this->appointmentRef(appointmentId: $appointmentId, clientId: $clientId, ticketId: $ticketId, register: $register);
        if (is_string($appointmentRef) === true) {
            return $appointmentRef;
        }

        return ['contactId' => $contactId, 'ticketId' => $ticketId, 'refs' => array_values(array_filter([$contactRef, $ticketRef, $appointmentRef]))];

    }//end linkedRecords()


    /**
     * The filinq reference of the named contact, null when none is named, or the not-found message.
     *
     * @param string|null $contactId The contact id.
     * @param string      $register  The register.
     *
     * @return array<string, string>|string|null
     */
    private function contactRef(string|null $contactId, string $register): array|string|null
    {
        if ($contactId === null) {
            return null;
        }

        if ($this->reader->read(id: $contactId, schemaKey: 'contact_schema') === null) {
            return 'Contact not found';
        }

        return ['register' => $register, 'schema' => 'contact', 'id' => $contactId];

    }//end contactRef()


    /**
     * The filinq reference of the named ticket, null when none is named, or the not-found message.
     *
     * @param string|null $ticketId The ticket id.
     * @param string      $clientId The client id.
     * @param string      $register The register.
     *
     * @return array<string, string>|string|null
     */
    private function ticketRef(string|null $ticketId, string $clientId, string $register): array|string|null
    {
        if ($ticketId === null) {
            return null;
        }

        // A ticket of another client would log the letter on the wrong timeline.
        if ($this->ticketOfClient(ticketId: $ticketId, clientId: $clientId) === false) {
            return 'Ticket not found';
        }

        return ['register' => $register, 'schema' => 'ticket', 'id' => $ticketId];

    }//end ticketRef()


    /**
     * The filinq reference of the named appointment, null when none is named, or the not-found message.
     *
     * @param string|null $appointmentId The appointment id.
     * @param string      $clientId      The client id.
     * @param string|null $ticketId      The ticket id.
     * @param string      $register      The register.
     *
     * @return array<string, string>|string|null
     */
    private function appointmentRef(string|null $appointmentId, string $clientId, string|null $ticketId, string $register): array|string|null
    {
        if ($appointmentId === null) {
            return null;
        }

        if ($this->appointmentOf(appointmentId: $appointmentId, clientId: $clientId, ticketId: $ticketId) === false) {
            return 'Appointment not found';
        }

        return ['register' => $register, 'schema' => 'appointmentBooking', 'id' => $appointmentId];

    }//end appointmentRef()


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
     * Whether the appointment exists, is readable and belongs to the client or to the letter's ticket.
     *
     * @param string      $appointmentId The appointment id.
     * @param string      $clientId      The client id.
     * @param string|null $ticketId      The letter's ticket, if any.
     *
     * @return bool True when the letter may read it.
     *
     * @spec openspec/specs/client-letters/spec.md#requirement-a-letter-reads-the-clients-appointment
     */
    private function appointmentOf(string $appointmentId, string $clientId, ?string $ticketId): bool
    {
        $appointment = $this->reader->read(id: $appointmentId, schemaKey: 'booking_schema');
        if ($appointment === null) {
            return false;
        }

        if ((string) ($appointment['customerId'] ?? '') === $clientId) {
            return true;
        }

        return $ticketId !== null && (string) ($appointment['ticket'] ?? '') === $ticketId;

    }//end appointmentOf()


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
