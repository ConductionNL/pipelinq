<?php

/**
 * Pipelinq LoyaltyProgrammeService.
 *
 * Owns LoyaltyProgramme lifecycle. Activation transitions from concept to actief
 * trigger validation of rules and redemption options (REQ-LOY-001).
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
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Lifecycle\SchemaLifecycleGraph;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Programme activation + validation service.
 *
 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-001
 */
class LoyaltyProgrammeService {
	/**
	 * Schema slug whose `x-openregister-lifecycle` declares the programme status graph.
	 *
	 * @var string
	 */
	private const PROGRAMME_SCHEMA_SLUG = 'loyaltyProgramme';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app configuration.
	 * @param LoggerInterface $logger The logger.
	 * @param SchemaLifecycleGraph $lifecycleGraph Reads the programme status graph from its schema.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		private SchemaLifecycleGraph $lifecycleGraph = new SchemaLifecycleGraph(),
	) {
	}//end __construct()

	/**
	 * Validate a programme for activation.
	 *
	 * @param string $programmeId The programme UUID.
	 *
	 * @return array<int, string> List of validation errors (empty when valid).
	 *
	 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-001-01
	 */
	public function validateForActivation(string $programmeId): array {
		$errors = [];
		$programme = $this->getProgramme(programmeId: $programmeId);
		if ($programme === null) {
			return ['Programme not found.'];
		}

		// Date range.
		$start = (string)($programme['startDate'] ?? '');
		$end = (string)($programme['endDate'] ?? '');
		if ($start !== '' && $end !== '' && $start > $end) {
			$errors[] = 'Cannot activate: einddatum must be after startdatum';
		}

		// At least one rule.
		$rules = $this->countByProgramme(schemaKey: 'pointsRule_schema', programmeId: $programmeId);
		if ($rules === 0) {
			$errors[] = 'Cannot activate: no points rules configured';
		}

		// At least one redemption option.
		$options = $this->countByProgramme(schemaKey: 'redemptionOption_schema', programmeId: $programmeId);
		if ($options === 0) {
			$errors[] = 'Cannot activate: no redemption options configured';
		}

		return $errors;
	}//end validateForActivation()

	/**
	 * Activate a programme (transition concept -> actief).
	 *
	 * @param string $programmeId The programme UUID.
	 * @param string $activatedBy The user id who triggered activation.
	 *
	 * @return array<string, mixed> The activated programme.
	 *
	 * @throws RuntimeException When validation fails.
	 */
	public function activate(string $programmeId, string $activatedBy): array {
		$programme = $this->getProgramme(programmeId: $programmeId);
		if ($programme === null) {
			throw new RuntimeException('Programme not found.');
		}

		// Declarative transition guard (ADR-031): the concept -> actief edge is
		// declared in the loyaltyProgramme schema's x-openregister-lifecycle map,
		// which OpenRegister's LifecycleValidationListener also enforces on save.
		// We assert it here so an out-of-state activation surfaces with a clear
		// message before the (more expensive) business validation runs.
		$current = (string)($programme['status'] ?? 'concept');
		$this->assertTransitionAllowed(from: $current, to: 'actief');

		// Business activation guards stay in PHP: date-range coherence and the
		// "at least one points rule" / "at least one redemption option" cross-object
		// invariants cannot be expressed in the declarative lifecycle grammar.
		$errors = $this->validateForActivation(programmeId: $programmeId);
		if ($errors !== []) {
			throw new RuntimeException(implode('; ', $errors));
		}

		$programme['status'] = 'actief';
		$this->logger->info(
			'Pipelinq: loyalty programme activated',
			['programmeId' => $programmeId, 'activatedBy' => $activatedBy]
		);

		return $this->persist(payload: $programme, uuid: $programmeId);
	}//end activate()

	/**
	 * Assert a programme status transition is permitted by the schema declaration.
	 *
	 * The allowed graph is read from the loyaltyProgramme schema's
	 * `x-openregister-lifecycle` annotation (ADR-031). Falls back to the single
	 * concept -> actief edge only when the declaration is unreadable, so a broken
	 * register file never regresses behavior.
	 *
	 * @param string $from Current status.
	 * @param string $to Target status.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the transition is not allowed.
	 */
	private function assertTransitionAllowed(string $from, string $to): void {
		$graph = $this->lifecycleGraph->adjacencyFor(schemaSlug: self::PROGRAMME_SCHEMA_SLUG);
		if ($graph === []) {
			// Fallback mirrors the schema's concept -> actief edge.
			$graph = ['concept' => ['actief']];
		}

		$allowed = ($graph[$from] ?? []);
		if (in_array($to, $allowed, true) === false) {
			throw new RuntimeException(
				sprintf("Cannot activate: transition from '%s' to '%s' is not allowed", $from, $to)
			);
		}
	}//end assertTransitionAllowed()

	/**
	 * Get a programme by UUID.
	 *
	 * @param string $programmeId The programme UUID.
	 *
	 * @return array<string, mixed>|null
	 */
	public function getProgramme(string $programmeId): ?array {
		[$register, $schema] = $this->config(schemaKey: 'loyaltyProgramme_schema');
		if ($register === '' || $schema === '' || $programmeId === '') {
			return null;
		}

		try {
			$object = $this->getObjectService()->find(id: $programmeId, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			return null;
		}

		if ($object === null) {
			return null;
		}

		return $this->toArray(object: $object);
	}//end getProgramme()

	/**
	 * Count objects in a schema filtered by programmeId.
	 *
	 * @param string $schemaKey AppConfig key for the schema id.
	 * @param string $programmeId The programme UUID.
	 *
	 * @return int
	 */
	private function countByProgramme(string $schemaKey, string $programmeId): int {
		[$register, $schema] = $this->config(schemaKey: $schemaKey);
		if ($register === '' || $schema === '') {
			return 0;
		}

		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'programmeId' => $programmeId,
						'register' => $register,
						'schema' => $schema,
					],
					'limit' => 1000,
				]
			);
		} catch (\Throwable $e) {
			return 0;
		}

		if (is_array($rows) === true) {
			return count($rows);
		}

		return 0;
	}//end countByProgramme()

	/**
	 * Persist a programme.
	 *
	 * @param array<string, mixed> $payload The programme data.
	 * @param ?string $uuid Update target.
	 *
	 * @return array<string, mixed>
	 */
	private function persist(array $payload, ?string $uuid): array {
		[$register, $schema] = $this->config(schemaKey: 'loyaltyProgramme_schema');
		if ($register === '' || $schema === '') {
			throw new RuntimeException('LoyaltyProgramme schema is not configured.');
		}

		$saved = $this->getObjectService()->saveObject(
			object: $payload,
			extend: [],
			register: $register,
			schema: $schema,
			uuid: $uuid
		);

		return $this->toArray(object: $saved);
	}//end persist()

	/**
	 * Resolve register + schema id.
	 *
	 * Fails closed: '' on either id means "unconfigured", and every caller
	 * refuses the OpenRegister call on it. An empty id must never be handed to
	 * OpenRegister — ObjectService skips setRegister()/setSchema() for an empty
	 * value, so the query silently inherits whatever context an earlier call in
	 * the same request left on the shared service instance. The empty case is
	 * logged so an unprovisioned instance is visible rather than silent.
	 *
	 * @param string $schemaKey The schema config key.
	 *
	 * @return array{0: string, 1: string} The [register, schema] ids, each ''
	 *                                     when unconfigured.
	 */
	private function config(string $schemaKey): array {
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schemaId = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
		if ($registerId === '' || $schemaId === '') {
			$this->logger->warning(
				'Pipelinq: register/schema not configured; OpenRegister calls are refused, not run unscoped',
				['schemaKey' => $schemaKey]
			);
		}

		return [$registerId, $schemaId];
	}//end config()

	/**
	 * Normalise OR entity/array to a plain array.
	 *
	 * @param mixed $object The entity or array.
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialized = $object->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$data = $object->getObject();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return [];
	}//end toArray()

	/**
	 * Get the OpenRegister ObjectService.
	 *
	 * @return object
	 */
	private function getObjectService(): object {
		// Injected (ADR-083): a property read throws nothing, so the old
		// catch was unreachable — phpstan reports it as a dead catch.
		return $this->objectService;
	}//end getObjectService()
}//end class
