<?php

/**
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit
 *
 * @author    Conduction <info@conduction.nl>
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

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Service\AttributionService;
use OCA\Pipelinq\Service\BlastService;
use OCA\Pipelinq\Service\ComplianceService;
use OCA\Pipelinq\Service\WebhookProcessorService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Error paths and branches of the provider webhook processor: missing or
 * failing handlers, delivery lookup fallbacks, OpenRegister outages, open /
 * complaint handling, soft-bounce threshold parsing and SendGrid
 * normalisation.
 *
 * @covers \OCA\Pipelinq\Service\WebhookProcessorService
 * @uses   \OCA\Pipelinq\Util\PayloadToArray
 * @uses   \OCA\Pipelinq\Util\EntityPayload
 * @uses   \OCA\Pipelinq\Util\SerialisedPayload
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) One test per branch.
 *
 * @spec openspec/changes/archive/2026-06-14-marketing-segmentation-and-blast-05-jobs-and-webhooks/tasks.md#webhooks
 */
class WebhookProcessorServiceCoverageTest extends TestCase {

	/**
	 * In-memory app config.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	private ContainerInterface&MockObject $container;
	private IAppConfig&MockObject $appConfig;
	private ComplianceService&MockObject $compliance;
	private AttributionService&MockObject $attribution;
	private BlastService&MockObject $blasts;
	private LoggerInterface&MockObject $logger;
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * Fresh doubles per test; OpenRegister is available by default.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->config = [];
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);
		$this->appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);
		$this->compliance = $this->createMock(ComplianceService::class);
		$this->attribution = $this->createMock(AttributionService::class);
		$this->blasts = $this->createMock(BlastService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->objects = $this->createMock(ObjectServiceInterface::class);
	}//end setUp()

	/**
	 * Build the service; the container serves the object service unless told otherwise.
	 *
	 * @param bool $withObjects Whether OpenRegister is wired.
	 *
	 * @return WebhookProcessorService
	 */
	private function service(bool $withObjects = true): WebhookProcessorService {
		if ($withObjects === true) {
			$this->container->method('get')
				->with('OCA\\OpenRegister\\Service\\ObjectService')
				->willReturn($this->objects);
		}

		return new WebhookProcessorService(
			container: $this->container,
			appConfig: $this->appConfig,
			complianceService: $this->compliance,
			attributionService: $this->attribution,
			blastService: $this->blasts,
			logger: $this->logger,
		);
	}//end service()

	/**
	 * Make find() serve the given delivery row for its id.
	 *
	 * @param array $row The stored delivery.
	 *
	 * @return void
	 */
	private function storedDelivery(array $row): void {
		$entity = new ObjectEntity();
		$entity->setObject($row);
		$this->objects->method('find')->willReturn($entity);
	}//end storedDelivery()

	/**
	 * Capture every saveObject payload.
	 *
	 * @return \ArrayObject<int, array> The captured payloads.
	 */
	private function captureSaves(): \ArrayObject {
		$saved = new \ArrayObject();
		$this->objects->method('saveObject')->willReturnCallback(
			static function (array $object) use ($saved): ObjectEntity {
				$saved[] = $object;
				$entity = new ObjectEntity();
				$entity->setObject($object);
				return $entity;
			}
		);
		return $saved;
	}//end captureSaves()

	/**
	 * An event without a type is rejected.
	 *
	 * @return void
	 */
	public function testProcessEventWithoutTypeIsRejected(): void {
		$this->logger->expects($this->once())->method('info')
			->with('WebhookProcessorService.processEvent: missing eventType', ['provider' => 'mailgun']);
		$this->container->expects($this->never())->method('get');

		$this->assertFalse($this->service(false)->processEvent(['blastDeliveryId' => 'd-1'], 'mailgun'));
	}//end testProcessEventWithoutTypeIsRejected()

