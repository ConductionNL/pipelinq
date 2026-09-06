<?php

/**
 * Unit tests for TrafficEventEmitter.
 *
 * Covers:
 * - the open and click event shapes match the fleet traffic contract
 * - no email address, phone, IP or client id ever leaves Pipelinq
 * - nothing is sent when no portal is configured
 * - nothing is sent when Portaliq's ingest service is not installed
 * - a throwing ingest, or a container that cannot resolve it, is swallowed
 *
 * Portaliq is never installed in this test run: the availability probe is
 * a protected method overridden by an anonymous subclass, and the ingest
 * service is a hand-written fake resolved from a mocked container.
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
 * @spec openspec/specs/marketing-email-tracking/spec.md#requirement-opens-and-clicks-are-reported-to-portaliq-as-email-traffic-events
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\TrafficEventEmitter;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for TrafficEventEmitter.
 *
 * @spec openspec/specs/marketing-email-tracking/spec.md#requirement-opens-and-clicks-are-reported-to-portaliq-as-email-traffic-events
 */
class TrafficEventEmitterTest extends TestCase {
	/**
	 * Fixed clock: 2023-11-14T22:13:20Z.
	 *
	 * @var int
	 */
	private const NOW = 1700000000;

	/**
	 * A delivery row as TrackingLinkService loads it. Carries the PII
	 * fields on purpose so the tests can prove they never leave.
	 *
	 * @var array<string, mixed>
	 */
	private const DELIVERY = [
		'uuid' => '00000000-0000-0000-0000-000000000000',
		'blastId' => 'blast-1',
		'contactId' => 'contact-9',
		'email' => 'someone@example.invalid',
		'phone' => '+31000000000',
		'status' => 'delivered',
	];

	/**
	 * Fake ingest service that records every call.
	 *
	 * @var object
	 */
	private object $ingest;

	/**
	 * Build an emitter around a fake ingest service.
	 *
	 * @param bool $available What the class_exists probe should answer.
	 * @param string $portal The configured `blast.traffic_portal` value.
	 * @param bool $throwingIngest Whether the fake ingest throws.
	 * @param bool $unresolvable Whether the container refuses to resolve
	 *                           the ingest service.
	 * @param LoggerInterface|null $logger Optional pre-configured logger mock.
	 *
	 * @return TrafficEventEmitter
	 */
	private function build(
		bool $available = true,
		string $portal = 'gemeente-portal',
		bool $throwingIngest = false,
		bool $unresolvable = false,
		?LoggerInterface $logger = null,
	): TrafficEventEmitter {
		$this->ingest = new class ($throwingIngest) {
			/** @var array<int, array{portalSlug: string, events: array<int, array<string, mixed>>, context: array<string, mixed>}> */
			public array $calls = [];

			public function __construct(private bool $throwing) {
			}//end __construct()

			/**
			 * @param array<int, array<string, mixed>> $events
			 * @param array<string, mixed> $context
			 *
			 * @return array{accepted: int, refused: array<string, int>}
			 */
			public function ingest(string $portalSlug, array $events, array $context = []): array {
				if ($this->throwing === true) {
					throw new RuntimeException('portaliq is on fire');
				}

				$this->calls[] = ['portalSlug' => $portalSlug, 'events' => $events, 'context' => $context];
				return ['accepted' => count($events), 'refused' => []];
			}//end ingest()
		};

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use ($portal): string {
				return ($key === TrafficEventEmitter::PORTAL_CONFIG_KEY) ? $portal : $default;
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($unresolvable) {
				if ($unresolvable === false && $id === TrafficEventEmitter::INGEST_SERVICE_CLASS) {
					return $this->ingest;
				}

				throw new RuntimeException('not registered: ' . $id);
			}
		);

		return new class ($container, $appConfig, $time, ($logger ?? $this->createMock(LoggerInterface::class)), $available) extends TrafficEventEmitter {
			public function __construct(
				ContainerInterface $container,
				IAppConfig $appConfig,
				ITimeFactory $time,
				LoggerInterface $logger,
				private bool $available,
			) {
				parent::__construct($container, $appConfig, $time, $logger);
			}//end __construct()

			protected function isIngestServiceAvailable(): bool {
				return $this->available;
			}//end isIngestServiceAvailable()
		};
	}//end build()

	/**
	 * An open becomes exactly one `email_open` event, sequence 0, addressed
	 * to `mailto:blast/<blastId>`, carrying blastRef and contactRef, with
	 * the server-side consent context the contract asks for.
	 *
	 * @return void
	 */
	public function testOpenEventMatchesTheContract(): void {
		$emitter = $this->build();

		$emitter->emitEmailEvent(kind: 'open', delivery: self::DELIVERY, blast: ['name' => 'Spring launch'], clickedUrl: null);

		$this->assertCount(1, $this->ingest->calls);
		$call = $this->ingest->calls[0];
		$this->assertSame('gemeente-portal', $call['portalSlug']);
		$this->assertSame(['serverSide' => true, 'consent' => true], $call['context']);
		$this->assertCount(1, $call['events']);
		$this->assertSame(
			[
				'name' => 'email_open',
				'timestamp' => '2023-11-14T22:13:20Z',
				'sequence' => 0,
				'pageLocation' => 'mailto:blast/blast-1',
				'params' => [],
				'campaign' => 'Spring launch',
				'source' => 'email',
				'medium' => 'email',
				'blastRef' => 'blast-1',
				'contactRef' => 'contact-9',
			],
			$call['events'][0]
		);
	}//end testOpenEventMatchesTheContract()

