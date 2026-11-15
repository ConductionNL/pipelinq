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
 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\LoyaltyProgrammeServiceInterface;
use OCA\Pipelinq\Service\Lifecycle\SchemaLifecycleGraph;
use OCA\Pipelinq\Util\ConfiguredSchemaCount;
use OCA\Pipelinq\Util\InjectedObjectService;
use OCA\Pipelinq\Util\LoggedRegisterSchemaIds;
use OCA\Pipelinq\Util\PayloadToArray;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Programme activation + validation service.
 *
 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-001
 */
class LoyaltyProgrammeService implements LoyaltyProgrammeServiceInterface {
	use PayloadToArray;
	use LoggedRegisterSchemaIds;
	use ConfiguredSchemaCount;
	use InjectedObjectService;

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
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service.
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
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-001-01
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
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-001
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
		$current = (string)($programme['status'] ?? 'draft');
		$this->assertTransitionAllowed(from: $current, to: 'active');

		// Business activation guards stay in PHP: date-range coherence and the
		// "at least one points rule" / "at least one redemption option" cross-object
		// invariants cannot be expressed in the declarative lifecycle grammar.
		$errors = $this->validateForActivation(programmeId: $programmeId);
		if ($errors !== []) {
			throw new RuntimeException(implode('; ', $errors));
		}

		$programme['status'] = 'active';
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
			$graph = ['draft' => ['active']];
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
	 * @spec exclude loyalty has no spec at all; the change that specified it was archived
	 *   and nothing inherited it
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

		return $this->toArray(value: $object);
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
		return $this->countMatching(schemaKey: $schemaKey, filters: ['programmeId' => $programmeId]);
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

		return $this->toArray(value: $saved);
	}//end persist()
}//end class