	/**
	 * A handler that throws makes the event count as not processed.
	 *
	 * @return void
	 */
	public function testProcessEventHandlerFailureReturnsFalse(): void {
		$this->storedDelivery(['uuid' => 'd-1', 'contactId' => 'c-1', 'channel' => 'email']);
		$this->captureSaves();
		$this->compliance->method('recordConsentWithdrawal')
			->willThrowException(new RuntimeException('consent store down'));
		$this->logger->expects($this->once())->method('warning')
			->with(
				'WebhookProcessorService.processEvent: handler failed',
				['provider' => 'postmark', 'eventType' => 'unsubscribed', 'exception' => 'consent store down']
			);

		$this->assertFalse(
			$this->service()->processEvent(['event' => 'Unsubscribed', 'blastDeliveryId' => 'd-1'], 'postmark')
		);
	}//end testProcessEventHandlerFailureReturnsFalse()

	/**
	 * Every handler is a no-op when the delivery cannot be located.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function eventTypes(): array {
		return [
			'delivered' => ['delivered'],
			'bounce' => ['bounce'],
			'open' => ['open'],
			'click' => ['click'],
			'unsubscribe' => ['unsubscribe'],
			'complaint' => ['complaint'],
		];
	}//end eventTypes()

	/**
	 * Unknown delivery: nothing is saved and no side effect fires.
	 *
	 * @param string $type The event type.
	 *
	 * @dataProvider eventTypes
	 *
	 * @return void
	 */
	public function testUnknownDeliveryIsANoOp(string $type): void {
		$this->objects->method('find')->willReturn(null);
		$this->objects->method('findAll')->willReturn([]);
		$this->objects->expects($this->never())->method('saveObject');
		$this->compliance->expects($this->never())->method('recordConsentWithdrawal');
		$this->attribution->expects($this->never())->method('recordClick');

		$this->assertTrue(
			$this->service()->processEvent(
				['eventType' => $type, 'blastDeliveryId' => 'gone', 'providerId' => 'p-x', 'contactId' => 'c', 'blastId' => 'b'],
				'generic'
			)
		);
	}//end testUnknownDeliveryIsANoOp()

	/**
	 * An open marks a delivered row opened and stamps the event time,
	 * against the default register and schema slugs.
	 *
	 * @return void
	 */
	public function testOpenMarksDeliveredRowOpened(): void {
		$this->objects->expects($this->once())->method('find')
			->with('d-1', $this->anything(), $this->anything(), 'pipelinq', 'blastDelivery')
			->willReturnCallback(static function (): ObjectEntity {
				$entity = new ObjectEntity();
				$entity->setObject(['uuid' => 'd-1', 'status' => 'delivered']);
				return $entity;
			});
		$saved = $this->captureSaves();

		$this->assertTrue(
			$this->service()->processEvent(['eventType' => 'opened', 'blastDeliveryId' => 'd-1', 'timestamp' => 86400], 'x')
		);

		$this->assertCount(1, $saved);
		$this->assertSame('opened', $saved[0]['status']);
		$this->assertSame('1970-01-02T00:00:00Z', $saved[0]['openedAt']);
	}//end testOpenMarksDeliveredRowOpened()

	/**
	 * A repeat open keeps the first openedAt and a later status.
	 *
	 * @return void
	 */
	public function testOpenKeepsExistingOpenedAtAndLaterStatus(): void {
		$this->config['register'] = 'reg-x';
		$this->config['blastDelivery_schema'] = 'sch-x';
		$this->storedDelivery(['uuid' => 'd-1', 'status' => 'clicked', 'openedAt' => '2026-01-01T00:00:00Z']);
		$saved = $this->captureSaves();
		$this->objects->expects($this->once())->method('saveObject')
			->with($this->anything(), $this->anything(), 'reg-x', 'sch-x', 'd-1');

		$this->service()->processOpen(['blastDeliveryId' => 'd-1', 'timestamp' => '1700000000']);

		$this->assertSame('clicked', $saved[0]['status']);
		$this->assertSame('2026-01-01T00:00:00Z', $saved[0]['openedAt']);
	}//end testOpenKeepsExistingOpenedAtAndLaterStatus()