	/**
	 * A click becomes an `email_click` event, sequence 1, whose pageLocation
	 * is the clicked URL.
	 *
	 * @return void
	 */
	public function testClickEventCarriesTheClickedUrl(): void {
		$emitter = $this->build();

		$emitter->emitEmailEvent(
			kind: 'click',
			delivery: self::DELIVERY,
			blast: ['name' => 'Spring launch'],
			clickedUrl: 'https://pipelinq.nl/spring?utm_campaign=x',
		);

		$event = $this->ingest->calls[0]['events'][0];
		$this->assertSame('email_click', $event['name']);
		$this->assertSame(1, $event['sequence']);
		$this->assertSame('https://pipelinq.nl/spring?utm_campaign=x', $event['pageLocation']);
	}//end testClickEventCarriesTheClickedUrl()

	/**
	 * The event never carries an email address, phone number, IP address,
	 * user agent or client id, whatever the delivery row holds.
	 *
	 * @return void
	 */
	public function testEventCarriesNoPii(): void {
		$emitter = $this->build();

		$emitter->emitEmailEvent(kind: 'open', delivery: self::DELIVERY, blast: [], clickedUrl: null);

		$event = $this->ingest->calls[0]['events'][0];
		foreach (['email', 'phone', 'ip', 'userAgent', 'clientId', 'uuid'] as $forbidden) {
			$this->assertArrayNotHasKey($forbidden, $event);
		}

		$this->assertStringNotContainsString('example.invalid', json_encode($this->ingest->calls[0]));
		$this->assertArrayNotHasKey('ip', $this->ingest->calls[0]['context']);
		$this->assertArrayNotHasKey('userAgent', $this->ingest->calls[0]['context']);
	}//end testEventCarriesNoPii()

	/**
	 * `campaign` falls back from the blast name to its template id, then to
	 * the blast id, so an event is never attributed to an empty campaign.
	 *
	 * @return void
	 */
	public function testCampaignFallsBackToTemplateIdThenBlastId(): void {
		$emitter = $this->build();

		$emitter->emitEmailEvent(kind: 'open', delivery: self::DELIVERY, blast: ['templateId' => 'tpl-3'], clickedUrl: null);
		$emitter->emitEmailEvent(kind: 'open', delivery: self::DELIVERY, blast: [], clickedUrl: null);

		$this->assertSame('tpl-3', $this->ingest->calls[0]['events'][0]['campaign']);
		$this->assertSame('blast-1', $this->ingest->calls[1]['events'][0]['campaign']);
	}//end testCampaignFallsBackToTemplateIdThenBlastId()

	/**
	 * No portal configured: nothing is resolved and nothing is sent.
	 *
	 * @return void
	 */
	public function testSkipsWhenNoPortalIsConfigured(): void {
		$emitter = $this->build(portal: '  ');

		$emitter->emitEmailEvent(kind: 'open', delivery: self::DELIVERY, blast: [], clickedUrl: null);

		$this->assertSame([], $this->ingest->calls);
	}//end testSkipsWhenNoPortalIsConfigured()

	/**
	 * Portaliq not installed: the probe answers false and nothing is sent,
	 * even though a portal is configured.
	 *
	 * @return void
	 */
	public function testSkipsWhenIngestServiceIsNotInstalled(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$emitter = $this->build(available: false, logger: $logger);

		$emitter->emitEmailEvent(kind: 'open', delivery: self::DELIVERY, blast: [], clickedUrl: null);

		$this->assertSame([], $this->ingest->calls);
	}//end testSkipsWhenIngestServiceIsNotInstalled()

	/**
	 * A throwing ingest is logged at warning and never escapes.
	 *
	 * @return void
	 */
	public function testSwallowsAThrowingIngest(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('reporting to Portaliq failed'), $this->arrayHasKey('exception'));

		$emitter = $this->build(throwingIngest: true, logger: $logger);

		$emitter->emitEmailEvent(kind: 'click', delivery: self::DELIVERY, blast: [], clickedUrl: 'https://pipelinq.nl/x');

		$this->assertSame([], $this->ingest->calls);
	}//end testSwallowsAThrowingIngest()

	/**
	 * A container that cannot resolve the ingest service is logged at
	 * warning and never escapes.
	 *
	 * @return void
	 */
	public function testSwallowsAnUnresolvableIngestService(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$emitter = $this->build(unresolvable: true, logger: $logger);

		$emitter->emitEmailEvent(kind: 'open', delivery: self::DELIVERY, blast: [], clickedUrl: null);

		$this->assertSame([], $this->ingest->calls);
	}//end testSwallowsAnUnresolvableIngestService()

	/**
	 * An unknown kind is refused before any lookup happens.
	 *
	 * @return void
	 */
	public function testRefusesAnUnknownKind(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$emitter = $this->build(logger: $logger);

		$emitter->emitEmailEvent(kind: 'bounce', delivery: self::DELIVERY, blast: [], clickedUrl: null);

		$this->assertSame([], $this->ingest->calls);
	}//end testRefusesAnUnknownKind()
}//end class
