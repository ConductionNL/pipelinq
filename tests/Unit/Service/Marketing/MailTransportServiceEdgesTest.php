<?php

/**
 * MailTransportService: what it does when a collaborator is missing or fails.
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

use OCA\Pipelinq\Service\ArticleService;
use OCA\Pipelinq\Service\ConnectorSourceRegister;
use OCA\Pipelinq\Service\Marketing\MailTransportService;
use OCA\Pipelinq\Service\Marketing\PhysicalAddressRenderer;
use OCA\Pipelinq\Service\Marketing\RecipientNameReader;
use OCA\Pipelinq\Service\UnsubscribeMail;
use OCP\IAppConfig;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Transport resolution, rate-limit reading, adapter dispatch and first-party
 * tracking, each with its collaborator absent or failing.
 *
 * @covers \OCA\Pipelinq\Service\Marketing\MailTransportService
 * @uses   \OCA\Pipelinq\Service\Marketing\Transport\InstanceMailerTransport
 * @uses   \OCA\Pipelinq\Service\Marketing\Transport\RenderedMail
 * @uses   \OCA\Pipelinq\Service\Marketing\Transport\MailAddressing
 * @uses   \OCA\Pipelinq\Service\Marketing\Transport\MailContent
 * @uses   \OCA\Pipelinq\Service\Marketing\Transport\SendResult
 */
class MailTransportServiceEdgesTest extends TestCase {

	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	private const TRACKING = 'OCA\\Pipelinq\\Service\\TrackingLinkService';

	/**
	 * What the container hands back per id: an object, or a Throwable to throw.
	 *
	 * @var array<string, mixed>
	 */
	private array $services = [];

	/**
	 * App-config values by key.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * A recording stand-in for OpenRegister's ObjectService.
	 *
	 * @var object
	 */
	private object $objects;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mailer mock.
	 *
	 * @var IMailer&MockObject
	 */
	private IMailer&MockObject $mailer;

	/**
	 * Connector register mock.
	 *
	 * @var ConnectorSourceRegister&MockObject
	 */
	private ConnectorSourceRegister&MockObject $connectorRegister;

	/**
	 * Build the fakes.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->mailer = $this->createMock(IMailer::class);
		$this->connectorRegister = $this->createMock(ConnectorSourceRegister::class);
		$this->objects = new class {
			/** @var mixed What find() hands back, or a Throwable to throw. */
			public mixed $found = null;

			/** @var mixed What findAll() hands back, or a Throwable to throw. */
			public mixed $rows = [];

			/** @var \Throwable|null Thrown by saveObject() when set. */
			public ?\Throwable $saveFails = null;

			/** @var array<int, array<int, mixed>> The calls seen. */
			public array $calls = [];

			public function find(string $id, $register = null, $schema = null): mixed {
				$this->calls[] = ['find', $id, $register, $schema];
				if ($this->found instanceof \Throwable) {
					throw $this->found;
				}

				return $this->found;
			}//end find()

			public function findAll(array $config = []): mixed {
				$this->calls[] = ['findAll', $config];
				if ($this->rows instanceof \Throwable) {
					throw $this->rows;
				}

				return $this->rows;
			}//end findAll()

