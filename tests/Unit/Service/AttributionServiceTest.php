<?php

/**
 * Unit tests for AttributionService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-segmentation-and-blast-04-blast-attribution-services/tasks.md#task-2.4
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\AttributionService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for AttributionService — click recording, deal linking,
 * idempotency, and attributed-revenue roll-up.
 *
 * @spec openspec/changes/marketing-segmentation-and-blast-04-blast-attribution-services/tasks.md#task-2.4
 */
class AttributionServiceTest extends TestCase {
	private ContainerInterface $container;
	private IAppConfig $appConfig;
	private LoggerInterface $logger;
	private object $objectService;

	/**
	 * Service under test, instantiated in setUp().
	 *
	 * @var AttributionService
	 */
	private AttributionService $service;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->objectService = new class {
			/** @var array<int, array<string, mixed>> */
			public array $saved = [];

			/** @var array<string, array<string, mixed>> */
			public array $store = [];

			/** @var array<int, array<string, mixed>> */
			public array $attributionLinks = [];

			/**
			 * Mock find().
			 *
			 * @param string $id Identifier.
			 * @param mixed $register Register slug.
			 * @param mixed $schema Schema slug.
			 *
			 * @return array<string, mixed>|null Payload or null.
			 */
			public function find(string $id, $register = null, $schema = null): ?array {
				return ($this->store[$id] ?? null);
			}

			/**
			 * Mock findAll() — returns attribution links matching every
			 * filter.
			 *
			 * Mirrors OR's real ObjectService::findAll(array $config): the
			 * register/schema context travels INSIDE $config['filters'] and OR
			 * treats both as reserved params, never as object-field filters.
			 *
			 * @param array<string, mixed> $config Config with a `filters` map.
			 *
			 * @return array<int, array<string, mixed>> Rows.
			 */
			public function findAll(array $config = []): array {
				$filters = $config['filters'] ?? [];
				unset($filters['register'], $filters['schema']);

				$out = [];
				foreach ($this->attributionLinks as $row) {
					foreach ($filters as $k => $v) {
						if (($row[$k] ?? null) !== $v) {
							continue 2;
						}
					}
					$out[] = $row;
				}
				return $out;
			}

			/**
			 * Mock saveObject() — records the saved payload + indexes it.
			 *
			 * @param array $object Payload.
			 * @param mixed $register Register.
			 * @param mixed $schema Schema.
			 * @param string|null $uuid Existing id.
			 *
			 * @return array<string, mixed> The saved payload.
			 */
			public function saveObject(array $object, $register = null, $schema = null, ?string $uuid = null): array {
				if ($uuid === null || $uuid === '') {
					$uuid = ('saved-' . count($this->saved));
				}
				$object['uuid'] = $uuid;
				$this->saved[] = $object;
				$this->store[$uuid] = $object;
				if (isset($object['attributedValue']) === true) {
					$this->attributionLinks[] = $object;
				}
				return $object;
			}
		};

