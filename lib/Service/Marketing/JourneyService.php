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
 * @spec openspec/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

use OCA\Pipelinq\Kernel\Contracts\JourneyServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * JourneyService: journeys, and the flows they compile into.
 *
 * @spec openspec/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
 */
class JourneyService implements JourneyServiceInterface {

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
	 * @spec openspec/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
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
	 * @spec openspec/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
	 */
	public function find(string $journeyId): ?array {
		return $this->store->find(schemaSlug: $this->schema(), id: $journeyId);
	}//end find()

	/**
	 * Every journey.
	 *
	 * @return array<int, array<string, mixed>> The journeys.
	 *
	 * @spec openspec/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
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
	 * @spec openspec/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
	 */
	public function save(array $payload, string $createdByUid, ?string $journeyId = null): ?array {
		$write = ['journeyId' => $journeyId, 'author' => $createdByUid];

		return $this->storedAndCompiled(journey: $this->stamped(payload: $payload, write: $write), write: $write);
	}//end save()

	/**
	 * The journey stamped for a write: updated now, created by the author when new, and its activation.
	 *
	 * @param array<string, mixed>                       $payload The journey fields.
	 * @param array{journeyId: ?string, author: string} $write   The id when updating (null when creating) and the author.
	 *
	 * @return array<string, mixed> The journey to store.
	 */
	private function stamped(array $payload, array $write): array {
		$payload['updatedAt'] = date('c', $this->time->getTime());
		$before = $this->storedBefore(journeyId: $write['journeyId']);
		if ($write['journeyId'] === null) {
			$payload['createdAt'] = $payload['updatedAt'];
			$payload['createdBy'] = $write['author'];
		}

		return $this->stampActivation(journey: $payload, before: $before);
	}//end stamped()

	/**
	 * Store a stamped journey and compile it as its author.
	 *
	 * @param array<string, mixed>                       $journey The journey to store.
	 * @param array{journeyId: ?string, author: string} $write   The id when updating (null when creating) and the author.
	 *
	 * @return array<string, mixed>|null The stored journey, or null when the write failed.
	 */
	private function storedAndCompiled(array $journey, array $write): ?array {
		$stored = $this->store->save(schemaSlug: $this->schema(), payload: $journey, id: $write['journeyId']);
		if ($stored === null) {
			return null;
		}

		return $this->compile(journeyId: $this->store->idOf($stored), runAs: $write['author']);
	}//end storedAndCompiled()

	/**
	 * The journey as stored before a write, or null for a new one.
	 *
	 * @param string|null $journeyId The id when updating, null when creating.
	 *
	 * @return array<string, mixed>|null The stored journey, or null.
	 */
	private function storedBefore(?string $journeyId): ?array {
		if ($journeyId === null) {
			return null;
		}

		return $this->find(journeyId: $journeyId);
	}//end storedBefore()

	/**
	 * Pause or resume a journey and compile it again, so its flow is
	 * switched off while paused and back on when resumed.
	 *
	 * @param string $journeyId Journey UUID or slug.
	 * @param string $status `active` or `paused`.
	 * @param string $runAs The identity a scheduled journey runs as.
	 *
	 * @return array<string, mixed>|null The journey with its flow status, or null when unknown.
	 *
	 * @spec openspec/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
	 */
	public function setStatus(string $journeyId, string $status, string $runAs = ''): ?array {
		$journey = $this->find(journeyId: $journeyId);
		if ($journey === null) {
			return null;
		}

		return $this->storedStatus(journey: $this->withStatus(journey: $journey, status: $status), write: ['journeyId' => $journeyId, 'runAs' => $runAs]);
	}//end setStatus()

	/**
	 * Store a journey with its new status and compile it again.
	 *
	 * @param array<string, mixed>                     $journey The journey to store.
	 * @param array{journeyId: string, runAs: string} $write   The journey UUID or slug, and the identity a scheduled journey runs as.
	 *
	 * @return array<string, mixed>|null The journey with its flow status, or null when the write failed.
	 */
	private function storedStatus(array $journey, array $write): ?array {
		$stored = $this->store->save(schemaSlug: $this->schema(), payload: $journey, id: $write['journeyId']);
		if ($stored === null) {
			return null;
		}

		return $this->compile(journeyId: $write['journeyId'], runAs: $write['runAs']);
	}//end storedStatus()

	/**
	 * The journey with a new status, stamped now.
	 *
	 * @param array<string, mixed> $journey The journey as stored.
	 * @param string               $status  `active` or `paused`.
	 *
	 * @return array<string, mixed> The journey to store.
	 */
	private function withStatus(array $journey, string $status): array {
		$before  = $journey;
		$journey['status']    = $status;
		$journey['updatedAt'] = date('c', $this->time->getTime());

		return $this->stampActivation(journey: $journey, before: $before);
	}//end withStatus()

