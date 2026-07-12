<?php

/**
 * Pipelinq NotesController.
 *
 * Controller for managing notes (comments) on Pipelinq entities.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-29
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\NoteEventService;
use OCA\Pipelinq\Service\NotesService;
use OCA\Pipelinq\Service\SettingsService;
use OCA\Pipelinq\Service\TicketService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Controller for managing notes on Pipelinq entities.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Wires the collaborators a notes
 *  endpoint legitimately needs (notes + event services, session, l10n, logger,
 *  group manager, settings, ticket resolver, OR container); splitting them would
 *  add indirection without reducing real coupling.
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-29
 */
class NotesController extends Controller
{
    /**
     * Maps the pipelinq_ objectType prefix to its settings config key for schema resolution.
     *
     * This ensures objectExists() scopes OR lookups to this app's own register+schema,
     * preventing IDOR against objects in other apps or registers.
     *
     * Ticket-backed object types (see OBJECT_TYPE_TO_TICKET_TYPE) are absent here:
     * they resolve through TicketService instead of a per-entity schema key.
     *
     * @var array<string, string>
     */
    private const OBJECT_TYPE_TO_SCHEMA_KEY = [
        'pipelinq_client'  => 'client_schema',
        'pipelinq_contact' => 'contact_schema',
        'pipelinq_lead'    => 'lead_schema',
    ];

    /**
     * Maps the ticket-backed objectType slugs onto their `ticketType` discriminator.
     *
     * The EXTERNAL slugs (`pipelinq_request`) are a public contract and stay stable;
     * internally they now resolve to the unified `ticket` schema narrowed by the
     * discriminator (unify-ticket-supertype), so a note may still only be attached to
     * a ticket of the matching subtype.
     *
     * @var array<string, string>
     */
    private const OBJECT_TYPE_TO_TICKET_TYPE = [
        'pipelinq_request' => TicketService::TYPE_REQUEST,
    ];

    /**
     * Constructor.
     *
     * @param IRequest           $request          The request.
     * @param NotesService       $notesService     The notes service.
     * @param NoteEventService   $noteEventService The note event service.
     * @param IUserSession       $userSession      The user session.
     * @param IL10N              $l10n             The localization service.
     * @param LoggerInterface    $logger           The logger.
     * @param ContainerInterface $container        The DI container.
     * @param IGroupManager      $groupManager     The group manager.
     * @param SettingsService    $settingsService  The settings service.
     * @param TicketService      $ticketService    The unified ticket resolver.
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Nextcloud controllers are wired
     *  purely by constructor DI; every parameter is a collaborator the notes endpoint
     *  uses directly, and a parameter object would only hide the wiring.
     */
    public function __construct(
        IRequest $request,
        private NotesService $notesService,
        private NoteEventService $noteEventService,
        private IUserSession $userSession,
        private IL10N $l10n,
        private LoggerInterface $logger,
        private ContainerInterface $container,
        private IGroupManager $groupManager,
        private SettingsService $settingsService,
        private TicketService $ticketService,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Resolve the register + schema an objectType's objects live in.
     *
     * Ticket-backed types (`pipelinq_request`) resolve to the unified `ticket`
     * schema through TicketService; every other type keeps its own schema config
     * key from the app settings.
     *
     * @param string|null $schemaKey The settings schema key, or null for ticket-backed types.
     *
     * @return array{0: string, 1: string} The [registerId, schemaId] pair ('' when unconfigured).
     */
    private function resolveScope(?string $schemaKey): array
    {
        if ($schemaKey === null) {
            return [$this->ticketService->getRegisterId(), $this->ticketService->getSchemaId()];
        }

        $settings = $this->settingsService->getSettings();

        return [
            (string) ($settings['register'] ?? ''),
            (string) ($settings[$schemaKey] ?? ''),
        ];
    }//end resolveScope()

    /**
     * Whether a found ticket carries the expected `ticketType` discriminator.
     *
     * The unified `ticket` schema holds every subtype, so schema scoping alone no
     * longer proves the object is the subtype the caller named. A payload that
     * cannot be read fails closed, mirroring objectExists()'s contract.
     *
     * @param mixed  $object     The object returned by OpenRegister.
     * @param string $ticketType The expected TicketService::TYPE_* value.
     *
     * @return bool Whether the object is a ticket of that subtype.
     */
    private function isTicketOfType(mixed $object, string $ticketType): bool
    {
        $data = null;
        if (is_array($object) === true) {
            $data = $object;
        } else if (is_object($object) === true && method_exists($object, 'getObject') === true) {
            $payload = $object->getObject();
            if (is_array($payload) === true) {
                $data = $payload;
            }
        }

        if ($data === null) {
            $this->logger->warning('NotesController: ticket payload unreadable', ['ticketType' => $ticketType]);
            return false;
        }

        return (string) ($data['ticketType'] ?? '') === $ticketType;
    }//end isTicketOfType()

    /**
     * Verify that the underlying OR object exists within this app's own register+schema.
     *
     * Scopes the lookup to the app's register and the schema that corresponds to
     * the given objectType. Returns false when the object is not found in the
     * expected schema (including when it exists in a different app's schema — IDOR
     * prevention), and — for ticket-backed types — when the found ticket carries a
     * different `ticketType`. Returns false on all unexpected errors (fail-closed)
     * so that a broken OR connection does not silently grant access.
     *
     * @param string $objectType The pipelinq object type (must be in VALID_TYPES).
     * @param string $objectId   The OR object UUID.
     *
     * @return bool Whether the object exists and belongs to this app.
     */
    private function objectExists(string $objectType, string $objectId): bool
    {
        $ticketType = (self::OBJECT_TYPE_TO_TICKET_TYPE[$objectType] ?? null);
        $schemaKey  = (self::OBJECT_TYPE_TO_SCHEMA_KEY[$objectType] ?? null);
        if ($ticketType === null && $schemaKey === null) {
            return false;
        }

        try {
            [$registerId, $schemaId] = $this->resolveScope(schemaKey: $schemaKey);

            if ($registerId === '' || $schemaId === '') {
                // Settings not yet configured — fail closed.
                $this->logger->warning('NotesController: register or schema not configured', ['objectType' => $objectType]);
                return false;
            }

            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $object        = $objectService->find(
                id: $objectId,
                register: $registerId,
                schema: $schemaId
            );
            if ($object === null) {
                return false;
            }

            if ($ticketType === null) {
                return true;
            }

            return $this->isTicketOfType(object: $object, ticketType: $ticketType);
        } catch (\OCP\DB\Exception | \OCP\AppFramework\Db\DoesNotExistException $e) {
            // Expected "not found" path — return false without noise.
            return false;
        } catch (\Throwable $e) {
            // Unexpected error — fail closed and log.
            $this->logger->error('NotesController: objectExists check failed', ['objectId' => $objectId, 'exception' => $e->getMessage()]);
            return false;
        }//end try
    }//end objectExists()

    /**
     * List notes for an entity.
     *
     * @param string $objectType The object type.
     * @param string $objectId   The object ID.
     *
     * @return JSONResponse The response containing notes.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-29
     */
    public function list(string $objectType, string $objectId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
        }

        if (in_array($objectType, NotesService::VALID_TYPES, true) === false) {
            return new JSONResponse(['error' => $this->l10n->t('Invalid object type')], 400);
        }

        if ($this->objectExists(objectType: $objectType, objectId: $objectId) === false) {
            return new JSONResponse(['error' => $this->l10n->t('Object not found')], Http::STATUS_NOT_FOUND);
        }

        try {
            $notes = $this->notesService->getNotes(
                objectType: $objectType,
                objectId: $objectId
            );
            return new JSONResponse(['notes' => $notes]);
        } catch (\Exception $e) {
            $this->logger->error('NotesController: list failed', ['exception' => $e]);
            return new JSONResponse(['error' => $this->l10n->t('An unexpected error occurred')], 500);
        }
    }//end list()

