<?php

/**
 * Pipelinq WalkInQueueService.
 *
 * Walk-in queue management for the appointment-booking surface (member 09 of 12).
 * Owns WalkInTicket creation, the waiting -> called -> served / abandoned state
 * machine, ETA computation from the schedule gaps surfaced by AvailabilityService
 * (member 02), and the rebalance routine fired by the WalkInQueueRebalanceJob
 * after a Booking completes (member 04 event).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Lifecycle\SchemaLifecycleGraph;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * WalkInQueueService — walk-in ticket lifecycle and queue rebalance.
 *
 * Ticket state machine:
 *   waiting -> called -> served
 *   waiting -> abandoned
 *   called  -> abandoned
 *   served / abandoned are terminal.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyMethods)           small single-purpose
 *  methods (ticket CRUD/state-machine + ETA computation + candidate filtering
 *  + accessors); each is individually under the complexity threshold.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) owns the full walk-in
 *  ticket lifecycle (create/call/serve/abandon), ETA computation, and
 *  rebalance in one cohesive service; splitting would scatter one
 *  transactional concern.
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */
class WalkInQueueService {
	/**
	 * App-config key for the WalkInTicket schema id/slug.
	 *
	 * @var string
	 */
	public const TICKET_SCHEMA_KEY = 'walkInTicket_schema';

	/**
	 * App-config key for the Service schema id/slug.
	 *
	 * @var string
	 */
	public const SERVICE_SCHEMA_KEY = 'service_schema';

	/**
	 * Allowed statuses (matches walkInTicket schema enum).
	 *
	 * @var array<int, string>
	 */
	public const STATUSES = [
		'waiting',
		'called',
		'served',
		'abandoned',
	];

	/**
	 * Schema slug whose `x-openregister-lifecycle` declares the ticket status graph.
	 *
	 * @var string
	 */
	private const TICKET_SCHEMA_SLUG = 'walkInTicket';

	/**
	 * Fallback ticket state-machine adjacency map, used only when the schema
	 * declaration is unreadable. The canonical source of truth is the walkInTicket
	 * schema's `x-openregister-lifecycle` annotation (ADR-031), which OpenRegister's
	 * LifecycleValidationListener also enforces on save. This constant must mirror it.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const FALLBACK_TRANSITIONS = [
		'waiting' => ['called', 'abandoned'],
		'called' => ['served', 'abandoned'],
		'served' => [],
		'abandoned' => [],
	];

	/**
	 * Maximum number of waiting tickets the rebalance / list operations read.
	 *
	 * @var int
	 */
	public const QUEUE_PAGE_SIZE = 200;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app configuration.
	 * @param AvailabilityService $availabilityService Member 02 — ETA computation source.
	 * @param LoggerInterface $logger The logger.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private AvailabilityService $availabilityService,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Create a new WalkInTicket.
	 *
	 * The ticket starts in `waiting` with `arrivedAt: now` (UTC ISO-8601) and
	 * `estimatedReadyAt` derived from the earliest free slot returned by
	 * AvailabilityService for the requested service (when serviceId is provided
	 * and the service resolves). When no service is provided or no schedule is
	 * configured, `estimatedReadyAt` is left unset.
	 *
	 * @param array<string, mixed> $data Ticket payload (displayName, customerId,
	 *                                   phone, serviceId).
	 *
	 * @return string The new WalkInTicket UUID.
	 *
	 * @throws InvalidArgumentException If displayName is missing.
	 * @throws RuntimeException If OpenRegister is unavailable.
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 *
	 * @orphaned-write-capability exclude redundant, not missing —
	 * src/components/bookings/WalkInQueuePanel.vue reads and writes walkInTicket
	 * objects DIRECTLY against OpenRegister (ADR-022), so this wrapper has no
	 * caller by design rather than by omission. callNext, callTicket,
	 * serveTicket and abandonTicket are equally caller-less; only rebalance()
	 * is live. Deleting the five wrappers is the agreed remedy and means
	 * deleting their unit tests too, which is not a change to make on a gate's
	 * say-so — pipelinq#764 item 5 carries that decision.
	 */
	public function createTicket(array $data): string {
		$displayName = trim((string)($data['displayName'] ?? ''));
		if ($displayName === '') {
			throw new InvalidArgumentException('displayName is required');
		}

		$serviceId = trim((string)($data['serviceId'] ?? ''));
		$customerId = trim((string)($data['customerId'] ?? ''));
		$phone = trim((string)($data['phone'] ?? ''));

		$nowIso = $this->nowIso();

		$payload = [
			'displayName' => $this->truncate(value: $displayName, max: 255),
			'arrivedAt' => $nowIso,
			'status' => 'waiting',
		];

		if ($customerId !== '') {
			$payload['customerId'] = $customerId;
		}

		if ($phone !== '') {
			$payload['phone'] = $this->truncate(value: $phone, max: 32);
		}

		if ($serviceId !== '') {
			$payload['serviceId'] = $serviceId;
			$eta = $this->computeEstimatedReadyAt(serviceId: $serviceId);
			if ($eta !== '') {
				$payload['estimatedReadyAt'] = $eta;
			}
		}

		$saved = $this->saveTicket(payload: $payload, uuid: null);
		$uuid = $this->idOf(object: $saved);
		if ($uuid === '') {
			throw new RuntimeException('WalkInTicket save returned no id');
		}

		return $uuid;
	}//end createTicket()