	/**
	 * Compile one journey into a published flow.
	 *
	 * @param string $journeyId Journey UUID or slug.
	 * @param string $runAs The identity a scheduled journey runs as.
	 *
	 * @return array<string, mixed>|null The journey with its flow status, or null when unknown.
	 *
	 * @spec openspec/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
	 */
	public function compile(string $journeyId, string $runAs = ''): ?array {
		$journey = $this->find(journeyId: $journeyId);
		if ($journey === null) {
			return null;
		}

		$run = new JourneyCompileRun(journeyId: $journeyId, journey: $journey, runAs: $runAs);
		$flowService = $this->service(name: self::FLOW_SERVICE);
		if ($flowService === null) {
			return $this->record(run: $run, outcome: ['flowStatus' => 'engine_missing', 'flowError' => '', 'flowUuid' => '']);
		}

		return $this->saveFlow(flowService: $flowService, run: $run);
	}//end compile()

	/**
	 * Save the journey's flow document and record the outcome.
	 *
	 * @param object            $flowService The flow engine's service.
	 * @param JourneyCompileRun $run         The journey, its id and the identity it runs as.
	 *
	 * @return array<string, mixed> The journey with its flow status.
	 */
	private function saveFlow(object $flowService, JourneyCompileRun $run): array {
		$document = $this->compiler->documentFor(journey: $run->journey, journeyId: $run->journeyId, runAs: $run->runAs);
		$existing = trim((string)($run->journey['flowUuid'] ?? ''));
		$target = $this->flowTarget(existing: $existing);

		try {
			$flow = $flowService->save($document, $target);
		} catch (Throwable $e) {
			$this->logger->warning(
				'JourneyService.compile: the flow engine refused the document',
				['journeyId' => $run->journeyId, 'exception' => $e->getMessage()]
			);
			return $this->record(run: $run, outcome: ['flowStatus' => 'refused', 'flowError' => $e->getMessage(), 'flowUuid' => $existing]);
		}

		return $this->recordCompiled(flow: $flow, run: $run);
	}//end saveFlow()

	/**
	 * The flow uuid to update, or null to create a new flow.
	 *
	 * @param string $existing The journey's stored flow uuid, or ''.
	 *
	 * @return string|null
	 */
	private function flowTarget(string $existing): string|null {
		$target = null;
		if ($existing !== '') {
			$target = $existing;
		}

		return $target;
	}//end flowTarget()

	/**
	 * Publish the saved flow and record the journey as compiled.
	 *
	 * @param mixed             $flow The saved flow.
	 * @param JourneyCompileRun $run  The journey and its id.
	 *
	 * @return array<string, mixed> The journey with its flow status.
	 */
	private function recordCompiled(mixed $flow, JourneyCompileRun $run): array {
		$flowUuid = $this->uuidOf(flow: $flow);
		$this->publish(flow: $flow, journeyId: $run->journeyId);
		$outcome = [];
		if (trim((string)($run->journey['compiledAt'] ?? '')) === '') {
			$outcome['compiledAt'] = date('c', $this->time->getTime());
		}

		return $this->record(run: $run, outcome: $outcome + ['flowStatus' => 'compiled', 'flowError' => '', 'flowUuid' => $flowUuid]);
	}//end recordCompiled()

	/**
	 * Record one outcome of a journey run.
	 *
	 * @param array<string, mixed> $run The run fields.
	 *
	 * @return array<string, mixed>|null The stored run.
	 *
	 * @spec openspec/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-refuses-a-send-without-consent-and-names-the-contact
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
	 * @spec openspec/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-refuses-a-send-without-consent-and-names-the-contact
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
	 * Record when a journey went live: the moment its status becomes active.
	 *
	 * @param array<string, mixed>      $journey The journey as it will be stored, its `updatedAt` the current moment.
	 * @param array<string, mixed>|null $before  The journey as stored before, or null for a new one.
	 *
	 * @return array<string, mixed> The journey, with `activatedAt` set when it went live now.
	 *
	 * @spec openspec/specs/marketing-integrated-campaigns/spec.md
	 */
	private function stampActivation(array $journey, ?array $before): array {
		$wasActive = (($before['status'] ?? '') === 'active');
		if (($journey['status'] ?? '') === 'active' && $wasActive === false) {
			$journey['activatedAt'] = $journey['updatedAt'];
		}

		return $journey;
	}//end stampActivation()

	/**
	 * Store the compilation outcome on the journey.
	 *
	 * @param JourneyCompileRun    $run     The journey payload and its id.
	 * @param array<string, mixed> $outcome The flowStatus value, the engine's own words (`flowError`, or empty)
	 *                                      and the `flowUuid` (or empty), after any fields stamped with them.
	 *
	 * @return array<string, mixed> The journey as recorded.
	 */
	private function record(JourneyCompileRun $run, array $outcome): array {
		$journey = array_merge($run->journey, $outcome);

		$stored = $this->store->save(schemaSlug: $this->schema(), payload: $journey, id: $run->journeyId);
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
		// The Flow entity's getters are magic (__call), so method_exists never
		// finds getUuid; is_callable does.
		if (is_object($flow) === true && is_callable([$flow, 'getUuid']) === true) {
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