	/**
	 * A delivery found only by contact + blast is updated; a failing find by
	 * id is logged, and the delivered time comes from an ISO timestamp.
	 *
	 * @return void
	 */
	public function testDeliveredFallsBackToContactAndBlastLookup(): void {
		$this->objects->method('find')->willThrowException(new RuntimeException('404'));
		$this->objects->expects($this->once())->method('findAll')
			->with([
				'filters' => [
					'contactId' => 'c-1',
					'blastId' => 'b-1',
					'register' => 'pipelinq',
					'schema' => 'blastDelivery',
				],
			])
			->willReturn([['@self' => ['uuid' => 'd-7'], 'status' => 'sent', 'blastId' => 'b-1']]);
		$saved = $this->captureSaves();
		$this->blasts->expects($this->once())->method('updateBlastTotals')->with('b-1');
		$this->logger->expects($this->once())->method('info')
			->with('WebhookProcessorService.loadDeliveryById: not found', ['id' => 'd-0', 'exception' => '404']);

		$this->service()->processDelivered(
			['blastDeliveryId' => 'd-0', 'contactId' => 'c-1', 'blastId' => 'b-1', 'timestamp' => '2026-03-01T12:00:00+01:00']
		);

		$this->assertSame('delivered', $saved[0]['status']);
		$this->assertSame('2026-03-01T11:00:00Z', $saved[0]['deliveredAt']);
	}//end testDeliveredFallsBackToContactAndBlastLookup()

	/**
	 * A failing findAll is logged and treated as no match.
	 *
	 * @return void
	 */
	public function testFindAllFailureIsLoggedAndSkipped(): void {
		$this->objects->method('findAll')->willThrowException(new RuntimeException('index down'));
		$this->objects->expects($this->never())->method('saveObject');
		$this->logger->expects($this->once())->method('warning')
			->with(
				'WebhookProcessorService.findDeliveries: findAll failed',
				['filters' => ['providerId' => 'p-1'], 'exception' => 'index down']
			);

		$this->service()->processDelivered(['providerId' => 'p-1']);
	}//end testFindAllFailureIsLoggedAndSkipped()

	/**
	 * Without OpenRegister nothing is looked up or saved.
	 *
	 * @return void
	 */
	public function testMissingOpenRegisterSkipsLookups(): void {
		$this->container->expects($this->exactly(2))->method('get')
			->willThrowException(new RuntimeException('no OR'));
		$this->logger->expects($this->exactly(2))->method('warning')
			->with('WebhookProcessorService.getObjectService: OpenRegister unavailable', ['exception' => 'no OR']);

		$this->service(false)->processDelivered(['blastDeliveryId' => 'd-1', 'providerId' => 'p-1']);
	}//end testMissingOpenRegisterSkipsLookups()

	/**
	 * OpenRegister disappearing between lookup and save skips the save.
	 *
	 * @return void
	 */
	public function testOpenRegisterVanishingBeforeSaveSkipsSave(): void {
		$this->storedDelivery(['uuid' => 'd-1', 'status' => 'sent', 'blastId' => 'b-1']);
		$calls = 0;
		$this->container->method('get')->willReturnCallback(
			function () use (&$calls): ObjectServiceInterface {
				$calls++;
				if ($calls > 1) {
					throw new RuntimeException('gone');
				}

				return $this->objects;
			}
		);
		$this->objects->expects($this->never())->method('saveObject');
		$this->blasts->expects($this->never())->method('updateBlastTotals');

		$this->service(false)->processDelivered(['blastDeliveryId' => 'd-1']);
		$this->assertSame(2, $calls);
	}//end testOpenRegisterVanishingBeforeSaveSkipsSave()

	/**
	 * A failing save and a failing totals update are both logged, not fatal.
	 *
	 * @return void
	 */
	public function testSaveAndTotalsFailuresAreLogged(): void {
		$this->storedDelivery(['uuid' => 'd-1', 'status' => 'queued', 'blastId' => 'b-1', 'deliveredAt' => '2026-01-01T00:00:00Z']);
		$this->objects->expects($this->once())->method('saveObject')
			->with(
				$this->callback(static fn (array $p): bool => $p['status'] === 'delivered' && $p['deliveredAt'] === '2026-01-01T00:00:00Z'),
			)
			->willThrowException(new RuntimeException('locked'));
		$this->blasts->expects($this->once())->method('updateBlastTotals')->with('b-1')
			->willThrowException(new RuntimeException('totals broke'));
		$this->logger->expects($this->once())->method('warning')
			->with('WebhookProcessorService.saveDelivery: save failed', ['id' => 'd-1', 'exception' => 'locked']);
		$this->logger->expects($this->once())->method('info')
			->with(
				'WebhookProcessorService.saveDelivery: updateBlastTotals failed',
				['blastId' => 'b-1', 'exception' => 'totals broke']
			);

		$this->assertTrue($this->service()->processEvent(['eventType' => 'delivered', 'blastDeliveryId' => 'd-1'], 'x'));
	}//end testSaveAndTotalsFailuresAreLogged()

