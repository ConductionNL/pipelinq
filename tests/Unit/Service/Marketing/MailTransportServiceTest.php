<?php

/**
 * Unit tests for MailTransportService.
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
 * @spec openspec/changes/marketing-mail-transports/specs/marketing-blast/spec.md#requirement-send-via-openconnector-with-per-tenant-provider
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\Pipelinq\Service\ArticleService;
use OCA\Pipelinq\Service\Marketing\ListObjectStore;
use OCA\Pipelinq\Service\Marketing\MailTransportService;
use OCP\IAppConfig;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for MailTransportService — transport resolution, rate-limit
 * resolution, daily-limit enforcement and per-delivery dispatch.
 *
 * @spec openspec/changes/marketing-mail-transports/specs/marketing-blast/spec.md#requirement-send-via-openconnector-with-per-tenant-provider
 */
class MailTransportServiceTest extends TestCase {
	private ContainerInterface $container;
	private IAppConfig $appConfig;
	private IMailer $mailer;
	private LoggerInterface $logger;
	private object $objectService;
	private MailTransportService $service;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->mailer = $this->createMock(IMailer::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->objectService = new class {
			/** @var array<string, array<string, mixed>> */
			public array $store = [];

			/** @var array<int, array<string, mixed>> */
			public array $saved = [];

			public function find(string $id, $register = null, $schema = null): ?array {
				return ($this->store[$id] ?? null);
			}//end find()

			public function findAll(array $config = []): array {
				$filters = ($config['filters'] ?? []);
				unset($filters['register'], $filters['schema']);
				$out = [];
				foreach ($this->store as $row) {
					$matches = true;
					foreach ($filters as $k => $v) {
						if (($row[$k] ?? null) !== $v) {
							$matches = false;
							break;
						}
					}

					if ($matches === true) {
						$out[] = $row;
					}
				}

				$limit = ($config['limit'] ?? null);
				if ($limit !== null) {
					$out = array_slice($out, 0, $limit);
				}

				return $out;
			}//end findAll()

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

		$this->appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default): string {
				return match ($key) {
					'register' => 'pipelinq',
					default => $default,
				};
			}
		);

