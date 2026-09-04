<?php

/**
 * Unit tests for TrackingLinkService.
 *
 * Covers:
 * - sign/verify round-trip for open + click tokens
 * - tampered and expired tokens are rejected (fail closed)
 * - link injection rewrites `<a href>` targets and appends the open pixel
 * - `{{unsubscribe_link}}` and in-page anchors are never rewritten
 * - recordOpen is idempotent (openedAt set-once) and refreshes totals
 * - recordClick delegates to AttributionService and refreshes totals
 * - recordOpen/recordClick report to Portaliq only after the write and the
 *   totals roll-up, and survive a throwing emitter
 *
 * Uses placeholder secrets and a nil-UUID delivery id per design.md's Seed
 * Data section (`00000000-0000-0000-0000-000000000000`).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-email-open-click-tracking/tasks.md#4.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\AttributionService;
use OCA\Pipelinq\Service\BlastService;
use OCA\Pipelinq\Service\TrackingLinkService;
use OCA\Pipelinq\Service\TrafficEventEmitter;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for TrackingLinkService.
 *
 * @spec openspec/changes/marketing-email-open-click-tracking/tasks.md#4.1
 */
class TrackingLinkServiceTest extends TestCase {
	/**
	 * Placeholder BlastDelivery id used across the fixtures (design.md
	 * Seed Data section — nil UUID, never a realistic-looking value).
	 *
	 * @var string
	 */
	private const DELIVERY_ID = '00000000-0000-0000-0000-000000000000';

	/**
	 * In-memory app-config key/value store driven by the mock IAppConfig.
	 *
	 * @var array<string, string>
	 */
	private array $appConfigStore = [];

	/**
	 * Fake OpenRegister ObjectService used by every build().
	 *
	 * @var object
	 */
	private object $objectService;