	/**
	 * A delivery without any identifier is not saved and gets no click.
	 *
	 * @return void
	 */
	public function testDeliveryWithoutIdIsNotSavedOrClicked(): void {
		$this->objects->method('findAll')->willReturn([['status' => 'sent', 'uuid' => '', 'blastId' => 'b-1']]);
		$this->objects->expects($this->never())->method('saveObject');
		$this->blasts->expects($this->never())->method('updateBlastTotals');
		$this->attribution->expects($this->never())->method('recordClick');

		$service = $this->service();
		$service->processDelivered(['providerId' => 'p-1']);
		$service->processClick(['providerId' => 'p-1', 'url' => 'https://x.nl/?utm_campaign=spring']);
	}//end testDeliveryWithoutIdIsNotSavedOrClicked()

	/**
	 * Clicks without a query string, or without a URL, carry no campaign.
	 *
	 * @return void
	 */
	public function testClickWithoutQueryHasNoCampaign(): void {
		$this->storedDelivery(['slug' => 'd-slug']);
		$urls = [];
		$this->attribution->expects($this->exactly(2))->method('recordClick')
			->willReturnCallback(static function (string $id, array $click) use (&$urls): void {
				if ($id !== 'd-slug' || $click['utmCampaign'] !== '' || $click['timestamp'] !== '1970-01-01T00:01:40Z') {
					throw new \LogicException('unexpected click ' . json_encode([$id, $click]));
				}

				$urls[] = $click['url'];
			});

		$service = $this->service();
		$service->processClick(['blastDeliveryId' => 'd-slug', 'url' => 'https://x.nl/page', 'timestamp' => 100]);
		$service->processClick(['blastDeliveryId' => 'd-slug', 'timestamp' => 100]);

		$this->assertSame(['https://x.nl/page', ''], $urls);
	}//end testClickWithoutQueryHasNoCampaign()

	/**
	 * A complaint marks the row complained and withdraws consent with no blast id.
	 *
	 * @return void
	 */
	public function testComplaintWithdrawsConsent(): void {
		$this->storedDelivery(['uuid' => 'd-1', 'contactId' => 'c-1', 'channel' => 'sms']);
		$saved = $this->captureSaves();
		$this->blasts->expects($this->never())->method('updateBlastTotals');
		$this->compliance->expects($this->once())->method('recordConsentWithdrawal')
			->with('c-1', 'sms', 'spam-complaint', null);

		$this->assertTrue(
			$this->service()->processEvent(['eventType' => 'complained', 'blastDeliveryId' => 'd-1', 'timestamp' => 0], 'x')
		);

		$this->assertSame('complained', $saved[0]['status']);
		$this->assertSame('1970-01-01T00:00:00Z', $saved[0]['complainedAt']);
	}//end testComplaintWithdrawsConsent()

	/**
	 * Complaint, unsubscribe and bounce without a contact only update the row.
	 *
	 * @return void
	 */
	public function testEventsWithoutContactOnlyUpdateRow(): void {
		$this->storedDelivery(['uuid' => 'd-1']);
		$saved = $this->captureSaves();
		$this->compliance->expects($this->never())->method('recordConsentWithdrawal');

		$service = $this->service();
		$service->processComplaint(['blastDeliveryId' => 'd-1']);
		$service->processUnsubscribe(['blastDeliveryId' => 'd-1']);
		$service->processBounce(['blastDeliveryId' => 'd-1', 'bounceReason' => 'mailbox full']);

		$this->assertSame(['complained', 'unsubscribed', 'bounced'], array_column($saved->getArrayCopy(), 'status'));
		$this->assertSame('hard', $saved[2]['bounceType']);
		$this->assertSame('mailbox full', $saved[2]['bounceReason']);
	}//end testEventsWithoutContactOnlyUpdateRow()