			public function saveObject(array $object, $register = null, $schema = null, ?string $uuid = null): array {
				$this->calls[] = ['save', $object, $register, $schema, $uuid];
				if ($this->saveFails !== null) {
					throw $this->saveFails;
				}

				return $object;
			}//end saveObject()
		};
		$this->services = [self::OBJECT_SERVICE => $this->objects];
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return MailTransportService
	 */
	private function service(): MailTransportService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
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

		$addresses = $this->createMock(PhysicalAddressRenderer::class);
		$addresses->method('render')->willReturnCallback(static fn (string $body): string => $body);
		$names = $this->createMock(RecipientNameReader::class);
		$names->method('greetingName')->willReturn('Anna');

		return new MailTransportService(
			$container,
			$appConfig,
			$this->mailer,
			$this->createMock(ArticleService::class),
			$this->connectorRegister,
			$this->logger,
			$addresses,
			$names,
		);
	}//end service()

	/**
	 * The calls of one kind the ObjectService saw.
	 *
	 * @param string $kind The call kind.
	 *
	 * @return array<int, array<int, mixed>>
	 */
	private function calls(string $kind): array {
		return array_values(array_filter($this->objects->calls, static fn (array $c): bool => $c[0] === $kind));
	}//end calls()

	/**
	 * A mailer that accepts and records the HTML body.
	 *
	 * @param array<int, string> $html Receives the HTML bodies.
	 *
	 * @return void
	 */
	private function acceptingMailer(array &$html): void {
		$message = $this->createMock(IMessage::class);
		foreach (['setFrom', 'setTo', 'setReplyTo', 'setSubject', 'setPlainBody'] as $setter) {
			$message->method($setter)->willReturn($message);
		}

		$message->method('setHtmlBody')->willReturnCallback(
			function (string $body) use (&$html, $message): IMessage {
				$html[] = $body;
				return $message;
			}
		);
		$this->mailer->method('createMessage')->willReturn($message);
		$this->mailer->method('send')->willReturn([]);
	}//end acceptingMailer()

	/**
	 * A named transport that throws or is missing falls back to the default
	 * transport, which is read from the configured schema and the default
	 * register; an entity row is read through jsonSerialize.
	 *
	 * @return void
	 */
	public function testAMissingNamedTransportFallsBackToTheDefault(): void {
		$this->config = ['mailTransport_schema' => 'transports'];
		$this->objects->found = new RuntimeException('gone');
		$this->objects->rows = [
			new class implements \JsonSerializable {
				/**
				 * The serialised row.
				 *
				 * @return array<string, mixed>
				 */
				public function jsonSerialize(): array {
					return ['uuid' => 'default-t', 'kind' => 'instance'];
				}//end jsonSerialize()
			},
		];
		$this->logger->expects($this->once())->method('info')->with(
			'MailTransportService.loadOne: not found',
			['id' => 't-1', 'exception' => 'gone']
		);

		$this->assertSame(['uuid' => 'default-t', 'kind' => 'instance'], $this->service()->resolveTransport(blast: ['transportId' => 't-1']));
		$this->assertSame(['find', 't-1', 'pipelinq', 'transports'], $this->objects->calls[0]);
		$this->assertSame(
			['findAll', ['filters' => ['register' => 'pipelinq', 'schema' => 'transports', 'default' => true], 'limit' => 1]],
			$this->objects->calls[1]
		);
	}//end testAMissingNamedTransportFallsBackToTheDefault()

	/**
	 * A null named transport and an entity read through getObject.
	 *
	 * @return void
	 */
	public function testANullNamedTransportFallsBackToAnObjectRow(): void {
		$this->objects->found = null;
		$this->objects->rows = [
			new class {
				/**
				 * The row payload.
				 *
				 * @return array<string, mixed>
				 */
				public function getObject(): array {
					return ['uuid' => 'from-getobject'];
				}//end getObject()
			},
		];

		$this->assertSame(['uuid' => 'from-getobject'], $this->service()->resolveTransport(blast: ['transportId' => 't-1']));
	}//end testANullNamedTransportFallsBackToAnObjectRow()

	/**
	 * No default row, an unreadable row, or a failing query: no transport.
	 *
	 * @return void
	 */
	public function testNoUsableDefaultTransportIsNull(): void {
		$service = $this->service();

		$this->objects->rows = [];
		$this->assertNull($service->resolveTransport(blast: []));

		$this->objects->rows = null;
		$this->assertNull($service->resolveTransport(blast: []));

		$this->objects->rows = ['not-a-row'];
		$this->assertSame([], $service->resolveTransport(blast: []));

		$this->objects->rows = new RuntimeException('db down');
		$this->logger->expects($this->once())->method('warning')->with(
			'MailTransportService.loadDefaultTransport: findAll failed',
			['exception' => 'db down']
		);
		$this->assertNull($service->resolveTransport(blast: []));
	}//end testNoUsableDefaultTransportIsNull()

	/**
	 * Without OpenRegister no transport resolves, and each attempt is logged.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNoTransportResolves(): void {
		$this->services[self::OBJECT_SERVICE] = new RuntimeException('no openregister');
		$this->logger->expects($this->exactly(2))->method('warning')->with(
			'MailTransportService.getObjectService: unavailable',
			['exception' => 'no openregister']
		);

		$this->assertNull($this->service()->resolveTransport(blast: ['transportId' => 't-1']));
	}//end testWithoutOpenRegisterNoTransportResolves()

	/**
	 * A provider transport keeps the caller's rate when the source cannot be
	 * read: no source id, no OpenRegister, no register, a failing or empty
	 * lookup, or no usable declared limit.
	 *
	 * @return void
	 */
	public function testAnUnreadableSourceKeepsTheCallerRate(): void {
		$service = $this->service();
		$provider = ['kind' => 'provider', 'connectorSourceId' => 'src-1'];

		$this->assertSame(40, $service->resolveRateLimit(transport: ['kind' => 'provider'], callerRate: 40));

		$this->connectorRegister->method('slugOrNull')->willReturnOnConsecutiveCalls(null, 'connectors', 'connectors', 'connectors', 'connectors', 'connectors');
		$this->assertSame(40, $service->resolveRateLimit(transport: $provider, callerRate: 40));

		$this->objects->found = new RuntimeException('no source');
		$this->assertSame(40, $service->resolveRateLimit(transport: $provider, callerRate: 40));
		$this->assertSame(['find', 'src-1', 'connectors', ConnectorSourceRegister::SOURCE_SCHEMA], $this->calls('find')[0]);

		$this->objects->found = null;
		$this->assertSame(40, $service->resolveRateLimit(transport: $provider, callerRate: 40));

		$this->objects->found = ['rateLimitLimit' => 'lots'];
		$this->assertSame(40, $service->resolveRateLimit(transport: $provider, callerRate: 40));

		$this->objects->found = ['rateLimitLimit' => 0];
		$this->assertSame(40, $service->resolveRateLimit(transport: $provider, callerRate: 40));

		$this->services[self::OBJECT_SERVICE] = new RuntimeException('no openregister');
		$this->assertSame(40, $service->resolveRateLimit(transport: $provider, callerRate: 40));
	}//end testAnUnreadableSourceKeepsTheCallerRate()

	/**
	 * The declared limit is spread over its window; a non-positive or
	 * unreadable window counts as one second, and the rate never drops below 1.
	 *
	 * @return void
	 */
	public function testTheSourceLimitIsSpreadOverItsWindow(): void {
		$this->connectorRegister->method('slugOrNull')->willReturn('connectors');
		$service = $this->service();
		$provider = ['kind' => 'provider', 'connectorSourceId' => 'src-1'];

		$this->objects->found = ['rateLimitLimit' => 60, 'rateLimitWindow' => 4];
		$this->assertSame(15, $service->resolveRateLimit(transport: $provider, callerRate: 0));

		$this->objects->found = ['rateLimitLimit' => '30', 'rateLimitWindow' => -5];
		$this->assertSame(30, $service->resolveRateLimit(transport: $provider, callerRate: 0));

		$this->objects->found = ['rateLimitLimit' => 20, 'rateLimitWindow' => 0];
		$this->assertSame(20, $service->resolveRateLimit(transport: $provider, callerRate: 0));

		$this->objects->found = ['rateLimitLimit' => 30, 'rateLimitWindow' => 'hourly'];
		$this->assertSame(30, $service->resolveRateLimit(transport: $provider, callerRate: 0));

		$this->objects->found = ['rateLimitLimit' => 5, 'rateLimitWindow' => 60];
		$this->assertSame(1, $service->resolveRateLimit(transport: $provider, callerRate: 0));
	}//end testTheSourceLimitIsSpreadOverItsWindow()

	/**
	 * An unknown kind fails the delivery and saves it as failed under the
	 * default blast-delivery schema, with its @self id.
	 *
	 * @return void
	 */
	public function testAnUnknownTransportKindFailsTheDelivery(): void {
		$this->logger->expects($this->once())->method('error')->with(
			'MailTransportService.dispatchToAdapter: unknown transport kind',
			['kind' => 'pigeon']
		);

		$sent = $this->service()->sendOneDelivery(
			delivery: ['@self' => ['uuid' => '', 'id' => 'd-7'], 'email' => 'a@example.org'],
			template: ['subject' => 'Hi'],
			transport: ['kind' => 'pigeon'],
		);

		$this->assertFalse($sent);
		$save = $this->calls('save');
		$this->assertCount(1, $save);
		$this->assertSame(['pipelinq', 'blastDelivery', 'd-7'], [$save[0][2], $save[0][3], $save[0][4]]);
		$this->assertSame('failed', $save[0][1]['status']);
	}//end testAnUnknownTransportKindFailsTheDelivery()

	/**
	 * An adapter that cannot be built fails the delivery; a failing save of
	 * that failure is logged, not thrown.
	 *
	 * @return void
	 */
	public function testAnAdapterThatCannotBeBuiltFailsTheDelivery(): void {
		$this->config = ['blastDelivery_schema' => 'deliveries'];
		$this->objects->saveFails = new RuntimeException('read only');
		$this->logger->expects($this->once())->method('error')->with(
			'MailTransportService.dispatchToAdapter: adapter construction failed',
			$this->callback(static fn (array $ctx): bool => $ctx['kind'] === 'mailAccount' && $ctx['exception'] !== '')
		);
		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			function (string $message, array $context) use (&$warnings): void {
				$warnings[] = [$message, $context];
			}
		);

		$sent = $this->service()->sendOneDelivery(
			delivery: ['uuid' => 'd-1'],
			template: [],
			transport: ['kind' => 'mailAccount', 'mailAccountRef' => new \stdClass()],
		);

		$this->assertFalse($sent);
		$this->assertSame(
			[
				['MailTransportService.sendOneDelivery: transport rejected the delivery', ['deliveryId' => 'd-1', 'reason' => 'adapter-construction-failed']],
				['MailTransportService.saveDelivery: save failed', ['id' => 'd-1', 'exception' => 'read only']],
			],
			$warnings
		);
		$this->assertSame('deliveries', $this->calls('save')[0][3]);
	}//end testAnAdapterThatCannotBeBuiltFailsTheDelivery()

	/**
	 * With first-party tracking on, the HTML that is sent is the one the
	 * tracking service returns for this delivery; a successful send then
	 * advances the transport's count, and a failing save of it is logged.
	 *
	 * @return void
	 */
	public function testTrackingRewritesTheHtmlThatIsSent(): void {
		$this->config = ['blast.first_party_tracking' => 'true'];
		$this->services[UnsubscribeMail::class] = new \stdClass();
		$this->services[self::TRACKING] = new class {
			/** @var array<int, array<int, string>> */
			public array $seen = [];

			public function injectTracking(string $html, string $blastDeliveryId): string {
				$this->seen[] = [$html, $blastDeliveryId];
				return $html . '<img src="pixel">';
			}//end injectTracking()
		};
		$html = [];
		$this->acceptingMailer($html);
		$today = gmdate('Y-m-d') . 'T00:00:00Z';

		$sent = $this->service()->sendOneDelivery(
			delivery: ['uuid' => 'd-1', 'email' => 'a@example.org'],
			template: ['bodyHtml' => '<p>Hi {{preferredName}}</p>', 'senderEmail' => 'news@example.org'],
			transport: ['uuid' => 't-1', 'kind' => 'instance', 'sentToday' => 2, 'dailyLimitResetAt' => $today],
		);

		$this->assertTrue($sent);
		$this->assertSame([['<p>Hi Anna</p>', 'd-1']], $this->services[self::TRACKING]->seen);
		$this->assertSame(['<p>Hi Anna</p><img src="pixel">'], $html);
		$save = $this->calls('save');
		$this->assertSame('sent', $save[0][1]['status']);
		$this->assertSame(['t-1', 3], [$save[1][4], $save[1][1]['sentToday']]);
		$this->assertSame('mailTransport', $save[1][3]);
	}//end testTrackingRewritesTheHtmlThatIsSent()

	/**
	 * A failing tracking service leaves the HTML as rendered; a missing one
	 * too. A failing save of the transport count is logged.
	 *
	 * @return void
	 */
	public function testFailingTrackingKeepsTheRenderedHtml(): void {
		$this->config = ['blast.first_party_tracking' => 'true'];
		$this->services[self::TRACKING] = new class {
			public function injectTracking(string $html, string $blastDeliveryId): string {
				throw new RuntimeException('bad html');
			}//end injectTracking()
		};
		$html = [];
		$this->acceptingMailer($html);
		$today = gmdate('Y-m-d') . 'T00:00:00Z';
		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			function (string $message, array $context) use (&$warnings): void {
				$warnings[] = [$message, $context];
			}
		);
		$infos = [];
		$this->logger->method('info')->willReturnCallback(
			function (string $message, array $context) use (&$infos): void {
				$infos[] = [$message, $context];
			}
		);

		$service = $this->service();
		$transport = ['uuid' => 't-1', 'kind' => 'instance', 'dailyLimitResetAt' => $today];
		$this->assertTrue($service->sendOneDelivery(delivery: ['uuid' => 'd-1'], template: ['bodyHtml' => '<p>A</p>'], transport: $transport));

		unset($this->services[self::TRACKING]);
		$this->objects->saveFails = new RuntimeException('read only');
		$this->assertTrue($service->sendOneDelivery(delivery: ['uuid' => 'd-2'], template: ['bodyHtml' => '<p>B</p>'], transport: $transport));

		$this->assertSame(['<p>A</p>', '<p>B</p>'], $html);
		$this->assertSame(
			[
				['MailTransportService.injectTrackingLinks: injection failed', ['blastDeliveryId' => 'd-1', 'exception' => 'bad html']],
				['MailTransportService.saveDelivery: save failed', ['id' => 'd-2', 'exception' => 'read only']],
				['MailTransportService.saveObject: save failed', ['id' => 't-1', 'exception' => 'read only']],
			],
			$warnings
		);
		$this->assertSame(
			[['MailTransportService.injectTrackingLinks: TrackingLinkService unavailable', ['exception' => 'not registered: ' . self::TRACKING]]],
			$infos
		);
	}//end testFailingTrackingKeepsTheRenderedHtml()

	/**
	 * With tracking on, an empty body or a delivery without an id is not
	 * handed to the tracking service.
	 *
	 * @return void
	 */
	public function testTrackingSkipsAnEmptyBodyOrAnIdlessDelivery(): void {
		$this->config = ['blast.first_party_tracking' => 'true'];
		$this->services[self::TRACKING] = new class {
			public function injectTracking(string $html, string $blastDeliveryId): string {
				throw new \LogicException('must not be called');
			}//end injectTracking()
		};
		$html = [];
		$this->acceptingMailer($html);
		$service = $this->service();

		$this->assertTrue($service->sendOneDelivery(delivery: [], template: ['bodyHtml' => '<p>A</p>'], transport: ['kind' => 'instance']));
		$this->assertFalse($service->sendOneDelivery(delivery: ['uuid' => 'd-1'], template: [], transport: ['kind' => 'pigeon']));

		$this->assertSame(['<p>A</p>'], $html);
	}//end testTrackingSkipsAnEmptyBodyOrAnIdlessDelivery()

	/**
	 * Without OpenRegister a send still goes out; nothing is recorded.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterASendIsNotRecorded(): void {
		$this->services[self::OBJECT_SERVICE] = new RuntimeException('no openregister');
		$html = [];
		$this->acceptingMailer($html);
		$this->logger->expects($this->exactly(3))->method('warning')->with('MailTransportService.getObjectService: unavailable');

		$sent = $this->service()->sendOneDelivery(
			delivery: ['uuid' => 'd-1'],
			template: ['bodyHtml' => '<p>A</p>'],
			transport: ['uuid' => 't-1', 'kind' => 'instance', 'dailyLimitResetAt' => '2001-01-01T00:00:00Z'],
		);

		$this->assertTrue($sent);
		$this->assertSame(['<p>A</p>'], $html);
	}//end testWithoutOpenRegisterASendIsNotRecorded()
}//end class
