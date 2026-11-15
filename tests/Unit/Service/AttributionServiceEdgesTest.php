<?php

/**
 * Edge cases of AttributionService: click event shapes, deal fields, currencies and failing OpenRegister calls.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\AttributionService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;

/**
 * A click and a won deal are recorded once, with the values a report can add up.
 *
 * @covers \OCA\Pipelinq\Service\AttributionService
 * @uses \OCA\Pipelinq\Util\PayloadToArray
 * @uses \OCA\Pipelinq\Util\EntityPayload
 * @uses \OCA\Pipelinq\Util\SerialisedPayload
 */
class AttributionServiceEdgesTest extends TestCase {

	/**
	 * App config values.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * The OpenRegister double.
	 *
	 * @var object
	 */
	private object $objects;

	/**
	 * Whether the container fails to give the ObjectService.
	 *
	 * @var bool
	 */
	private bool $noObjectService = false;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new class {
			/** @var array<string, array<string, mixed>> */
			public array $store = [];

			/** @var array<int, array<string, mixed>> */
			public array $links = [];

			/** @var array<int, array<string, mixed>> */
			public array $findAllCalls = [];

			/** @var array<int, array<string, mixed>> */
			public array $saves = [];

			/** @var bool */
			public bool $failFind = false;

			/** @var bool */
			public bool $failFindAll = false;

			/** @var bool */
			public bool $failSave = false;

			/**
			 * Find one object by schema and id.
			 *
			 * @param string $id       The id.
			 * @param mixed  $register The register.
			 * @param mixed  $schema   The schema.
			 *
			 * @return mixed
			 */
			public function find(string $id, ?ObjectFindOptions $options = null, bool $_rbac = true, bool $_multitenancy = true): mixed {
				$register = $options?->register;
				$schema = $options?->schema;
				if ($this->failFind === true) {
					throw new RuntimeException('gone');
				}

				return ($this->store[$schema . '/' . $id] ?? null);
			}

			/**
			 * The attribution links; filters are recorded and applied.
			 *
			 * @param array<string, mixed> $config The config.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config = []): array {
				$this->findAllCalls[] = $config;
				if ($this->failFindAll === true) {
					throw new RuntimeException('down');
				}

				$filters = $config['filters'];
				unset($filters['register'], $filters['schema']);
				return array_values(array_filter(
					$this->links,
					static fn (array $row): bool => array_intersect_assoc($filters, $row) === $filters
				));
			}

			/**
			 * Save an object.
			 *
			 * @param array<string, mixed> $object   The object.
			 * @param mixed                $register The register.
			 * @param mixed                $schema   The schema.
			 * @param string|null          $uuid     The uuid.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true, bool $_multitenancy = true): array {
				$register = $options->target->register;
				$schema = $options->target->schema;
				$uuid = $options->uuid;
				$this->saves[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];
				if ($this->failSave === true) {
					throw new RuntimeException('refused');
				}

				return $object;
			}
		};
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * The service over the doubles.
	 *
	 * @return AttributionService
	 */
	private function service(): AttributionService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): object {
				if ($this->noObjectService === true) {
					throw new RuntimeException('no OpenRegister');
				}

				return $this->objects;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);