	/**
	 * Build a TrackingLinkService wired to mocked collaborators.
	 *
	 * @param int $time Fixed timestamp for ITimeFactory.
	 * @param AttributionService|null $attributionService Optional pre-configured mock.
	 * @param BlastService|null $blastService Optional pre-configured mock.
	 * @param TrafficEventEmitter|null $trafficEventEmitter Optional pre-configured mock.
	 *
	 * @return TrackingLinkService
	 */
	private function build(
		int $time = 1700000000,
		?AttributionService $attributionService = null,
		?BlastService $blastService = null,
		?TrafficEventEmitter $trafficEventEmitter = null,
	): TrackingLinkService {
		$this->appConfigStore = [
			'register' => 'pipelinq',
			'blastDelivery_schema' => 'blastDelivery',
			'blast.tracking_secret' => 'YOUR_TRACKING_SECRET_HERE',
		];

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				return ($this->appConfigStore[$key] ?? $default);
			}
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->appConfigStore[$key] = $value;
				return true;
			}
		);

		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturn($time);

		$secureRandom = $this->createMock(ISecureRandom::class);
		$secureRandom->method('generate')->willReturn('UNUSED-BECAUSE-SECRET-IS-PRESEEDED');

		$this->objectService = new class {
			/** @var array<string, array<string, mixed>> */
			public array $store = [];

			/** @var array<int, array<string, mixed>> */
			public array $saved = [];

			public function find(string $id, $register = null, $schema = null): ?array {
				return ($this->store[$id] ?? null);
			}//end find()

			public function saveObject(array $object, $register = null, $schema = null, ?string $uuid = null): array {
				if ($uuid === null || $uuid === '') {
					$uuid = ('saved-' . count($this->saved));
				}

				$object['uuid'] = $uuid;
				$this->saved[] = $object;
				$this->store[$uuid] = $object;
				return $object;
			}//end saveObject()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $this->objectService;
				}

				throw new \RuntimeException('not registered: ' . $id);
			}
		);

		$logger = $this->createMock(LoggerInterface::class);

		return new TrackingLinkService($container,
			$appConfig,
			$timeFactory,
			$secureRandom,
			($attributionService ?? $this->createMock(AttributionService::class)),
			($blastService ?? $this->createMock(BlastService::class)),
			$logger,
			($trafficEventEmitter ?? $this->createMock(TrafficEventEmitter::class)),
		);
	}//end build()

	/**
	 * signOpenToken → verifyToken round-trip returns the delivery id with a
	 * null target url.
	 *
	 * @return void
	 */
	public function testOpenTokenRoundTrip(): void {
		$service = $this->build();
		$token = $service->signOpenToken(blastDeliveryId: self::DELIVERY_ID);

		$payload = $service->verifyToken(token: $token);
		$this->assertIsArray($payload);
		$this->assertSame(self::DELIVERY_ID, $payload['d']);
		$this->assertNull($payload['u']);
	}//end testOpenTokenRoundTrip()

	/**
	 * signClickToken → verifyToken round-trip returns the delivery id and
	 * the bound target URL.
	 *
	 * @return void
	 */
	public function testClickTokenRoundTrip(): void {
		$service = $this->build();
		$token = $service->signClickToken(
			blastDeliveryId: self::DELIVERY_ID,
			targetUrl: 'https://pipelinq.nl/q4?utm_campaign=gemeente',
		);

		$payload = $service->verifyToken(token: $token);
		$this->assertIsArray($payload);
		$this->assertSame(self::DELIVERY_ID, $payload['d']);
		$this->assertSame('https://pipelinq.nl/q4?utm_campaign=gemeente', $payload['u']);
	}//end testClickTokenRoundTrip()

	/**
	 * A token whose signature does not match the recomputed HMAC (tampered
	 * payload) is rejected — fail closed.
	 *
	 * @return void
	 */
	public function testVerifyTokenRejectsTamperedToken(): void {
		$service = $this->build();
		$token = $service->signOpenToken(blastDeliveryId: self::DELIVERY_ID);

		[$encoded, $signature] = explode('.', $token);
		$tampered = ($encoded . 'x.' . $signature);

		$this->assertNull($service->verifyToken(token: $tampered));
	}//end testVerifyTokenRejectsTamperedToken()

	/**
	 * A malformed token (no dot separator) is rejected.
	 *
	 * @return void
	 */
	public function testVerifyTokenRejectsMalformedToken(): void {
		$service = $this->build();
		$this->assertNull($service->verifyToken(token: 'not-a-real-token'));
	}//end testVerifyTokenRejectsMalformedToken()

	/**
	 * A token whose `exp` has passed is rejected even though the signature
	 * is valid.
	 *
	 * @return void
	 */
	public function testVerifyTokenRejectsExpiredToken(): void {
		$issuedAt = 1700000000;
		$service = $this->build(time: $issuedAt);
		$token = $service->signOpenToken(blastDeliveryId: self::DELIVERY_ID);

		// Re-verify far past the 90-day default TTL.
		$expiredService = $this->build(time: ($issuedAt + (91 * 86400)));
		$this->assertNull($expiredService->verifyToken(token: $token));
	}//end testVerifyTokenRejectsExpiredToken()

	/**
	 * injectTracking rewrites a plain `<a href>` to the click-redirect
	 * route and appends an open-pixel `<img>` before `</body>`.
	 *
	 * @return void
	 */
	public function testInjectTrackingRewritesLinksAndAppendsPixel(): void {
		$service = $this->build();
		$html = '<html><body><p>Hi</p><a href="https://pipelinq.nl/q4">Read more</a></body></html>';

		$result = $service->injectTracking(html: $html, blastDeliveryId: self::DELIVERY_ID);

		$this->assertStringContainsString('/api/blast/track/click/', $result);
		$this->assertStringNotContainsString('href="https://pipelinq.nl/q4"', $result);
		$this->assertStringContainsString('/api/blast/track/open/', $result);
		$this->assertStringContainsString('<img src="/api/blast/track/open/', $result);
		$this->assertStringContainsString('</body>', $result);
	}//end testInjectTrackingRewritesLinksAndAppendsPixel()

	/**
	 * injectTracking leaves the `{{unsubscribe_link}}` merge token and an
	 * in-page fragment anchor untouched (marketing-compliance).
	 *
	 * @return void
	 */
	public function testInjectTrackingLeavesUnsubscribeLinkUntouched(): void {
		$service = $this->build();
		$html = '<body>'
			. '<a href="{{unsubscribe_link}}">Unsubscribe</a>'
			. '<a href="#top">Back to top</a>'
			. '</body>';

		$result = $service->injectTracking(html: $html, blastDeliveryId: self::DELIVERY_ID);

		$this->assertStringContainsString('href="{{unsubscribe_link}}"', $result);
		$this->assertStringContainsString('href="#top"', $result);
	}//end testInjectTrackingLeavesUnsubscribeLinkUntouched()

	/**
	 * injectTracking on empty input / empty delivery id is a no-op.
	 *
	 * @return void
	 */
	public function testInjectTrackingNoOpOnEmptyInput(): void {
		$service = $this->build();
		$this->assertSame('', $service->injectTracking(html: '', blastDeliveryId: self::DELIVERY_ID));
		$this->assertSame('<body></body>', $service->injectTracking(html: '<body></body>', blastDeliveryId: ''));
	}//end testInjectTrackingNoOpOnEmptyInput()

	/**
	 * recordOpen sets `openedAt` + status `opened` on first hit and
	 * refreshes the blast totals roll-up.
	 *
	 * @return void
	 */
	public function testRecordOpenSetsOpenedAtAndUpdatesTotals(): void {
		$blastService = $this->createMock(BlastService::class);
		$blastService->expects($this->once())
			->method('updateBlastTotals')
			->with('blast-1');

		$service = $this->build(blastService: $blastService);
		$this->objectService->store[self::DELIVERY_ID] = [
			'uuid' => self::DELIVERY_ID,
			'blastId' => 'blast-1',
			'status' => 'delivered',
		];

		$service->recordOpen(blastDeliveryId: self::DELIVERY_ID);

		$saved = end($this->objectService->saved);
		$this->assertNotEmpty($saved['openedAt']);
		$this->assertSame('opened', $saved['status']);
	}//end testRecordOpenSetsOpenedAtAndUpdatesTotals()

	/**
	 * Repeated opens do not overwrite the first `openedAt` — the earliest
	 * timestamp is preserved (idempotent first-open semantics).
	 *
	 * @return void
	 */
	public function testRecordOpenIsIdempotent(): void {
		$service = $this->build();
		$this->objectService->store[self::DELIVERY_ID] = [
			'uuid' => self::DELIVERY_ID,
			'blastId' => 'blast-1',
			'status' => 'opened',
			'openedAt' => '2026-01-01T00:00:00Z',
		];

		$service->recordOpen(blastDeliveryId: self::DELIVERY_ID);

		$saved = end($this->objectService->saved);
		$this->assertSame('2026-01-01T00:00:00Z', $saved['openedAt']);
	}//end testRecordOpenIsIdempotent()

	/**
	 * recordClick delegates to AttributionService::recordClick() with the
	 * delivery id and url, then refreshes the blast totals roll-up.
	 *
	 * @return void
	 */
	public function testRecordClickDelegatesToAttributionServiceAndUpdatesTotals(): void {
		$attributionService = $this->createMock(AttributionService::class);
		$attributionService->expects($this->once())
			->method('recordClick')
			->with(
				self::DELIVERY_ID,
				$this->callback(function (array $event): bool {
					return ($event['url'] ?? '') === 'https://pipelinq.nl/q4';
				}),
			);

		$blastService = $this->createMock(BlastService::class);
		$blastService->expects($this->once())
			->method('updateBlastTotals')
			->with('blast-1');

		$service = $this->build(attributionService: $attributionService, blastService: $blastService);
		$this->objectService->store[self::DELIVERY_ID] = [
			'uuid' => self::DELIVERY_ID,
			'blastId' => 'blast-1',
			'contactId' => 'c1',
			'status' => 'delivered',
		];

		$service->recordClick(blastDeliveryId: self::DELIVERY_ID, url: 'https://pipelinq.nl/q4');
	}//end testRecordClickDelegatesToAttributionServiceAndUpdatesTotals()

	/**
	 * recordClick on an unknown delivery id is a no-op — no attribution
	 * call and no totals refresh.
	 *
	 * @return void
	 */
	public function testRecordClickNoOpWhenDeliveryMissing(): void {
		$attributionService = $this->createMock(AttributionService::class);
		$attributionService->expects($this->never())->method('recordClick');

		$blastService = $this->createMock(BlastService::class);
		$blastService->expects($this->never())->method('updateBlastTotals');

		$service = $this->build(attributionService: $attributionService, blastService: $blastService);
		$service->recordClick(blastDeliveryId: 'unknown-delivery', url: 'https://pipelinq.nl/x');
	}//end testRecordClickNoOpWhenDeliveryMissing()

	/**
	 * recordOpen reports an `open` to the traffic emitter with the loaded
	 * delivery row and the parent blast, and only AFTER the blastDelivery
	 * write and the totals roll-up have both happened.
	 *
	 * @return void
	 */
	public function testRecordOpenReportsToTrafficAfterSaveAndTotals(): void {
		$totalsDone = false;
		$blastService = $this->createMock(BlastService::class);
		$blastService->method('updateBlastTotals')->willReturnCallback(
			function () use (&$totalsDone): void {
				$totalsDone = true;
			}
		);
		$blastService->method('getBlastById')->with('blast-1')->willReturn(['name' => 'Spring launch']);

		$emitter = $this->createMock(TrafficEventEmitter::class);
		$emitter->expects($this->once())
			->method('emitEmailEvent')
			->willReturnCallback(
				function (string $kind, array $delivery, array $blast, ?string $clickedUrl) use (&$totalsDone): void {
					$this->assertCount(1, $this->objectService->saved, 'the delivery must be written before the report');
					$this->assertTrue($totalsDone, 'the totals roll-up must run before the report');
					$this->assertSame('open', $kind);
					$this->assertSame('blast-1', $delivery['blastId']);
					$this->assertSame('contact-9', $delivery['contactId']);
					$this->assertSame(['name' => 'Spring launch'], $blast);
					$this->assertNull($clickedUrl);
				}
			);

		$service = $this->build(blastService: $blastService, trafficEventEmitter: $emitter);
		$this->objectService->store[self::DELIVERY_ID] = [
			'uuid' => self::DELIVERY_ID,
			'blastId' => 'blast-1',
			'contactId' => 'contact-9',
			'status' => 'delivered',
		];

		$service->recordOpen(blastDeliveryId: self::DELIVERY_ID);
	}//end testRecordOpenReportsToTrafficAfterSaveAndTotals()

	/**
	 * recordClick reports a `click` with the clicked URL and the loaded
	 * delivery row, after AttributionService and the totals roll-up.
	 *
	 * @return void
	 */
	public function testRecordClickReportsToTrafficWithTheClickedUrl(): void {
		$clickRecorded = false;
		$attributionService = $this->createMock(AttributionService::class);
		$attributionService->method('recordClick')->willReturnCallback(
			function () use (&$clickRecorded): array {
				$clickRecorded = true;
				return [];
			}
		);

		$emitter = $this->createMock(TrafficEventEmitter::class);
		$emitter->expects($this->once())
			->method('emitEmailEvent')
			->willReturnCallback(
				function (string $kind, array $delivery, array $blast, ?string $clickedUrl) use (&$clickRecorded): void {
					$this->assertTrue($clickRecorded, 'the click must be recorded before the report');
					$this->assertSame('click', $kind);
					$this->assertSame('blast-1', $delivery['blastId']);
					$this->assertSame([], $blast);
					$this->assertSame('https://pipelinq.nl/x', $clickedUrl);
				}
			);

		$service = $this->build(attributionService: $attributionService, trafficEventEmitter: $emitter);
		$this->objectService->store[self::DELIVERY_ID] = [
			'uuid' => self::DELIVERY_ID,
			'blastId' => 'blast-1',
			'status' => 'delivered',
		];

		$service->recordClick(blastDeliveryId: self::DELIVERY_ID, url: 'https://pipelinq.nl/x');
	}//end testRecordClickReportsToTrafficWithTheClickedUrl()

	/**
	 * A throwing emitter never breaks the record: the delivery is written,
	 * the totals are refreshed and recordOpen returns normally.
	 *
	 * @return void
	 */
	public function testRecordOpenSurvivesAThrowingEmitter(): void {
		$blastService = $this->createMock(BlastService::class);
		$blastService->expects($this->once())->method('updateBlastTotals')->with('blast-1');

		$emitter = $this->createMock(TrafficEventEmitter::class);
		$emitter->method('emitEmailEvent')->willThrowException(new \RuntimeException('portaliq is on fire'));

		$service = $this->build(blastService: $blastService, trafficEventEmitter: $emitter);
		$this->objectService->store[self::DELIVERY_ID] = [
			'uuid' => self::DELIVERY_ID,
			'blastId' => 'blast-1',
			'status' => 'delivered',
		];

		$service->recordOpen(blastDeliveryId: self::DELIVERY_ID);

		$saved = end($this->objectService->saved);
		$this->assertSame('opened', $saved['status']);
		$this->assertNotEmpty($saved['openedAt']);
	}//end testRecordOpenSurvivesAThrowingEmitter()

	/**
	 * A missing delivery records nothing and reports nothing.
	 *
	 * @return void
	 */
	public function testRecordOpenDoesNotReportWhenDeliveryMissing(): void {
		$emitter = $this->createMock(TrafficEventEmitter::class);
		$emitter->expects($this->never())->method('emitEmailEvent');

		$service = $this->build(trafficEventEmitter: $emitter);
		$service->recordOpen(blastDeliveryId: 'unknown-delivery');

		$this->assertSame([], $this->objectService->saved);
	}//end testRecordOpenDoesNotReportWhenDeliveryMissing()
}//end class