		$this->container->method('get')->willReturnCallback(
			function (string $id) {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $this->objectService;
				}
				throw new \RuntimeException('not registered: ' . $id);
			}
		);

		$this->appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default) {
				return match ($key) {
					'register' => 'pipelinq',
					'attributionLink_schema' => 'attributionLink',
					'blastDelivery_schema' => 'blastDelivery',
					'lead_schema' => 'lead',
					default => $default,
				};
			}
		);

		$this->service = new AttributionService(
			$this->container,
			$this->appConfig,
			$this->logger,
		);
	}//end setUp()

	/**
	 * recordClick sets firstClickAt and adds the URL to clickedUrls.
	 *
	 * @return void
	 */
	public function testRecordClickSetsFirstClickAtAndAppendsUrl(): void {
		$delivery = [
			'uuid' => 'd1',
			'blastId' => 'blast-1',
			'contactId' => 'c1',
			'status' => 'delivered',
		];
		$this->objectService->store['d1'] = $delivery;
		$this->service->recordClick('d1', [
			'timestamp' => '2026-12-01T12:00:00Z',
			'url' => 'https://pipelinq.nl/q4?utm_campaign=gemeente',
		]);

		$saved = end($this->objectService->saved);
		$this->assertSame('2026-12-01T12:00:00Z', $saved['firstClickAt']);
		$this->assertContains('https://pipelinq.nl/q4?utm_campaign=gemeente', $saved['clickedUrls']);
		$this->assertSame('clicked', $saved['status']);
	}//end testRecordClickSetsFirstClickAtAndAppendsUrl()

	/**
	 * recordClick does NOT overwrite an existing firstClickAt — the
	 * earliest timestamp is the attribution-window anchor.
	 *
	 * @return void
	 */
	public function testRecordClickPreservesEarlierFirstClick(): void {
		$delivery = [
			'uuid' => 'd1',
			'blastId' => 'blast-1',
			'contactId' => 'c1',
			'status' => 'clicked',
			'firstClickAt' => '2026-11-01T00:00:00Z',
			'clickedUrls' => ['https://pipelinq.nl/q4'],
		];
		$this->objectService->store['d1'] = $delivery;

		$this->service->recordClick('d1', [
			'timestamp' => '2026-12-01T12:00:00Z',
			'url' => 'https://pipelinq.nl/q4/follow',
		]);

		$saved = end($this->objectService->saved);
		$this->assertSame('2026-11-01T00:00:00Z', $saved['firstClickAt']);
		$this->assertCount(2, $saved['clickedUrls']);
	}//end testRecordClickPreservesEarlierFirstClick()

	/**
	 * recordClick does NOT downgrade a bounced/unsubscribed/complained
	 * status — those terminal states stay.
	 *
	 * @return void
	 */
	public function testRecordClickKeepsTerminalStatuses(): void {
		$delivery = [
			'uuid' => 'd1',
			'blastId' => 'blast-1',
			'contactId' => 'c1',
			'status' => 'bounced',
		];
		$this->objectService->store['d1'] = $delivery;
		$this->service->recordClick('d1', ['url' => 'https://pipelinq.nl/x']);

		$saved = end($this->objectService->saved);
		$this->assertSame('bounced', $saved['status']);
	}//end testRecordClickKeepsTerminalStatuses()

	/**
	 * linkBlastToDeal writes an AttributionLink joining the BlastDelivery's
	 * blastId/contactId, the dealId, the firstClickAt anchor and the
	 * deal's value as attributedValue.
	 *
	 * @return void
	 */
	public function testLinkBlastToDealCreatesAttributionLink(): void {
		$this->objectService->store['d1'] = [
			'uuid' => 'd1',
			'blastId' => 'blast-1',
			'contactId' => 'c1',
			'firstClickAt' => '2026-12-01T12:00:00Z',
		];
		$this->objectService->store['deal-1'] = [
			'uuid' => 'deal-1',
			'closedWonAt' => '2026-12-10T09:00:00Z',
			'value' => 28500.50,
		];

		$this->service->linkBlastToDeal('d1', 'deal-1');

		$created = end($this->objectService->saved);
		$this->assertSame('blast-1', $created['blastId']);
		$this->assertSame('c1', $created['contactId']);
		$this->assertSame('deal-1', $created['dealId']);
		$this->assertSame('2026-12-01T12:00:00Z', $created['firstClickAt']);
		$this->assertSame('2026-12-10T09:00:00Z', $created['closedWonAt']);
		$this->assertEqualsWithDelta(28500.50, $created['attributedValue'], 0.001);
		$this->assertSame('EUR', $created['currency']);
	}//end testLinkBlastToDealCreatesAttributionLink()

	/**
	 * linkBlastToDeal is idempotent: re-running for the same triple
	 * does not create a second AttributionLink.
	 *
	 * @return void
	 */
	public function testLinkBlastToDealIsIdempotent(): void {
		$this->objectService->store['d1'] = [
			'uuid' => 'd1',
			'blastId' => 'blast-1',
			'contactId' => 'c1',
			'firstClickAt' => '2026-12-01T12:00:00Z',
		];
		$this->objectService->store['deal-1'] = [
			'uuid' => 'deal-1',
			'closedWonAt' => '2026-12-10T09:00:00Z',
			'value' => 14750.00,
		];

		$this->service->linkBlastToDeal('d1', 'deal-1');
		$this->service->linkBlastToDeal('d1', 'deal-1');

		$links = array_filter(
			$this->objectService->saved,
			fn (array $row): bool => ($row['blastId'] ?? null) === 'blast-1'
				&& ($row['dealId'] ?? null) === 'deal-1',
		);
		$this->assertCount(1, $links);
	}//end testLinkBlastToDealIsIdempotent()

	/**
	 * getBlastAttributedValue sums attributedValue across every
	 * AttributionLink row for one blast.
	 *
	 * @return void
	 */
	public function testGetBlastAttributedValueSumsRows(): void {
		$this->objectService->attributionLinks = [
			['blastId' => 'blast-1', 'attributedValue' => 12000.00],
			['blastId' => 'blast-1', 'attributedValue' => 4500.50],
			['blastId' => 'blast-1', 'attributedValue' => 1000.25],
			['blastId' => 'blast-2', 'attributedValue' => 9999.99],
		];

		$sum = $this->service->getBlastAttributedValue('blast-1');
		$this->assertEqualsWithDelta(17500.75, $sum, 0.001);
	}//end testGetBlastAttributedValueSumsRows()

	/**
	 * getBlastAttributedValue returns zero when no rows match.
	 *
	 * @return void
	 */
	public function testGetBlastAttributedValueReturnsZeroWhenEmpty(): void {
		$sum = $this->service->getBlastAttributedValue('nonexistent');
		$this->assertSame(0.0, $sum);
	}//end testGetBlastAttributedValueReturnsZeroWhenEmpty()
}//end class