	/**
	 * Call the first waiting ticket in the queue (oldest arrivedAt).
	 *
	 * Transitions the ticket from `waiting` to `called` and stamps the
	 * (optional) assignedResourceId. Returns the ticket UUID, or empty when the
	 * queue is empty.
	 *
	 * @param string $assignedResourceId Optional resource id taking the ticket.
	 *
	 * @return string The transitioned ticket UUID, or '' when the queue is empty.
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	public function callNext(string $assignedResourceId = ''): string {
		$waiting = $this->listByStatus(status: 'waiting');
		if ($waiting === []) {
			return '';
		}

		usort(
			$waiting,
			static function (array $left, array $right): int {
				return strcmp(
					(string)($left['arrivedAt'] ?? ''),
					(string)($right['arrivedAt'] ?? '')
				);
			}
		);

		$first = $waiting[0];
		$ticket = $this->ticketAsArray(value: $first);
		$uuid = $this->idOf(object: $first);
		if ($uuid === '') {
			return '';
		}

		$this->callTicket(ticketId: $uuid, assignedResourceId: $assignedResourceId, preloaded: $ticket);
		return $uuid;
	}//end callNext()

	/**
	 * Transition a specific ticket from `waiting` to `called`.
	 *
	 * @param string $ticketId Ticket UUID.
	 * @param string $assignedResourceId Optional resource id.
	 * @param array<string, mixed>|null $preloaded Optional already-fetched ticket payload.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException If the transition is rejected.
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	public function callTicket(string $ticketId, string $assignedResourceId = '', ?array $preloaded = null): void {
		$ticket = $preloaded ?? $this->loadTicket(ticketId: $ticketId);
		$current = (string)($ticket['status'] ?? '');
		$this->assertTransitionAllowed(from: $current, to: 'called');

		$ticket['status'] = 'called';
		if ($assignedResourceId !== '') {
			$ticket['assignedResourceId'] = $assignedResourceId;
		}

		$this->saveTicket(payload: $this->stripSelf(payload: $ticket), uuid: $ticketId);
	}//end callTicket()

	/**
	 * Mark a (called or waiting) ticket as `served`.
	 *
	 * Stamps `actualServedAt` to the current UTC ISO timestamp.
	 *
	 * @param string $ticketId Ticket UUID.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException If the transition is rejected.
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	public function serveTicket(string $ticketId): void {
		$ticket = $this->loadTicket(ticketId: $ticketId);
		$current = (string)($ticket['status'] ?? '');
		$this->assertTransitionAllowed(from: $current, to: 'served');

		$ticket['status'] = 'served';
		$ticket['actualServedAt'] = $this->nowIso();

		$this->saveTicket(payload: $this->stripSelf(payload: $ticket), uuid: $ticketId);
	}//end serveTicket()

	/**
	 * Mark a (waiting or called) ticket as `abandoned`.
	 *
	 * @param string $ticketId Ticket UUID.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException If the transition is rejected.
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	public function abandonTicket(string $ticketId): void {
		$ticket = $this->loadTicket(ticketId: $ticketId);
		$current = (string)($ticket['status'] ?? '');
		$this->assertTransitionAllowed(from: $current, to: 'abandoned');

		$ticket['status'] = 'abandoned';

		$this->saveTicket(payload: $this->stripSelf(payload: $ticket), uuid: $ticketId);
	}//end abandonTicket()

	/**
	 * Rebalance estimatedReadyAt for all waiting tickets.
	 *
	 * Recomputed from the current schedule (AvailabilityService) so customers
	 * see live ETAs as scheduled appointments complete. Errors per ticket are
	 * logged and swallowed — the rebalance is best-effort.
	 *
	 * @return int The number of tickets touched.
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	public function rebalance(): int {
		$waiting = $this->listByStatus(status: 'waiting');
		if ($waiting === []) {
			return 0;
		}

		$touched = 0;
		foreach ($waiting as $row) {
			if ($this->rebalanceTicket(row: $row) === true) {
				$touched++;
			}
		}

		if ($touched > 0) {
			$this->logger->info(
				'Pipelinq: walk-in queue rebalanced',
				['updated' => $touched, 'total' => count($waiting)]
			);
		}

		return $touched;
	}//end rebalance()

	/**
	 * Recompute + persist a single waiting ticket's estimatedReadyAt.
	 *
	 * @param array<string, mixed>|object $row The ticket row from listByStatus.
	 *
	 * @return bool True when the ticket was updated, false when skipped/unchanged/failed.
	 */
	private function rebalanceTicket(array|object $row): bool {
		$ticket = $this->ticketAsArray(value: $row);
		$uuid = $this->idOf(object: $row);
		if ($uuid === '') {
			return false;
		}

		$serviceId = (string)($ticket['serviceId'] ?? '');
		if ($serviceId === '') {
			// No service link — no schedule-derived ETA possible.
			return false;
		}

		try {
			$eta = $this->computeEstimatedReadyAt(serviceId: $serviceId);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: walk-in rebalance ETA failed',
				['ticket' => $uuid, 'service' => $serviceId]
			);
			return false;
		}

