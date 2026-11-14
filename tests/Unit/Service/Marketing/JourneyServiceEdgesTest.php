<?php

/**
 * JourneyService: saving, status changes and the flow engine's odd answers.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\Pipelinq\Service\Marketing\JourneyFlowCompiler;
use OCA\Pipelinq\Service\Marketing\JourneyService;
use OCA\Pipelinq\Service\Marketing\ListObjectStore;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * What a save stamps, when a write failure stops the compile, and how the
 * service reads a flow engine that answers with an array, nothing or a
 * failing publish.
 *
 * @covers \OCA\Pipelinq\Service\Marketing\JourneyService
 */
class JourneyServiceEdgesTest extends TestCase {

	private const NOW = 1788609600;

	/**
	 * Journeys by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $journeys = [];

	/**
	 * Saves seen, as [schema, payload, id].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>, 2: string|null}>
	 */
	private array $saved = [];

	/**
	 * Whether a save of a journey fails.
	 *
	 * @var boolean
	 */
	private bool $saveFails = false;

	/**
	 * What the container hands back per id: a value, or a Throwable to throw.
	 *
	 * @var array<string, mixed>
	 */
	private array $services = [];

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The service under test over an array-backed store.
	 *
	 * @return JourneyService
	 */
	private function service(): JourneyService {
		$store = $this->createMock(ListObjectStore::class);
		$store->method('schemaSlug')->willReturnCallback(
			static fn (string $key, string $default): string => ($key === 'journey_schema' ? 'journeys' : $default)
		);
		$store->method('find')->willReturnCallback(
			function (string $schema, string $id): ?array {
				$this->assertSame('journeys', $schema);
				return ($this->journeys[$id] ?? null);
			}
		);
		$store->method('findAll')->willReturnCallback(
			fn (string $schema): array => ($schema === 'journeys' ? array_values($this->journeys) : [])
		);
		$store->method('save')->willReturnCallback(
			function (string $schema, array $payload, ?string $id = null): ?array {
				$this->saved[] = [$schema, $payload, $id];
				if ($this->saveFails === true) {
					return null;
				}

				$id = ($id ?? 'new-' . count($this->saved));
				$payload['uuid'] = $id;
				$this->journeys[$id] = $payload;
				return $payload;
			}
		);
		$store->method('idOf')->willReturnCallback(static fn (?array $row): string => (string)(($row ?? [])['uuid'] ?? ''));

		$compiler = $this->createMock(JourneyFlowCompiler::class);
		$compiler->method('documentFor')->willReturnCallback(
			static fn (array $journey, string $journeyId, string $runAs = ''): array => ['journey' => $journeyId, 'runAs' => $runAs]
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): mixed {
				$service = ($this->services[$id] ?? new RuntimeException('not registered: ' . $id));
				if ($service instanceof \Throwable) {
					throw $service;
				}

				return $service;
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		return new JourneyService($store, $compiler, $container, $time, $this->logger);
	}//end service()

	/**
	 * A flow engine whose save answers with the given value.
	 *
	 * @param mixed $answer What save() returns.
	 *
	 * @return object
	 */
	private static function engine(mixed $answer): object {
		return new class ($answer) {
			/** @var array<int, array<int, mixed>> */
			public array $saves = [];

			public function __construct(private mixed $answer) {
			}//end __construct()

			public function save(array $document, ?string $target): mixed {
				$this->saves[] = [$document, $target];
				return $this->answer;
			}//end save()
		};
	}//end engine()

	/**
	 * Listing reads the configured journey schema.
	 *
	 * @return void
	 */
	public function testListingReadsTheJourneySchema(): void {
		$this->journeys = ['j-1' => ['uuid' => 'j-1'], 'j-2' => ['uuid' => 'j-2']];

		$this->assertSame([['uuid' => 'j-1'], ['uuid' => 'j-2']], $this->service()->listJourneys());
	}//end testListingReadsTheJourneySchema()

	/**
	 * A new active journey is stamped created, by whom and activated; then
	 * compiled as its creator. An array answer from the engine gives the uuid.
	 *
	 * @return void
	 */
	public function testANewJourneyIsStampedAndCompiled(): void {
		$engine = self::engine(['uuid' => 'flow-1']);
		$this->services[JourneyService::FLOW_SERVICE] = $engine;
		$now = date('c', self::NOW);

		$result = $this->service()->save(payload: ['name' => 'Win back', 'status' => 'active'], createdByUid: 'ann');

		$this->assertSame(
			['name' => 'Win back', 'status' => 'active', 'updatedAt' => $now, 'createdAt' => $now, 'createdBy' => 'ann', 'activatedAt' => $now],
			$this->saved[0][1]
		);
		$this->assertNull($this->saved[0][2]);
		$this->assertSame([[['journey' => 'new-1', 'runAs' => 'ann'], null]], $engine->saves);
		$this->assertSame('flow-1', $result['flowUuid']);
		$this->assertSame('compiled', $result['flowStatus']);
		$this->assertSame($now, $result['compiledAt']);
	}//end testANewJourneyIsStampedAndCompiled()

	/**
	 * Updating an already-active journey keeps its creation and activation
	 * stamps untouched; an engine answer without a uuid records none and
	 * is not published.
	 *
	 * @return void
	 */
	public function testAnUpdateOfAnActiveJourneyIsNotReactivated(): void {
		$this->journeys['j-1'] = ['uuid' => 'j-1', 'status' => 'active', 'flowUuid' => ' flow-old ', 'compiledAt' => 'earlier'];
		$engine = self::engine('not-a-flow');
		$this->services[JourneyService::FLOW_SERVICE] = $engine;
		$versions = new class {
			/** @var int */
			public int $published = 0;

			public function publish(mixed $flow, mixed $publishedBy): void {
				$this->published++;
			}//end publish()
		};
		$this->services[JourneyService::VERSION_SERVICE] = $versions;

		$result = $this->service()->save(payload: ['status' => 'active', 'flowUuid' => ' flow-old '], createdByUid: 'bob', journeyId: 'j-1');

		$this->assertSame(['status' => 'active', 'flowUuid' => ' flow-old ', 'updatedAt' => date('c', self::NOW)], $this->saved[0][1]);
		$this->assertSame('j-1', $this->saved[0][2]);
		$this->assertSame('flow-old', $engine->saves[0][1]);
		$this->assertSame('', $result['flowUuid']);
		$this->assertSame(0, $versions->published);
	}//end testAnUpdateOfAnActiveJourneyIsNotReactivated()

	/**
	 * A journey that cannot be written is not compiled.
	 *
	 * @return void
	 */
	public function testAFailedWriteStopsTheCompile(): void {
		$this->journeys['j-1'] = ['uuid' => 'j-1', 'status' => 'paused'];
		$this->saveFails = true;
		$engine = self::engine(['uuid' => 'flow-1']);
		$this->services[JourneyService::FLOW_SERVICE] = $engine;

		$service = $this->service();
		$this->assertNull($service->save(payload: ['name' => 'x'], createdByUid: 'ann'));
		$this->assertNull($service->setStatus(journeyId: 'j-1', status: 'active'));
		$this->assertSame([], $engine->saves);
		$this->assertSame(date('c', self::NOW), $this->saved[1][1]['activatedAt']);
	}//end testAFailedWriteStopsTheCompile()

	/**
	 * Unknown journeys neither change status nor compile.
	 *
	 * @return void
	 */
	public function testAnUnknownJourneyIsNull(): void {
		$service = $this->service();

		$this->assertNull($service->setStatus(journeyId: 'gone', status: 'active'));
		$this->assertNull($service->compile(journeyId: 'gone'));
		$this->assertSame([], $this->saved);
	}//end testAnUnknownJourneyIsNull()

	/**
	 * A publish that fails is logged; the journey is still recorded compiled.
	 *
	 * @return void
	 */
	public function testAFailedPublishIsLoggedButTheJourneyIsCompiled(): void {
		$this->journeys['j-1'] = ['uuid' => 'j-1'];
		$this->services[JourneyService::FLOW_SERVICE] = self::engine(
			new class {
				/**
				 * The flow uuid.
				 *
				 * @return string
				 */
				public function getUuid(): string {
					return 'flow-9';
				}//end getUuid()
			}
		);
		$this->services[JourneyService::VERSION_SERVICE] = new class {
			public function publish(mixed $flow, mixed $publishedBy): void {
				throw new RuntimeException('version clash');
			}//end publish()
		};
		$this->logger->expects($this->once())->method('info')->with(
			'JourneyService.publish: the flow was saved but not published',
			['journeyId' => 'j-1', 'exception' => 'version clash']
		);

		$result = $this->service()->compile(journeyId: 'j-1');

		$this->assertSame(['compiled', '', 'flow-9'], [$result['flowStatus'], $result['flowError'], $result['flowUuid']]);
	}//end testAFailedPublishIsLoggedButTheJourneyIsCompiled()

	/**
	 * A container entry that is not an object counts as no engine.
	 *
	 * @return void
	 */
	public function testANonObjectServiceIsNoEngine(): void {
		$this->journeys['j-1'] = ['uuid' => 'j-1'];
		$this->services[JourneyService::FLOW_SERVICE] = 'flow-service';

		$result = $this->service()->compile(journeyId: 'j-1');

		$this->assertSame('engine_missing', $result['flowStatus']);
	}//end testANonObjectServiceIsNoEngine()

	/**
	 * When the record write fails the compiled journey is still returned.
	 *
	 * @return void
	 */
	public function testAFailedRecordReturnsTheJourney(): void {
		$this->journeys['j-1'] = ['uuid' => 'j-1', 'name' => 'Win back'];
		$this->saveFails = true;

		$result = $this->service()->compile(journeyId: 'j-1');

		$this->assertSame(
			['uuid' => 'j-1', 'name' => 'Win back', 'flowStatus' => 'engine_missing', 'flowError' => '', 'flowUuid' => ''],
			$result
		);
	}//end testAFailedRecordReturnsTheJourney()
}//end class
