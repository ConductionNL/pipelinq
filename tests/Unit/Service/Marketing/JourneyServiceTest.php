<?php

/**
 * Unit tests for JourneyService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\Pipelinq\Service\Marketing\JourneyFlowCompiler;
use OCA\Pipelinq\Service\Marketing\JourneyService;
use OCA\Pipelinq\Tests\Unit\Support\InMemoryListObjectStore;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for JourneyService: what compilation records, and what happens when
 * the flow engine is absent or refuses.
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
 */
class JourneyServiceTest extends TestCase {

	/**
	 * The store journeys and runs live in.
	 *
	 * @var InMemoryListObjectStore
	 */
	private InMemoryListObjectStore $store;

	/**
	 * Set up a store holding one draft journey.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryListObjectStore([
			'journey' => [
				[
					'uuid' => 'journey-1',
					'name' => 'Win back',
					'status' => 'active',
					'trigger' => ['kind' => 'leadStageChanged'],
					'action' => ['kind' => 'createTask', 'taskSubject' => 'Call them'],
				],
			],
		]);
	}//end setUp()

	/**
	 * A clean compile records the flow uuid, publishes version one, and
	 * leaves no error behind.
	 *
	 * @return void
	 */
	public function testACleanCompileRecordsTheFlowAndPublishesIt(): void {
		$flowService = new FakeFlowService();
		$versionService = new FakeFlowVersionService();

		$journey = $this->service(flowService: $flowService, versionService: $versionService)
			->compile(journeyId: 'journey-1', runAs: 'admin');

		$this->assertSame('compiled', $journey['flowStatus']);
		$this->assertSame('flow-1', $journey['flowUuid']);
		$this->assertSame('', $journey['flowError']);
		$this->assertSame(1, $versionService->published);
		$this->assertSame('pipelinq', $flowService->lastDocument['triggerRegister']);
	}//end testACleanCompileRecordsTheFlowAndPublishesIt()

	/**
	 * With no flow service the journey is stored and inert, and says so.
	 * It must NOT fall back to a pipelinq-side loop: that would be the
	 * second engine ADR-094 exists to prevent.
	 *
	 * @return void
	 */
	public function testRecordsEngineMissingWhenTheFlowServiceIsAbsent(): void {
		$journey = $this->service(flowService: null, versionService: null)->compile(journeyId: 'journey-1');

		$this->assertSame('engine_missing', $journey['flowStatus']);
		$this->assertSame('', $journey['flowUuid']);
	}//end testRecordsEngineMissingWhenTheFlowServiceIsAbsent()

	/**
	 * A refusal keeps the engine's own words, because "could not save" tells
	 * a marketer nothing about which node the engine did not recognise.
	 *
	 * @return void
	 */
	public function testRecordsTheEnginesOwnWordsOnARefusal(): void {
		$flowService = new FakeFlowService();
		$flowService->refuseWith = 'unknown node type pipelinq.journey-action';

		$journey = $this->service(flowService: $flowService, versionService: null)->compile(journeyId: 'journey-1');

		$this->assertSame('refused', $journey['flowStatus']);
		$this->assertSame('unknown node type pipelinq.journey-action', $journey['flowError']);
	}//end testRecordsTheEnginesOwnWordsOnARefusal()

	/**
	 * A second compile updates the same flow rather than minting a new one,
	 * so a journey never accumulates flows nobody knows about.
	 *
	 * @return void
	 */
	public function testASecondCompileUpdatesTheSameFlow(): void {
		$flowService = new FakeFlowService();
		$service = $this->service(flowService: $flowService, versionService: null);

		$service->compile(journeyId: 'journey-1');
		$service->compile(journeyId: 'journey-1');

		$this->assertSame(['', 'flow-1'], $flowService->uuidsSeen);
	}//end testASecondCompileUpdatesTheSameFlow()

	/**
	 * A recorded run keeps the contact and the reason, which is the only
	 * place a refusal is ever visible.
	 *
	 * @return void
	 */
	public function testARecordedRunKeepsTheContactAndTheReason(): void {
		$this->service(flowService: null, versionService: null)->recordRun(run: [
			'journeyId' => 'journey-1',
			'contactId' => 'contact-1',
			'state' => 'refused',
			'reason' => 'no_consent',
		]);

		$runs = $this->service(flowService: null, versionService: null)->runsFor(journeyId: 'journey-1');

		$this->assertCount(1, $runs);
		$this->assertSame('contact-1', $runs[0]['contactId']);
		$this->assertSame('no_consent', $runs[0]['reason']);
		$this->assertNotSame('', (string)$runs[0]['occurredAt']);
	}//end testARecordedRunKeepsTheContactAndTheReason()

	/**
	 * A service wired to the given stand-ins.
	 *
	 * @param FakeFlowService|null $flowService The flow service, or null for none.
	 * @param FakeFlowVersionService|null $versionService The version service, or null.
	 *
	 * @return JourneyService The service.
	 */
	private function service(?FakeFlowService $flowService, ?FakeFlowVersionService $versionService): JourneyService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($flowService, $versionService) {
				if ($id === JourneyService::FLOW_SERVICE && $flowService !== null) {
					return $flowService;
				}

				if ($id === JourneyService::VERSION_SERVICE && $versionService !== null) {
					return $versionService;
				}

				throw new RuntimeException('not registered: ' . $id);
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn((int)strtotime('2026-09-05 12:00:00'));

		return new JourneyService(
			$this->store,
			new JourneyFlowCompiler(),
			$container,
			$time,
			$this->createMock(LoggerInterface::class),
		);
	}//end service()
}//end class

/**
 * A flow service that records what it was handed.
 */
class FakeFlowService {

	/**
	 * The document of the most recent save.
	 *
	 * @var array<string, mixed>
	 */
	public array $lastDocument = [];

	/**
	 * The uuid argument of every save, in order.
	 *
	 * @var array<int, string>
	 */
	public array $uuidsSeen = [];

	/**
	 * When set, every save throws with this message.
	 *
	 * @var string
	 */
	public string $refuseWith = '';

	/**
	 * Save a flow.
	 *
	 * @param array<string, mixed> $data The flow document.
	 * @param string|null $uuid The flow to update, or null to create one.
	 *
	 * @return object The saved flow.
	 */
	public function save(array $data, ?string $uuid = null): object {
		if ($this->refuseWith !== '') {
			throw new RuntimeException($this->refuseWith);
		}

		$this->lastDocument = $data;
		$this->uuidsSeen[] = (string)$uuid;

		return new class {
			/**
			 * The flow's uuid.
			 *
			 * @return string The uuid.
			 */
			public function getUuid(): string {
				return 'flow-1';
			}
		};
	}//end save()
}//end class

/**
 * A version service that counts publications.
 */
class FakeFlowVersionService {

	/**
	 * How many times publish() was called.
	 *
	 * @var int
	 */
	public int $published = 0;

	/**
	 * Publish version one.
	 *
	 * @param object $flow The flow.
	 * @param string|null $publishedBy The publisher.
	 *
	 * @return void
	 */
	public function publish(object $flow, ?string $publishedBy = null): void {
		$this->published++;
	}//end publish()
}//end class