		if ($eta === '') {
			return false;
		}

		if ((string)($ticket['estimatedReadyAt'] ?? '') === $eta) {
			return false;
		}

		$ticket['estimatedReadyAt'] = $eta;

		try {
			$this->saveTicket(payload: $this->stripSelf(payload: $ticket), uuid: $uuid);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: walk-in rebalance save failed',
				['ticket' => $uuid]
			);
			return false;
		}

		return true;
	}//end rebalanceTicket()

	/**
	 * Assert that a ticket status transition is permitted.
	 *
	 * @param string $from Current status.
	 * @param string $to Target status.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When rejected.
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	public function assertTransitionAllowed(string $from, string $to): void {
		$allowed = self::allowedTransitions();
		if (isset($allowed[$from]) === false) {
			throw new InvalidArgumentException(
				sprintf('Unknown WalkInTicket status: %s', $from)
			);
		}

		if (in_array($to, $allowed[$from], true) === false) {
			throw new InvalidArgumentException(
				sprintf('Invalid WalkInTicket transition: %s -> %s', $from, $to)
			);
		}
	}//end assertTransitionAllowed()

	/**
	 * WalkInTicket state-machine adjacency map.
	 *
	 * Derived from the walkInTicket schema's `x-openregister-lifecycle` declaration
	 * (ADR-031) — the single source of truth that OpenRegister's
	 * LifecycleValidationListener also enforces on save. `fullAdjacencyFor()` seeds
	 * a key for every declared state, so terminal states (served/abandoned) appear
	 * with an empty target list — preserving the "unknown status" vs "invalid
	 * transition" distinction in {@see assertTransitionAllowed()}. Falls back to the
	 * mirrored constant only when the declaration is unreadable.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function allowedTransitions(): array {
		$graph = (new SchemaLifecycleGraph())->fullAdjacencyFor(schemaSlug: self::TICKET_SCHEMA_SLUG);
		if ($graph === []) {
			return self::FALLBACK_TRANSITIONS;
		}

		return $graph;
	}//end allowedTransitions()

	/**
	 * Compute estimatedReadyAt for a given service from today's free slots.
	 *
	 * Picks the earliest free slot of any candidate resource for the service
	 * today. Returns ISO-8601 UTC timestamp or '' when no slot can be derived.
	 *
	 * @param string $serviceId Service UUID/slug.
	 *
	 * @return string ISO-8601 UTC timestamp, or '' when undeterminable.
	 */
	private function computeEstimatedReadyAt(string $serviceId): string {
		if ($serviceId === '') {
			return '';
		}

		$service = $this->loadServiceQuiet(serviceId: $serviceId);
		if ($service === null) {
			return '';
		}

		$duration = (int)($service['durationMinutes'] ?? 0);
		if ($duration <= 0) {
			return '';
		}

		$candidates = $this->loadCandidateResources(service: $service);
		if ($candidates === []) {
			return '';
		}

		$date = $this->todayLocalDate();
		$earliest = $this->findEarliestSlotStart(
			candidates: $candidates,
			date: $date,
			duration: $duration,
			serviceId: $serviceId
		);

		if ($earliest === null) {
			return '';
		}

		return $this->localTimeToUtcIso(date: $date, hhmm: $earliest);
	}//end computeEstimatedReadyAt()

	/**
	 * Find the earliest free-slot start time across a set of candidate resources.
	 *
	 * @param array<int, string> $candidates Candidate resource ids.
	 * @param string $date YYYY-MM-DD to check.
	 * @param int $duration Required service duration in minutes.
	 * @param string $serviceId Service id (for warning-log context only).
	 *
	 * @return string|null The earliest HH:MM start time, or null when none found.
	 */
	private function findEarliestSlotStart(array $candidates, string $date, int $duration, string $serviceId): ?string {
		$earliest = null;
		foreach ($candidates as $resourceId) {
			try {
				$slots = $this->availabilityService->computeAvailability(
					resourceId: $resourceId,
					date: $date,
					serviceDurationMinutes: $duration
				);
			} catch (\Throwable $e) {
				$this->logger->warning(
					'Pipelinq: walk-in ETA availability lookup failed',
					['service' => $serviceId, 'resource' => $resourceId]
				);
				continue;
			}

			foreach ($slots as $slot) {
				$startTime = $slot['startTime'];
				if ($startTime === '') {
					continue;
				}

				if ($earliest === null || strcmp($startTime, $earliest) < 0) {
					$earliest = $startTime;
				}
			}
		}//end foreach

		return $earliest;
	}//end findEarliestSlotStart()

	/**
	 * Read all tickets with a given status (best-effort, capped by QUEUE_PAGE_SIZE).
	 *
	 * @param string $status One of self::STATUSES.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function listByStatus(string $status): array {
		$register = $this->registerId();
		$schema = $this->schemaId(key: self::TICKET_SCHEMA_KEY);
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => ['status' => $status],
					'register' => $register,
					'schema' => $schema,
					'limit' => self::QUEUE_PAGE_SIZE,
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: walk-in queue lookup failed',
				['status' => $status]
			);
			return [];
		}

		if (is_array($rows) === false) {
			return [];
		}

		$out = [];
		foreach (array_values($rows) as $row) {
			$arr = $this->toArray(object: $row);
			if ($arr !== null) {
				$out[] = $arr;
			}
		}

		return $out;
	}//end listByStatus()

	/**
	 * Load a WalkInTicket by id, throwing when not found.
	 *
	 * @param string $ticketId Ticket UUID.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws InvalidArgumentException If empty.
	 * @throws RuntimeException If not found.
	 */
	private function loadTicket(string $ticketId): array {
		if ($ticketId === '') {
			throw new InvalidArgumentException('ticketId must be a non-empty string');
		}

		$register = $this->registerId();
		$schema = $this->schemaId(key: self::TICKET_SCHEMA_KEY);
		if ($register === '' || $schema === '') {
			throw new RuntimeException('WalkInTicket register/schema not configured');
		}

		try {
			$found = $this->getObjectService()->find(
				id: $ticketId,
				register: $register,
				schema: $schema
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: walk-in ticket load failed', ['ticket' => $ticketId]);
			throw new RuntimeException('WalkInTicket lookup failed', 0, $e);
		}

		$data = $this->toArray(object: $found);
		if ($data === null) {
			throw new RuntimeException(sprintf('WalkInTicket %s not found', $ticketId));
		}

		return $data;
	}//end loadTicket()

	/**
	 * Load a Service by id without throwing.
	 *
	 * @param string $serviceId Service UUID/slug.
	 *
	 * @return array<string, mixed>|null
	 */
	private function loadServiceQuiet(string $serviceId): ?array {
		if ($serviceId === '') {
			return null;
		}

		$register = $this->registerId();
		$schema = $this->schemaId(key: self::SERVICE_SCHEMA_KEY);
		if ($register === '' || $schema === '') {
			return null;
		}

		try {
			$found = $this->getObjectService()->find(
				id: $serviceId,
				register: $register,
				schema: $schema
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: walk-in service load failed', ['service' => $serviceId]);
			return null;
		}

		return $this->toArray(object: $found);
	}//end loadServiceQuiet()

	/**
	 * Resolve candidate resource ids for a service (working subset for ETA).
	 *
	 * For the walk-in ETA we use the service's requiredResourceTypes filter
	 * against active+bookable resources. EligibilityService is not consulted
	 * here — skill matching is enforced when the walk-in is actually called
	 * (operator action) rather than for the ETA preview.
	 *
	 * @param array<string, mixed> $service Service entity.
	 *
	 * @return array<int, string>
	 */
	private function loadCandidateResources(array $service): array {
		$register = $this->registerId();
		$schema = $this->schemaId(key: 'resource_schema');
		$requiredTypes = $this->resolveRequiredTypes(service: $service);

		if ($register === '' || $schema === '') {
			return [];
		}

		$candidates = [];
		foreach ($this->fetchResourceRows(register: $register, schema: $schema) as $row) {
			$resource = $this->toArray(object: $row);
			if ($resource === null || $this->isCandidateResource(resource: $resource, requiredTypes: $requiredTypes) === false) {
				continue;
			}

			$id = $this->idOf(object: $resource);
			if ($id !== '') {
				$candidates[] = $id;
			}
		}//end foreach

		return $candidates;
	}//end loadCandidateResources()

	/**
	 * Normalise a service's `requiredResourceTypes` filter.
	 *
	 * @param array<string, mixed> $service Service entity.
	 *
	 * @return array<int, string>
	 */
	private function resolveRequiredTypes(array $service): array {
		$requiredTypes = ($service['requiredResourceTypes'] ?? []);
		if (is_array($requiredTypes) === false) {
			return [];
		}

		return $requiredTypes;
	}//end resolveRequiredTypes()

	/**
	 * Fetch resource rows for candidate resolution (best-effort, empty on failure).
	 *
	 * @param string $register The register id.
	 * @param string $schema The resource schema id.
	 *
	 * @return array<int, array<string, mixed>|object>
	 */
	private function fetchResourceRows(string $register, string $schema): array {
		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => [],
					'register' => $register,
					'schema' => $schema,
					'limit' => self::QUEUE_PAGE_SIZE,
				]
			);
		} catch (\Throwable $e) {
			return [];
		}

		if (is_array($rows) === false) {
			return [];
		}

		return array_values($rows);
	}//end fetchResourceRows()

	/**
	 * Whether a resource qualifies as a walk-in ETA candidate.
	 *
	 * Bookable, active, and (when the service declares required types) of a
	 * matching type.
	 *
	 * @param array<string, mixed> $resource The resource entity.
	 * @param array<int, string> $requiredTypes The service's requiredResourceTypes filter.
	 *
	 * @return bool
	 */
	private function isCandidateResource(array $resource, array $requiredTypes): bool {
		if (($resource['bookable'] ?? true) === false) {
			return false;
		}

		$status = (string)($resource['status'] ?? 'active');
		if ($status !== 'active') {
			return false;
		}

		if ($requiredTypes !== []) {
			$type = (string)($resource['type'] ?? '');
			if (in_array($type, $requiredTypes, true) === false) {
				return false;
			}
		}

		return true;
	}//end isCandidateResource()

	/**
	 * Persist a WalkInTicket (create when uuid is null, update otherwise).
	 *
	 * @param array<string, mixed> $payload Ticket payload.
	 * @param string|null $uuid UUID for updates.
	 *
	 * @return array<string, mixed>|object
	 */
	private function saveTicket(array $payload, ?string $uuid): array|object {
		$register = $this->registerId();
		$schema = $this->schemaId(key: self::TICKET_SCHEMA_KEY);
		if ($register === '' || $schema === '') {
			throw new RuntimeException('WalkInTicket register/schema not configured');
		}

		return $this->getObjectService()->saveObject(
			object: $payload,
			extend: [],
			register: $register,
			schema: $schema,
			uuid: $uuid
		);
	}//end saveTicket()

	/**
	 * Ensure a row is normalised as a plain array (for in-memory mutation).
	 *
	 * @param array<string, mixed>|object $value Row from listByStatus.
	 *
	 * @return array<string, mixed>
	 */
	private function ticketAsArray(array|object $value): array {
		$arr = $this->toArray(object: $value);
		return ($arr ?? []);
	}//end ticketAsArray()

	/**
	 * Strip OpenRegister metadata before re-saving.
	 *
	 * @param array<string, mixed> $payload Ticket payload.
	 *
	 * @return array<string, mixed>
	 */
	private function stripSelf(array $payload): array {
		if (array_key_exists('@self', $payload) === true) {
			unset($payload['@self']);
		}

		return $payload;
	}//end stripSelf()

	/**
	 * Truncate a string to a maximum length.
	 *
	 * @param string $value Raw value.
	 * @param int $max Maximum length.
	 *
	 * @return string
	 */
	private function truncate(string $value, int $max): string {
		if (strlen($value) <= $max) {
			return $value;
		}

		return substr($value, 0, $max);
	}//end truncate()

	/**
	 * Compose a UTC ISO-8601 timestamp from a date + local HH:MM time.
	 *
	 * The AvailabilityService publishes free slots in the resource's local
	 * working-hours frame; we record it as UTC for storage consistency with
	 * arrivedAt / actualServedAt (and to keep clients timezone-agnostic).
	 *
	 * @param string $date YYYY-MM-DD.
	 * @param string $hhmm HH:MM in local working hours.
	 *
	 * @return string ISO-8601 UTC timestamp, or '' on parse failure.
	 */
	private function localTimeToUtcIso(string $date, string $hhmm): string {
		$composed = ($date . 'T' . $hhmm . ':00');
		try {
			$timestamp = new DateTimeImmutable($composed, new DateTimeZone('UTC'));
		} catch (\Throwable $e) {
			return '';
		}

		return $timestamp->format('Y-m-d\TH:i:sP');
	}//end localTimeToUtcIso()

	/**
	 * Today's date in local (UTC) format YYYY-MM-DD.
	 *
	 * @return string
	 */
	private function todayLocalDate(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
	}//end todayLocalDate()

	/**
	 * Now in ISO-8601 UTC.
	 *
	 * @return string
	 */
	private function nowIso(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP');
	}//end nowIso()

	/**
	 * The pipelinq register id from app config.
	 *
	 * Fails closed: '' means "unconfigured", and every caller refuses the
	 * OpenRegister call on it. An empty register must never be handed to
	 * OpenRegister — ObjectService skips setRegister() for an empty value, so
	 * the query silently inherits whatever register context an earlier call in
	 * the same request left on the shared service instance. The empty case is
	 * logged so an unprovisioned instance is visible rather than silent.
	 *
	 * @return string The configured register id, or '' when unconfigured.
	 */
	private function registerId(): string {
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($registerId === '') {
			$this->logger->warning(
				'Pipelinq: app-config "register" is not configured; OpenRegister calls are refused, not run unscoped'
			);
		}

		return $registerId;
	}//end registerId()

	/**
	 * Resolve a schema id by app-config key.
	 *
	 * @param string $key App-config key.
	 *
	 * @return string
	 */
	private function schemaId(string $key): string {
		return $this->appConfig->getValueString(Application::APP_ID, $key, '');
	}//end schemaId()

	/**
	 * Pull the canonical id out of a normalised OpenRegister object.
	 *
	 * @param array<string, mixed>|object $object Normalised object or entity.
	 *
	 * @return string
	 */
	private function idOf(array|object $object): string {
		$arr = $this->toArray(object: $object);
		if ($arr === null) {
			return '';
		}

		if (isset($arr['@self']) === true && is_array($arr['@self']) === true) {
			$self = $arr['@self'];
			if (isset($self['id']) === true) {
				return (string)$self['id'];
			}

			if (isset($self['uuid']) === true) {
				return (string)$self['uuid'];
			}
		}

		if (isset($arr['id']) === true) {
			return (string)$arr['id'];
		}

		if (isset($arr['uuid']) === true) {
			return (string)$arr['uuid'];
		}

		return '';
	}//end idOf()

	/**
	 * Normalise an OpenRegister entity (or array) to a plain array.
	 *
	 * @param mixed $object Entity, array, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	private function toArray(mixed $object): ?array {
		if ($object === null) {
			return null;
		}

		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true) {
			if (method_exists($object, 'jsonSerialize') === true) {
				$serialised = $object->jsonSerialize();
				if (is_array($serialised) === true) {
					return $serialised;
				}
			}

			if (method_exists($object, 'toArray') === true) {
				$arr = $object->toArray();
				if (is_array($arr) === true) {
					return $arr;
				}
			}

			return (array)$object;
		}

		return null;
	}//end toArray()

	/**
	 * Resolve the OpenRegister ObjectService via the DI container.
	 *
	 * @return object The ObjectService instance.
	 */
	private function getObjectService(): object {
		// Injected (ADR-083): a property read throws nothing, so the old
		// catch was unreachable — phpstan reports it as a dead catch.
		return $this->objectService;
	}//end getObjectService()
}//end class
