<?php

/**
 * Pipelinq JourneyService.
 *
 * Reads and writes `journey` objects, and compiles one into a flow that
 * OpenRegister's engine owns and runs.
 *
 * 🔴 EVERY CALL INTO THE FLOW ENGINE IS DUCK-TYPED AND FAILS SOFT. Pipelinq
 * runs on instances where OpenRegister's flow engine is older than this
 * change, and a hard type hint would take the whole Marketing section down
 * with it. A journey that could not be compiled records `engine_missing` or
 * `refused` with the engine's own words, and stays inert. It never falls
 * back to a pipelinq-side loop: that would be the second engine ADR-094
 * exists to prevent.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * JourneyService: journeys, and the flows they compile into.
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
 */
class JourneyService {

	/**
	 * The journey schema slug.
	 *
	 * @var string
	 */
	public const JOURNEY_SCHEMA = 'journey';

	/**
	 * The journey run schema slug.
	 *
	 * @var string
	 */
	public const RUN_SCHEMA = 'journeyRun';

	/**
	 * OpenRegister's flow service, resolved by name so pipelinq boots without it.
	 *
	 * @var string
	 */
	public const FLOW_SERVICE = 'OCA\\OpenRegister\\Service\\Flow\\FlowService';

	/**
	 * OpenRegister's flow version service, which publishes version one.
	 *
	 * @var string
	 */
	public const VERSION_SERVICE = 'OCA\\OpenRegister\\Service\\Flow\\FlowVersionService';