		return new AttributionService($container, $appConfig, $this->logger);

	}//end service()

	/**
	 * An integer timestamp and an `href` key are read from the click event; a repeat url is kept once.
	 *
	 * @return void
	 */
	public function testAClickReadsTheEventShapes(): void {
		$this->config = ['register' => 'crm', 'blastDelivery_schema' => 'delivery'];
		$this->objects->store['delivery/d-1'] = ['@self' => ['uuid' => 'u-1'], 'status' => 'opened', 'clickedUrls' => ['https://a.test']];

		$this->service()->recordClick(blastDeliveryId: 'd-1', clickEvent: ['timestamp' => 0, 'href' => 'https://a.test']);

		$save = $this->objects->saves[0];
		$this->assertSame(['crm', 'delivery', 'u-1'], [$save['register'], $save['schema'], $save['uuid']]);
		$this->assertSame('1970-01-01T00:00:00Z', $save['object']['firstClickAt']);
		$this->assertSame(['https://a.test'], $save['object']['clickedUrls']);
		$this->assertSame('clicked', $save['object']['status']);

	}//end testAClickReadsTheEventShapes()

	/**
	 * Other timestamp and url keys are read in turn; a stored non-list of urls starts over.
	 *
	 * @return void
	 */
	public function testAClickFallsBackThroughTheKeys(): void {
		$this->objects->store['blastDelivery/d-1'] = ['uuid' => 'd-1', 'status' => 'bounced', 'clickedUrls' => 'broken', 'firstClickAt' => '2026-01-01T00:00:00Z'];

		$this->service()->recordClick(blastDeliveryId: 'd-1', clickEvent: ['timestamp' => '', 'occurredAt' => '2026-05-05T05:05:05Z', 'url' => '', 'link' => 'https://b.test']);

		$object = $this->objects->saves[0]['object'];
		$this->assertSame('2026-01-01T00:00:00Z', $object['firstClickAt']);
		$this->assertSame(['https://b.test'], $object['clickedUrls']);
		$this->assertSame('bounced', $object['status']);

	}//end testAClickFallsBackThroughTheKeys()

	/**
	 * A click without a timestamp or url stamps now and adds no url.
	 *
	 * @return void
	 */
	public function testAClickWithoutDetailsStampsNow(): void {
		$this->objects->store['blastDelivery/d-1'] = ['uuid' => 'd-1', 'status' => 'sent'];

		$before = gmdate('Y-m-d\TH:i:s\Z');
		$this->service()->recordClick(blastDeliveryId: 'd-1', clickEvent: ['time' => ['not', 'a', 'time']]);

		$object = $this->objects->saves[0]['object'];
		$this->assertGreaterThanOrEqual($before, $object['firstClickAt']);
		$this->assertSame([], $object['clickedUrls']);

	}//end testAClickWithoutDetailsStampsNow()

	/**
	 * A click on a delivery that cannot be read is dropped, logged.
	 *
	 * @return void
	 */
	public function testAClickOnAnUnreadableDeliveryIsDropped(): void {
		$this->objects->failFind = true;
		$this->logger->expects($this->once())->method('info')->with('AttributionService.loadOne: not found', ['id' => 'd-1', 'schema' => 'blastDelivery', 'exception' => 'gone']);

		$this->service()->recordClick(blastDeliveryId: 'd-1', clickEvent: ['url' => 'https://a.test']);

		$this->assertSame([], $this->objects->saves);

	}//end testAClickOnAnUnreadableDeliveryIsDropped()

	/**
	 * A failing save of a click is logged, not thrown.
	 *
	 * @return void
	 */
	public function testAFailingClickSaveIsLogged(): void {
		$this->objects->store['blastDelivery/d-1'] = ['uuid' => 'd-1'];
		$this->objects->failSave = true;
		$this->logger->expects($this->once())->method('warning')->with('AttributionService.saveObject: save failed', ['schema' => 'blastDelivery', 'exception' => 'refused']);

		$this->service()->recordClick(blastDeliveryId: 'd-1', clickEvent: []);

		$this->assertCount(1, $this->objects->saves);

	}//end testAFailingClickSaveIsLogged()

	/**
	 * A missing delivery, or one without blast or contact, links nothing.
	 *
	 * @return void
	 */
	public function testLinkingNeedsADeliveryWithBlastAndContact(): void {
		$this->logger->expects($this->once())->method('info')->with('AttributionService.linkBlastToDeal: delivery not found', ['blastDeliveryId' => 'none']);
		$this->service()->linkBlastToDeal(blastDeliveryId: 'none', dealId: 'deal-1');

		$this->objects->store['blastDelivery/d-1'] = ['uuid' => 'd-1', 'blastId' => 'b-1'];
		$this->service()->linkBlastToDeal(blastDeliveryId: 'd-1', dealId: 'deal-1');

		$this->assertSame([], $this->objects->saves);
		$this->assertSame([], $this->objects->findAllCalls);

	}//end testLinkingNeedsADeliveryWithBlastAndContact()

	/**
	 * An existing link for the same blast, contact and deal is not duplicated.
	 *
	 * @return void
	 */
	public function testAnExistingLinkIsNotDuplicated(): void {
		$this->objects->store['blastDelivery/d-1'] = ['uuid' => 'd-1', 'blastId' => 'b-1', 'contactId' => 'c-1'];
		$this->objects->links = [['blastId' => 'b-1', 'contactId' => 'c-1', 'dealId' => 'deal-1']];

		$this->service()->linkBlastToDeal(blastDeliveryId: 'd-1', dealId: 'deal-1');

		$this->assertSame([], $this->objects->saves);
		$this->assertSame(
			[['filters' => ['blastId' => 'b-1', 'contactId' => 'c-1', 'dealId' => 'deal-1', 'register' => 'pipelinq', 'schema' => 'attributionLink']]],
			$this->objects->findAllCalls
		);

	}//end testAnExistingLinkIsNotDuplicated()

	/**
	 * A link reads the deal's close date, value and currency from their fallback keys.
	 *
	 * @return void
	 */
	public function testALinkReadsTheDealFallbackFields(): void {
		$this->config = ['lead_schema' => 'deal', 'attributionLink_schema' => 'link'];
		$this->objects->store['blastDelivery/d-1'] = ['uuid' => 'd-1', 'blastId' => 'b-1', 'contactId' => 'c-1', 'firstClickAt' => '2026-02-02T00:00:00Z'];
		$this->objects->store['deal/deal-1'] = ['closedWonAt' => '', 'wonAt' => '2026-03-03T00:00:00Z', 'value' => 'n/a', 'amount' => '125.5', 'currency' => ' usd '];

		$this->service()->linkBlastToDeal(blastDeliveryId: 'd-1', dealId: 'deal-1');

		$save = $this->objects->saves[0];
		$this->assertSame('link', $save['schema']);
		$this->assertNull($save['uuid']);
		$object = $save['object'];
		$this->assertSame(['b-1', 'c-1', 'deal-1'], [$object['blastId'], $object['contactId'], $object['dealId']]);
		$this->assertSame('2026-02-02T00:00:00Z', $object['firstClickAt']);
		$this->assertSame('2026-03-03T00:00:00Z', $object['closedWonAt']);
		$this->assertSame(125.5, $object['attributedValue']);
		$this->assertSame('USD', $object['currency']);

	}//end testALinkReadsTheDealFallbackFields()

	/**
	 * A deal that cannot be read links with no value, now, in the reporting currency.
	 *
	 * @return void
	 */
	public function testALinkToAnUnknownDealUsesTheDefaults(): void {
		$this->config = ['currency' => 'gbp'];
		$this->objects->store['blastDelivery/d-1'] = ['uuid' => 'd-1', 'blastId' => 'b-1', 'contactId' => 'c-1'];

		$before = gmdate('Y-m-d\TH:i:s\Z');
		$this->service()->linkBlastToDeal(blastDeliveryId: 'd-1', dealId: 'unknown');

		$object = $this->objects->saves[0]['object'];
		$this->assertSame(0.0, $object['attributedValue']);
		$this->assertSame('GBP', $object['currency']);
		$this->assertSame('', $object['firstClickAt']);
		$this->assertGreaterThanOrEqual($before, $object['closedWonAt']);

	}//end testALinkToAnUnknownDealUsesTheDefaults()

	/**
	 * A deal without a usable close date or value, and with a bad currency, falls back.
	 *
	 * @return void
	 */
	public function testADealWithoutUsableFieldsFallsBack(): void {
		$this->config = ['currency' => 'euro'];
		$this->objects->store['blastDelivery/d-1'] = ['uuid' => 'd-1', 'blastId' => 'b-1', 'contactId' => 'c-1'];
		$this->objects->store['lead/deal-1'] = ['closedAt' => 5, 'value' => null, 'currency' => 'EU'];

		$before = gmdate('Y-m-d\TH:i:s\Z');
		$this->service()->linkBlastToDeal(blastDeliveryId: 'd-1', dealId: 'deal-1');

		$object = $this->objects->saves[0]['object'];
		$this->assertSame(0.0, $object['attributedValue']);
		$this->assertSame('EUR', $object['currency']);
		$this->assertGreaterThanOrEqual($before, $object['closedWonAt']);

	}//end testADealWithoutUsableFieldsFallsBack()

	/**
	 * The summary adds numeric values and counts distinct deals, in the reporting currency.
	 *
	 * @return void
	 */
	public function testTheSummaryAddsUpTheLinks(): void {
		$this->config = ['currency' => 'sek'];
		$this->objects->links = [
			['blastId' => 'b-1', 'dealId' => 'x', 'attributedValue' => 10],
			['blastId' => 'b-1', 'dealId' => 'x', 'attributedValue' => '2.5'],
			['blastId' => 'b-1', 'dealId' => '', 'attributedValue' => 'n/a'],
			['blastId' => 'b-1', 'dealId' => 'y'],
			['blastId' => 'b-2', 'dealId' => 'z', 'attributedValue' => 99],
		];

		$this->assertSame(['blastId' => 'b-1', 'dealCount' => 2, 'attributedValue' => 12.5, 'currency' => 'SEK'], $this->service()->getBlastAttributionSummary(blastId: 'b-1'));
		$this->assertSame(12.5, $this->service()->getBlastAttributedValue(blastId: 'b-1'));

	}//end testTheSummaryAddsUpTheLinks()

	/**
	 * A failing read sums to nothing, logged; without OpenRegister nothing is read.
	 *
	 * @return void
	 */
	public function testAFailingLinkReadSumsToNothing(): void {
		$this->objects->links = [['blastId' => 'b-1', 'attributedValue' => 10]];
		$this->objects->failFindAll = true;
		$this->logger->expects($this->once())->method('warning')->with('AttributionService.loadAttributionLinks: findAll failed', ['filters' => ['blastId' => 'b-1'], 'exception' => 'down']);

		$this->assertSame(0.0, $this->service()->getBlastAttributedValue(blastId: 'b-1'));

	}//end testAFailingLinkReadSumsToNothing()

	/**
	 * Without OpenRegister nothing is read or written.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingHappens(): void {
		$this->noObjectService = true;
		$this->logger->expects($this->exactly(2))->method('warning')->with('AttributionService.getObjectService: OpenRegister unavailable', ['exception' => 'no OpenRegister']);

		$this->assertSame(0, $this->service()->getBlastAttributionSummary(blastId: 'b-1')['dealCount']);
		$this->service()->recordClick(blastDeliveryId: 'd-1', clickEvent: []);
		$this->assertSame([], $this->objects->saves);

	}//end testWithoutOpenRegisterNothingHappens()
}//end class