    /**
     * Create a note on an entity.
     *
     * @param string $objectType The object type.
     * @param string $objectId   The object ID.
     *
     * @return JSONResponse The response containing the created note.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-29
     */
    public function create(string $objectType, string $objectId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
        }

        if (in_array($objectType, NotesService::VALID_TYPES, true) === false) {
            return new JSONResponse(['error' => $this->l10n->t('Invalid object type')], 400);
        }

        if ($this->objectExists(objectType: $objectType, objectId: $objectId) === false) {
            return new JSONResponse(['error' => $this->l10n->t('Object not found')], Http::STATUS_NOT_FOUND);
        }

        $message = $this->request->getParam('message', '');
        if (trim($message) === '') {
            return new JSONResponse(['error' => $this->l10n->t('Message is required')], 400);
        }

        try {
            $note = $this->notesService->addNote(
                objectType: $objectType,
                objectId: $objectId,
                message: $message
            );

            $this->noteEventService->triggerNoteEvents(
                objectType: $objectType,
                objectId: $objectId
            );

            return new JSONResponse(['note' => $note]);
        } catch (\Exception $e) {
            $this->logger->error('NotesController: create failed', ['exception' => $e]);
            return new JSONResponse(['error' => $this->l10n->t('An unexpected error occurred')], 500);
        }
    }//end create()

    /**
     * Delete all notes for an entity (admin/cleanup only).
     *
     * Restricted to Nextcloud administrators. Regular users may only delete
     * their own notes via deleteSingle(). This prevents any authenticated
     * user from bulk-deleting all notes on entities they do not own.
     *
     * @param string $objectType The object type.
     * @param string $objectId   The object ID.
     *
     * @return JSONResponse The response.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-29
     */
    #[NoAdminRequired]
    public function deleteAll(string $objectType, string $objectId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
        }

        if ($this->groupManager->isAdmin($user->getUID()) === false) {
            return new JSONResponse(['error' => $this->l10n->t('Admin privileges required')], Http::STATUS_FORBIDDEN);
        }

        if (in_array($objectType, NotesService::VALID_TYPES, true) === false) {
            return new JSONResponse(['error' => $this->l10n->t('Invalid object type')], 400);
        }

        if ($this->objectExists(objectType: $objectType, objectId: $objectId) === false) {
            return new JSONResponse(['error' => $this->l10n->t('Object not found')], Http::STATUS_NOT_FOUND);
        }

        try {
            $this->notesService->deleteAllNotes(
                objectType: $objectType,
                objectId: $objectId
            );
            return new JSONResponse(['success' => true]);
        } catch (\Exception $e) {
            $this->logger->error('NotesController: deleteAll failed', ['exception' => $e]);
            return new JSONResponse(['error' => $this->l10n->t('An unexpected error occurred')], 500);
        }
    }//end deleteAll()

    /**
     * Delete a single note (own notes only).
     *
     * @param int $noteId The note ID.
     *
     * @return JSONResponse The response.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-29
     */
    public function deleteSingle(int $noteId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $this->notesService->deleteNote(noteId: $noteId);
            return new JSONResponse(['success' => true]);
        } catch (\Exception $e) {
            $this->logger->warning('NotesController: deleteSingle denied', ['exception' => $e->getMessage(), 'noteId' => $noteId]);
            return new JSONResponse(['error' => $this->l10n->t('Not authorized or note not found')], Http::STATUS_FORBIDDEN);
        }
    }//end deleteSingle()
}//end class