	/**
	 * An invalid threshold falls back to five soft bounces, and a failing
	 * counter reset is logged.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function invalidThresholds(): array {
		return [
			'non-numeric' => ['abc'],
			'zero' => ['0'],
		];
	}//end invalidThresholds()

	/**
	 * Soft bounce at the default threshold withdraws consent.
	 *
	 * @param string $threshold The configured (invalid) threshold.
	 *
	 * @dataProvider invalidThresholds
	 *
	 * @return void
	 */
	public function testInvalidSoftBounceThresholdUsesDefault(string $threshold): void {
		$this->config['blast.soft_bounce_threshold'] = $threshold;
		$this->config['blast.soft_bounce_count.c-1.email'] = '3';
		$this->storedDelivery(['uuid' => 'd-1', 'contactId' => 'c-1', 'blastId' => 'b-1']);
		$this->captureSaves();
		$this->appConfig->method('deleteKey')->willThrowException(new RuntimeException('ro'));
		$this->logger->expects($this->once())->method('info')
			->with(
				'WebhookProcessorService.clearSoftBounceCounter: delete failed',
				['contactId' => 'c-1', 'channel' => 'email', 'exception' => 'ro']
			);
		$this->compliance->expects($this->once())->method('recordConsentWithdrawal')
			->with('c-1', 'email', 'bounce-soft-x5', 'b-1');

		$service = $this->service();
		$service->processBounce(['blastDeliveryId' => 'd-1', 'bounceType' => 'soft']);
		$this->assertSame('4', $this->config['blast.soft_bounce_count.c-1.email']);
		$service->processBounce(['blastDeliveryId' => 'd-1', 'bounceType' => 'SOFT']);
		$this->assertSame('5', $this->config['blast.soft_bounce_count.c-1.email']);
	}//end testInvalidSoftBounceThresholdUsesDefault()

	/**
	 * SendGrid spam reports and type-less bounces are normalised.
	 *
	 * @return void
	 */
	public function testNormaliseSendGridSpamReportAndBareBounce(): void {
		$service = $this->service(false);

		$spam = $service->normaliseSendGrid(['event' => 'SpamReport', 'providerId' => 'p-9', 'timestamp' => '1700000000']);
		$bounce = $service->normaliseSendGrid(['event' => 'bounce', 'sg_message_id' => 'sg-1', 'timestamp' => '2026-05-05T05:05:05Z']);
		$open = $service->normaliseSendGrid(['event' => 'open']);

		$this->assertSame('spam_report', $spam['eventType']);
		$this->assertSame('p-9', $spam['providerId']);
		$this->assertSame('2023-11-14T22:13:20Z', $spam['timestamp']);
		$this->assertSame('hard', $bounce['bounceType']);
		$this->assertSame('sg-1', $bounce['providerId']);
		$this->assertSame('2026-05-05T05:05:05Z', $bounce['timestamp']);
		$this->assertSame('', $open['bounceType']);
	}//end testNormaliseSendGridSpamReportAndBareBounce()

	/**
	 * An unparseable or non-scalar timestamp falls back to "now".
	 *
	 * @return void
	 */
	public function testUnparseableTimestampFallsBackToNow(): void {
		$service = $this->service(false);

		$before = time();
		$garbled = $service->normaliseSendGrid(['event' => 'open', 'timestamp' => 'definitely not a time'])['timestamp'];
		$array = $service->normaliseSendGrid(['event' => 'open', 'timestamp' => ['x']])['timestamp'];
		$after = time();

		foreach ([$garbled, $array] as $ts) {
			$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $ts);
			$this->assertGreaterThanOrEqual($before, strtotime($ts));
			$this->assertLessThanOrEqual($after, strtotime($ts));
		}
	}//end testUnparseableTimestampFallsBackToNow()
}//end class