		$this->container->method('get')->willReturnCallback(
			function (string $id) {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $this->objectService;
				}

				throw new \RuntimeException('not registered: ' . $id);
			}
		);

		$this->service = new MailTransportService(
			$this->container,
			$this->appConfig,
			$this->mailer,
			new ArticleService($this->makeArticleStore()),
			$this->logger,
		);
	}//end setUp()

	/**
	 * resolveTransport() prefers the transport named by blast.transportId
	 * when it exists and is active.
	 *
	 * @return void
	 */
	public function testResolveTransportPrefersNamedTransport(): void {
		$this->objectService->store['transport-named'] = ['uuid' => 'transport-named', 'kind' => 'instance', 'active' => true];
		$this->objectService->store['transport-default'] = ['uuid' => 'transport-default', 'kind' => 'instance', 'active' => true, 'default' => true];

		$resolved = $this->service->resolveTransport(['transportId' => 'transport-named']);

		$this->assertSame('transport-named', $resolved['uuid']);
	}//end testResolveTransportPrefersNamedTransport()

	/**
	 * resolveTransport() falls back to a synthesised legacy provider
	 * transport when the blast carries `connectorSourceId` directly and no
	 * `transportId` — the pre-mailTransport send path.
	 *
	 * @return void
	 */
	public function testResolveTransportFallsBackToLegacyConnectorSourceId(): void {
		$resolved = $this->service->resolveTransport(['connectorSourceId' => 'oc-legacy']);

		$this->assertSame('provider', $resolved['kind']);
		$this->assertSame('sendgrid', $resolved['provider']);
		$this->assertSame('oc-legacy', $resolved['connectorSourceId']);
	}//end testResolveTransportFallsBackToLegacyConnectorSourceId()

	/**
	 * resolveTransport() falls back to the default transport when the blast
	 * names neither a transportId nor a legacy connectorSourceId.
	 *
	 * @return void
	 */
	public function testResolveTransportFallsBackToDefaultTransport(): void {
		$this->objectService->store['transport-default'] = ['uuid' => 'transport-default', 'kind' => 'instance', 'active' => true, 'default' => true];

		$resolved = $this->service->resolveTransport([]);

		$this->assertSame('transport-default', $resolved['uuid']);
	}//end testResolveTransportFallsBackToDefaultTransport()

	/**
	 * resolveTransport() falls back to the default when the named transport
	 * is inactive.
	 *
	 * @return void
	 */
	public function testResolveTransportFallsBackToDefaultWhenNamedTransportInactive(): void {
		$this->objectService->store['transport-inactive'] = ['uuid' => 'transport-inactive', 'kind' => 'instance', 'active' => false];
		$this->objectService->store['transport-default'] = ['uuid' => 'transport-default', 'kind' => 'instance', 'active' => true, 'default' => true];

		$resolved = $this->service->resolveTransport(['transportId' => 'transport-inactive']);

		$this->assertSame('transport-default', $resolved['uuid']);
	}//end testResolveTransportFallsBackToDefaultWhenNamedTransportInactive()

	/**
	 * resolveRateLimit() reads the OpenConnector source's rateLimitLimit for
	 * a provider-kind transport, preferring it over the caller's rate when
	 * it is tighter.
	 *
	 * @return void
	 */
	public function testResolveRateLimitUsesSourceRateForProviderKind(): void {
		$this->objectService->store['oc-source-1'] = ['uuid' => 'oc-source-1', 'rateLimitLimit' => 5, 'rateLimitWindow' => 1];
		$transport = ['kind' => 'provider', 'connectorSourceId' => 'oc-source-1'];

		$rate = $this->service->resolveRateLimit(transport: $transport, callerRate: 100);

		$this->assertSame(5, $rate);
	}//end testResolveRateLimitUsesSourceRateForProviderKind()

	/**
	 * resolveRateLimit() ignores any source rate for an instance/mailAccount
	 * transport (no OpenConnector source to read from).
	 *
	 * @return void
	 */
	public function testResolveRateLimitIgnoresSourceRateForInstanceKind(): void {
		$transport = ['kind' => 'instance'];

		$rate = $this->service->resolveRateLimit(transport: $transport, callerRate: 42);

		$this->assertSame(42, $rate);
	}//end testResolveRateLimitIgnoresSourceRateForInstanceKind()

	/**
	 * sendOneDelivery() refuses to send once sentToday reaches dailyLimit,
	 * without calling the adapter (the mailer mock's send() is never hit).
	 *
	 * @return void
	 */
	public function testSendOneDeliveryRefusesWhenOverDailyLimit(): void {
		$this->objectService->store['transport-at-limit'] = [
			'uuid' => 'transport-at-limit',
			'kind' => 'instance',
			'dailyLimit' => 5,
			'sentToday' => 5,
			'dailyLimitResetAt' => gmdate('Y-m-d\TH:i:s\Z'),
		];
		$this->mailer->expects($this->never())->method('createMessage');

		$delivery = ['uuid' => 'd-1', 'email' => 'user@example.com'];
		$template = ['subject' => 'Hi', 'bodyHtml' => '<p>hi</p>'];

		$result = $this->service->sendOneDelivery($delivery, $template, $this->objectService->store['transport-at-limit']);

		$this->assertFalse($result);
	}//end testSendOneDeliveryRefusesWhenOverDailyLimit()

	/**
	 * sendOneDelivery() advances sentToday by one on a successful send.
	 *
	 * @return void
	 */
	public function testSendOneDeliveryAdvancesSentTodayOnSuccess(): void {
		$transport = [
			'uuid' => 'transport-ok',
			'kind' => 'instance',
			'dailyLimit' => 5,
			'sentToday' => 1,
			'dailyLimitResetAt' => gmdate('Y-m-d\TH:i:s\Z'),
		];
		$this->objectService->store['transport-ok'] = $transport;

		$message = $this->createMock(IMessage::class);
		$message->method('setFrom')->willReturn($message);
		$message->method('setTo')->willReturn($message);
		$message->method('setReplyTo')->willReturn($message);
		$message->method('setSubject')->willReturn($message);
		$message->method('setHtmlBody')->willReturn($message);
		$this->mailer->method('createMessage')->willReturn($message);
		$this->mailer->method('send')->willReturn([]);

		$delivery = ['uuid' => 'd-2', 'email' => 'user@example.com'];
		$template = ['subject' => 'Hi', 'bodyHtml' => '<p>hi</p>'];

		$result = $this->service->sendOneDelivery($delivery, $template, $transport);

		$this->assertTrue($result);
		$this->assertSame(2, $this->objectService->store['transport-ok']['sentToday']);
		$this->assertSame('sent', $this->objectService->store['d-2']['status']);
	}//end testSendOneDeliveryAdvancesSentTodayOnSuccess()

	/**
	 * sendOneDelivery() rolls sentToday to 0 when dailyLimitResetAt is from
	 * an earlier calendar day, before evaluating the limit.
	 *
	 * @return void
	 */
	public function testSendOneDeliveryRollsDailyLimitOnNewDay(): void {
		$transport = [
			'uuid' => 'transport-stale',
			'kind' => 'instance',
			'dailyLimit' => 1,
			'sentToday' => 1,
			'dailyLimitResetAt' => '2020-01-01T00:00:00Z',
		];
		$this->objectService->store['transport-stale'] = $transport;

		$message = $this->createMock(IMessage::class);
		$message->method('setFrom')->willReturn($message);
		$message->method('setTo')->willReturn($message);
		$message->method('setReplyTo')->willReturn($message);
		$message->method('setSubject')->willReturn($message);
		$message->method('setHtmlBody')->willReturn($message);
		$this->mailer->method('createMessage')->willReturn($message);
		$this->mailer->method('send')->willReturn([]);

		$delivery = ['uuid' => 'd-3', 'email' => 'user@example.com'];
		$template = ['subject' => 'Hi', 'bodyHtml' => '<p>hi</p>'];

		$result = $this->service->sendOneDelivery($delivery, $template, $transport);

		$this->assertTrue($result, 'the roll must happen before the limit check, or a stale-but-at-cap counter would wrongly refuse the send');
		$this->assertSame(1, $this->objectService->store['transport-stale']['sentToday']);
	}//end testSendOneDeliveryRollsDailyLimitOnNewDay()

	/**
	 * sendOneDelivery() against an `instance`-kind transport dispatches
	 * through IMailer and marks the delivery sent.
	 *
	 * @return void
	 */
	public function testSendOneDeliveryDispatchesToInstanceMailerTransport(): void {
		$transport = ['uuid' => 't-instance', 'kind' => 'instance', 'dailyLimit' => 0, 'sentToday' => 0];
		$this->objectService->store['t-instance'] = $transport;

		$message = $this->createMock(IMessage::class);
		$message->method('setFrom')->willReturn($message);
		$message->method('setTo')->willReturn($message);
		$message->method('setReplyTo')->willReturn($message);
		$message->method('setSubject')->willReturn($message);
		$message->method('setHtmlBody')->willReturn($message);
		$this->mailer->expects($this->once())->method('createMessage')->willReturn($message);
		$this->mailer->expects($this->once())->method('send')->willReturn([]);

		$delivery = ['uuid' => 'd-inst', 'email' => 'user@example.com'];
		$template = ['subject' => 'Hi', 'bodyHtml' => '<p>hi</p>', 'senderEmail' => 'noreply@example.test'];

		$result = $this->service->sendOneDelivery($delivery, $template, $transport);
		$this->assertTrue($result);
	}//end testSendOneDeliveryDispatchesToInstanceMailerTransport()

	/**
	 * sendOneDelivery() against a `mailAccount`-kind transport, with the
	 * Mail app absent, degrades soft: the delivery is marked failed, and no
	 * exception reaches the caller.
	 *
	 * @return void
	 */
	public function testSendOneDeliveryDispatchesToMailAccountTransportDegradesSoftWhenMailAppAbsent(): void {
		$transport = [
			'uuid' => 't-mailaccount',
			'kind' => 'mailAccount',
			'mailAccountRef' => '1',
			'mailAccountUserId' => 'alice',
			'dailyLimit' => 0,
			'sentToday' => 0,
		];
		$this->objectService->store['t-mailaccount'] = $transport;

		$delivery = ['uuid' => 'd-ma', 'email' => 'user@example.com'];
		$template = ['subject' => 'Hi', 'bodyHtml' => '<p>hi</p>'];

		$result = $this->service->sendOneDelivery($delivery, $template, $transport);

		$this->assertFalse($result);
		$this->assertSame('failed', $this->objectService->store['d-ma']['status']);
	}//end testSendOneDeliveryDispatchesToMailAccountTransportDegradesSoftWhenMailAppAbsent()

	/**
	 * sendOneDelivery() against a `provider`-kind transport dispatches
	 * through the OpenConnector CallService and stores the returned
	 * providerId.
	 *
	 * @return void
	 */
	public function testSendOneDeliveryDispatchesToConnectorSourceTransport(): void {
		$transport = [
			'uuid' => 't-provider',
			'kind' => 'provider',
			'provider' => 'sendgrid',
			'connectorSourceId' => 'oc-source-2',
			'dailyLimit' => 0,
			'sentToday' => 0,
		];
		$this->objectService->store['t-provider'] = $transport;
		$this->objectService->store['oc-source-2'] = ['uuid' => 'oc-source-2'];

		$callService = new class {
			/** @var array<int, array<string, mixed>> */
			public array $calls = [];

			public function call(array|object $source, string $endpoint, string $method, array $config): object {
				$this->calls[] = $config['json'];
				return new class {
					public function getObject(): array {
						return [
							'statusCode' => 200,
							'response' => ['statusCode' => 200, 'body' => json_encode(['providerId' => 'p-1']), 'encoding' => 'UTF-8'],
						];
					}
				};
			}
		};

		$objectService = $this->objectService;
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturnCallback(
			function (string $id) use ($callService, $objectService) {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $objectService;
				}

				if ($id === 'OCA\\OpenConnector\\Service\\CallService') {
					return $callService;
				}

				throw new \RuntimeException('not registered: ' . $id);
			}
		);
		$this->service = new MailTransportService(
			$this->container,
			$this->appConfig,
			$this->mailer,
			new ArticleService($this->makeArticleStore()),
			$this->logger,
		);

		$delivery = ['uuid' => 'd-prov', 'email' => 'user@example.com'];
		$template = ['subject' => 'Hi', 'bodyHtml' => '<p>hi</p>'];

		$result = $this->service->sendOneDelivery($delivery, $template, $transport);

		$this->assertTrue($result);
		$this->assertSame('p-1', $this->objectService->store['d-prov']['providerId']);
		$this->assertSame('sent', $this->objectService->store['d-prov']['status']);
	}//end testSendOneDeliveryDispatchesToConnectorSourceTransport()

	/**
	 * buildRenderedMail() expands a template's `{{articles}}` marker in both
	 * the HTML and the text body before dispatch.
	 *
	 * ArticleServiceTest proves the renderer produces the right block. That
	 * says nothing about whether this class ever calls it: a template key
	 * that is read nowhere renders a body still carrying the marker, and
	 * every renderer test stays green. This asserts the wire, from a stored
	 * template with `articleIds` to the JSON body the connector receives —
	 * porting the coverage `BlastService::renderTemplate()` used to carry
	 * before marketing-mail-transports moved rendering into this class.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/marketing-article-hub/specs/marketing-blast/spec.md#requirement-a-campaign-template-may-embed-articles
	 */
	public function testSendOneDeliveryExpandsTheArticlesMarkerInBothBodies(): void {
		$transport = [
			'uuid' => 't-articles',
			'kind' => 'provider',
			'provider' => 'sendgrid',
			'connectorSourceId' => 'oc-source-articles',
			'dailyLimit' => 0,
			'sentToday' => 0,
		];
		$this->objectService->store['t-articles'] = $transport;
		$this->objectService->store['oc-source-articles'] = ['uuid' => 'oc-source-articles'];

		$callService = new class {
			/** @var array<int, array<string, mixed>> */
			public array $calls = [];

			public function call(array|object $source, string $endpoint, string $method, array $config): object {
				$this->calls[] = $config['json'];
				return new class {
					public function getObject(): array {
						return [
							'statusCode' => 200,
							'response' => ['statusCode' => 200, 'body' => json_encode(['providerId' => 'p-articles']), 'encoding' => 'UTF-8'],
						];
					}
				};
			}
		};

		$articleStore = $this->makeArticleStore();
		$articleStore->save(
			schemaSlug: 'article',
			payload: [
				'title' => 'OpenRegister 3.0 is uit',
				'summary' => 'Wat er verandert en wat je moet doen.',
				'language' => 'nl',
				'portalPageRef' => 'https://example.org/nieuws/openregister-3-0',
			],
			id: 'article-1',
		);

		$objectService = $this->objectService;
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturnCallback(
			function (string $id) use ($callService, $objectService) {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $objectService;
				}

				if ($id === 'OCA\\OpenConnector\\Service\\CallService') {
					return $callService;
				}

				throw new \RuntimeException('not registered: ' . $id);
			}
		);
		$this->service = new MailTransportService(
			$this->container,
			$this->appConfig,
			$this->mailer,
			new ArticleService($articleStore),
			$this->logger,
		);

		$delivery = ['uuid' => 'd-articles', 'email' => 'c1@example.test'];
		$template = [
			'subject' => 'Nieuwsbrief',
			'bodyHtml' => '<p>Hallo</p>{{articles}}',
			'bodyText' => "Hallo\n\n{{articles}}",
			'articleIds' => ['article-1'],
		];

		$result = $this->service->sendOneDelivery($delivery, $template, $transport);

		$this->assertTrue($result);
		$this->assertCount(1, $callService->calls);
		$rendered = $callService->calls[0];
		$this->assertStringNotContainsString('{{articles}}', $rendered['bodyHtml']);
		$this->assertStringNotContainsString('{{articles}}', $rendered['bodyText']);
		$this->assertStringContainsString('<h2>OpenRegister 3.0 is uit</h2>', $rendered['bodyHtml']);
		$this->assertStringContainsString('Lees verder', $rendered['bodyHtml']);
		$this->assertStringContainsString('OpenRegister 3.0 is uit', $rendered['bodyText']);
	}//end testSendOneDeliveryExpandsTheArticlesMarkerInBothBodies()

	/**
	 * A template with no `{{articles}}` marker and no articleIds renders
	 * byte-identical: a template written before this feature sends exactly
	 * as it did.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/marketing-article-hub/specs/marketing-blast/spec.md#requirement-a-campaign-template-may-embed-articles
	 */
	public function testSendOneDeliveryLeavesATemplateWithNoMarkerUnchanged(): void {
		$transport = ['uuid' => 't-no-articles', 'kind' => 'instance', 'dailyLimit' => 0, 'sentToday' => 0];
		$this->objectService->store['t-no-articles'] = $transport;

		$message = $this->createMock(IMessage::class);
		$message->method('setFrom')->willReturn($message);
		$message->method('setTo')->willReturn($message);
		$message->method('setReplyTo')->willReturn($message);
		$message->method('setSubject')->willReturn($message);
		$html = null;
		$message->method('setHtmlBody')->willReturnCallback(
			function (string $value) use ($message, &$html) {
				$html = $value;
				return $message;
			}
		);
		$this->mailer->method('createMessage')->willReturn($message);
		$this->mailer->method('send')->willReturn([]);

		$delivery = ['uuid' => 'd-no-articles', 'email' => 'user@example.com'];
		$template = ['subject' => 'Hi', 'bodyHtml' => '<p>hi, no marker here</p>'];

		$result = $this->service->sendOneDelivery($delivery, $template, $transport);

		$this->assertTrue($result);
		$this->assertSame('<p>hi, no marker here</p>', $html);
	}//end testSendOneDeliveryLeavesATemplateWithNoMarkerUnchanged()

	/**
	 * Build an in-memory {@see ListObjectStore} double for {@see ArticleService}.
	 *
	 * @return ListObjectStore
	 */
	private function makeArticleStore(): ListObjectStore {
		return new class(
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class),
		) extends ListObjectStore {
			/** @var array<string, array<string, array<string, mixed>>> */
			public array $rows = [];

			/**
			 * @param string $configKey Ignored.
			 * @param string $default The slug.
			 * @return string The slug.
			 */
			public function schemaSlug(string $configKey, string $default): string {
				return $default;
			}

			/**
			 * @param string $schemaSlug The schema.
			 * @param string $id The id.
			 * @return array<string, mixed>|null The row.
			 */
			public function find(string $schemaSlug, string $id): ?array {
				return ($this->rows[$schemaSlug][$id] ?? null);
			}

			/**
			 * @param string $schemaSlug The schema.
			 * @param array<string, string> $filters Field-value pairs.
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function findAll(string $schemaSlug, array $filters = []): array {
				return array_values(($this->rows[$schemaSlug] ?? []));
			}

			/**
			 * @param string $schemaSlug The schema.
			 * @param array<string, mixed> $payload The payload.
			 * @param string|null $id Existing id.
			 * @return array<string, mixed>|null The saved row.
			 */
			public function save(string $schemaSlug, array $payload, ?string $id = null): ?array {
				$payload['id'] = (string)$id;
				$this->rows[$schemaSlug][(string)$id] = $payload;
				return $payload;
			}

			/**
			 * @param array<string, mixed>|null $payload The row.
			 * @return string The id.
			 */
			public function idOf(?array $payload): string {
				return (string)($payload['id'] ?? '');
			}
		};
	}//end makeArticleStore()
}//end class