	/**
	 * Constructor.
	 *
	 * @param ListObjectStore $store Register-scoped object plumbing.
	 * @param JourneyFlowCompiler $compiler Journey to flow document.
	 * @param ContainerInterface $container DI container, for the duck-typed flow engine.
	 * @param ITimeFactory $time Clock.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
	 */
	public function __construct(
		private ListObjectStore $store,
		private JourneyFlowCompiler $compiler,
		private ContainerInterface $container,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * One journey by id or slug.
	 *
	 * @param string $journeyId Journey UUID or slug.
	 *
	 * @return array<string, mixed>|null The journey, or null.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
	 */
	public function find(string $journeyId): ?array {
		return $this->store->find(schemaSlug: $this->schema(), id: $journeyId);
	}//end find()

	/**
	 * Every journey.
	 *
	 * @return array<int, array<string, mixed>> The journeys.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
	 */
	public function listJourneys(): array {
		return $this->store->findAll(schemaSlug: $this->schema());
	}//end listJourneys()

	/**
	 * Write a journey, then compile it.
	 *
	 * @param array<string, mixed> $payload The journey fields.
	 * @param string $createdByUid The author.
	 * @param string|null $journeyId The id when updating, null when creating.
	 *
	 * @return array<string, mixed>|null The stored journey, or null when the write failed.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
	 */
	public function save(array $payload, string $createdByUid, ?string $journeyId = null): ?array {
		$now = date('c', $this->time->getTime());
		$payload['updatedAt'] = $now;
		if ($journeyId === null) {
			$payload['createdAt'] = $now;
			$payload['createdBy'] = $createdByUid;
		}

		$stored = $this->store->save(schemaSlug: $this->schema(), payload: $payload, id: $journeyId);
		if ($stored === null) {
			return null;
		}

		return $this->compile(journeyId: $this->store->idOf($stored), runAs: $createdByUid);
	}//end save()

	/**
	 * Compile one journey into a published flow.
	 *
	 * @param string $journeyId Journey UUID or slug.
	 * @param string $runAs The identity a scheduled journey runs as.
	 *
	 * @return array<string, mixed>|null The journey with its flow status, or null when unknown.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
	 */
	public function compile(string $journeyId, string $runAs = ''): ?array {
		$journey = $this->find(journeyId: $journeyId);
		if ($journey === null) {
			return null;
		}

		$flowService = $this->service(name: self::FLOW_SERVICE);
		if ($flowService === null) {
			return $this->record(journey: $journey, journeyId: $journeyId, status: 'engine_missing', error: '', flowUuid: '');
		}

		$document = $this->compiler->documentFor(journey: $journey, journeyId: $journeyId, runAs: $runAs);
		$existing = trim((string)($journey['flowUuid'] ?? ''));
		$target = null;
		if ($existing !== '') {
			$target = $existing;
		}

		try {
			$flow = $flowService->save($document, $target);
		} catch (Throwable $e) {
			$this->logger->warning(
				'JourneyService.compile: the flow engine refused the document',
				['journeyId' => $journeyId, 'exception' => $e->getMessage()]
			);
			return $this->record(journey: $journey, journeyId: $journeyId, status: 'refused', error: $e->getMessage(), flowUuid: $existing);
		}

		$flowUuid = $this->uuidOf(flow: $flow);
		$this->publish(flow: $flow, journeyId: $journeyId);

		return $this->record(journey: $journey, journeyId: $journeyId, status: 'compiled', error: '', flowUuid: $flowUuid);
	}//end compile()

	/**
	 * Record one outcome of a journey run.
	 *
	 * @param array<string, mixed> $run The run fields.
	 *
	 * @return array<string, mixed>|null The stored run.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-refuses-a-send-without-consent-and-names-the-contact
	 */
	public function recordRun(array $run): ?array {
		$run['occurredAt'] = date('c', $this->time->getTime());
		return $this->store->save(
			schemaSlug: $this->store->schemaSlug('journeyRun_schema', self::RUN_SCHEMA),
			payload: $run
		);
	}//end recordRun()

	/**
	 * Every recorded run of one journey.
	 *
	 * @param string $journeyId Journey UUID or slug.
	 *
	 * @return array<int, array<string, mixed>> The runs.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-refuses-a-send-without-consent-and-names-the-contact
	 */
	public function runsFor(string $journeyId): array {
		$runs = [];
		$schema = $this->store->schemaSlug('journeyRun_schema', self::RUN_SCHEMA);
		foreach ($this->store->findAll(schemaSlug: $schema, filters: ['journeyId' => $journeyId]) as $run) {
			if ((string)($run['journeyId'] ?? '') === $journeyId) {
				$runs[] = $run;
			}
		}

		return $runs;
	}//end runsFor()

	/**
	 * Store the compilation outcome on the journey.
	 *
	 * @param array<string, mixed> $journey The journey payload.
	 * @param string $journeyId The journey id.
	 * @param string $status One of the flowStatus values.
	 * @param string $error The engine's own words, or empty.
	 * @param string $flowUuid The flow uuid, or empty.
	 *
	 * @return array<string, mixed> The journey as recorded.
	 */
	private function record(array $journey, string $journeyId, string $status, string $error, string $flowUuid): array {
		$journey['flowStatus'] = $status;
		$journey['flowError'] = $error;
		$journey['flowUuid'] = $flowUuid;

		$stored = $this->store->save(schemaSlug: $this->schema(), payload: $journey, id: $journeyId);
		return ($stored ?? $journey);
	}//end record()

	/**
	 * Publish version one so the flow can actually run.
	 *
	 * A saved flow that was never published is queued and then refused, and
	 * the refusal happens at trigger time rather than at save time, which is
	 * exactly where nobody is looking.
	 *
	 * @param mixed $flow The flow entity the engine returned.
	 * @param string $journeyId The journey, for the log line.
	 *
	 * @return void
	 */
	private function publish(mixed $flow, string $journeyId): void {
		$versions = $this->service(name: self::VERSION_SERVICE);
		if ($versions === null || is_object($flow) === false) {
			return;
		}

		try {
			$versions->publish(flow: $flow, publishedBy: null);
		} catch (Throwable $e) {
			$this->logger->info(
				'JourneyService.publish: the flow was saved but not published',
				['journeyId' => $journeyId, 'exception' => $e->getMessage()]
			);
		}
	}//end publish()

	/**
	 * The uuid of whatever the engine handed back.
	 *
	 * @param mixed $flow The flow entity.
	 *
	 * @return string The uuid, empty when it carries none.
	 */
	private function uuidOf(mixed $flow): string {
		if (is_object($flow) === true && method_exists($flow, 'getUuid') === true) {
			return (string)$flow->getUuid();
		}

		if (is_array($flow) === true) {
			return (string)($flow['uuid'] ?? '');
		}

		return '';
	}//end uuidOf()

	/**
	 * One OpenRegister service, or null when this instance has none.
	 *
	 * @param string $name The fully qualified class name.
	 *
	 * @return object|null The service.
	 */
	private function service(string $name): ?object {
		try {
			$service = $this->container->get($name);
		} catch (Throwable $e) {
			$this->logger->info(
				'JourneyService: the OpenRegister flow engine is not available',
				['service' => $name, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if (is_object($service) === false) {
			return null;
		}

		return $service;
	}//end service()

	/**
	 * The journey schema slug.
	 *
	 * @return string The slug.
	 */
	private function schema(): string {
		return $this->store->schemaSlug('journey_schema', self::JOURNEY_SCHEMA);
	}//end schema()
}//end class
