<?php

/**
 * Unit tests for ConnectorSourceTransport.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Marketing\Transport
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-a-provider-transport-never-carries-a-credential
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing\Transport;

use OCA\Pipelinq\Service\Marketing\Transport\ConnectorSourceTransport;
use OCA\Pipelinq\Service\Marketing\Transport\RenderedMail;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for ConnectorSourceTransport — per-provider request shaping and the
 * fail-closed paths (missing source, missing CallService, non-2xx response).
 *
 * @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-a-provider-transport-never-carries-a-credential
 */
class ConnectorSourceTransportTest extends TestCase {
	private LoggerInterface $logger;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Build a minimal RenderedMail.
	 *
	 * @return RenderedMail
	 */
	private function mail(): RenderedMail {
		return new RenderedMail(
			fromEmail: 'noreply@example.test',
			fromName: 'Pipelinq',
			replyTo: 'reply@example.test',
			toEmail: 'user@example.test',
			subject: 'Hi',
			html: '<p>hi</p>',
			text: 'hi',
			headers: [],
			deliveryId: 'd-1',
		);
	}//end mail()

	/**
	 * Build a container resolving ObjectService (with one source) and a
	 * request-capturing fake CallService.
	 *
	 * @param object $captureTarget An object exposing a public `$lastJson` property.
	 *
	 * @return ContainerInterface
	 */
	private function buildContainer(object $captureTarget): ContainerInterface {
		$objectService = new class {
			public function find(string $id, $register = null, $schema = null): array {
				return ['uuid' => $id];
			}//end find()
		};

		$callService = new class ($captureTarget) {
			public function __construct(private object $captureTarget) {
			}//end __construct()

			public function call(array|object $source, string $endpoint, string $method, array $config): object {
				$this->captureTarget->lastJson = $config['json'];
				return new class {
					public function getObject(): array {
						return ['statusCode' => 200, 'response' => ['statusCode' => 200, 'body' => '{}', 'encoding' => 'UTF-8']];
					}
				};
			}//end call()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($objectService, $callService) {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $objectService;
				}

				if ($id === 'OCA\\OpenConnector\\Service\\CallService') {
					return $callService;
				}

				throw new \RuntimeException('not registered: ' . $id);
			}
		);
		return $container;
	}//end buildContainer()

	/**
	 * `sendgrid` (and any unrecognised/empty provider) reproduces the exact
	 * pre-existing generic body shape — the compatibility guarantee.
	 *
	 * @return void
	 */
	public function testSendGridBodyIsUnchangedFromBeforeThisChange(): void {
		$capture = new class {
			public array $lastJson = [];
		};
		$transport = new ConnectorSourceTransport($this->buildContainer($capture), $this->logger, 'oc-1', 'sendgrid');

		$transport->send($this->mail());

		$this->assertSame([
			'to' => 'user@example.test',
			'subject' => 'Hi',
			'bodyHtml' => '<p>hi</p>',
			'bodyText' => 'hi',
			'senderName' => 'Pipelinq',
			'senderEmail' => 'noreply@example.test',
			'replyTo' => 'reply@example.test',
		], $capture->lastJson);
	}//end testSendGridBodyIsUnchangedFromBeforeThisChange()

	/**
	 * An empty/unknown provider name also falls back to the SendGrid shape
	 * (legacy transports created before the `provider` field existed).
	 *
	 * @return void
	 */
	public function testUnknownProviderFallsBackToSendGridShape(): void {
		$capture = new class {
			public array $lastJson = [];
		};
		$transport = new ConnectorSourceTransport($this->buildContainer($capture), $this->logger, 'oc-1', '');

		$transport->send($this->mail());

		$this->assertArrayHasKey('bodyHtml', $capture->lastJson);
		$this->assertArrayNotHasKey('Content', $capture->lastJson);
	}//end testUnknownProviderFallsBackToSendGridShape()

	/**
	 * `ses` shapes the body as an SES v2 SendEmail request.
	 *
	 * @return void
	 */
	public function testSesBodyShape(): void {
		$capture = new class {
			public array $lastJson = [];
		};
		$transport = new ConnectorSourceTransport($this->buildContainer($capture), $this->logger, 'oc-1', 'ses');

		$transport->send($this->mail());

		$this->assertSame(['user@example.test'], $capture->lastJson['Destination']['ToAddresses']);
		$this->assertSame('Hi', $capture->lastJson['Content']['Simple']['Subject']['Data']);
		$this->assertSame('<p>hi</p>', $capture->lastJson['Content']['Simple']['Body']['Html']['Data']);
	}//end testSesBodyShape()

	/**
	 * `brevo` shapes the body as a transactional email API request.
	 *
	 * @return void
	 */
	public function testBrevoBodyShape(): void {
		$capture = new class {
			public array $lastJson = [];
		};
		$transport = new ConnectorSourceTransport($this->buildContainer($capture), $this->logger, 'oc-1', 'brevo');

		$transport->send($this->mail());

		$this->assertSame('noreply@example.test', $capture->lastJson['sender']['email']);
		$this->assertSame([['email' => 'user@example.test']], $capture->lastJson['to']);
		$this->assertSame('<p>hi</p>', $capture->lastJson['htmlContent']);
	}//end testBrevoBodyShape()

	/**
	 * `mailjet` shapes the body as a Send API v3.1 `Messages[]` request.
	 *
	 * @return void
	 */
	public function testMailjetBodyShape(): void {
		$capture = new class {
			public array $lastJson = [];
		};
		$transport = new ConnectorSourceTransport($this->buildContainer($capture), $this->logger, 'oc-1', 'mailjet');

		$transport->send($this->mail());

		$this->assertSame('user@example.test', $capture->lastJson['Messages'][0]['To'][0]['Email']);
		$this->assertSame('Hi', $capture->lastJson['Messages'][0]['Subject']);
	}//end testMailjetBodyShape()

	/**
	 * `mailgun` shapes the body as the `messages` endpoint's form fields.
	 *
	 * @return void
	 */
	public function testMailgunBodyShape(): void {
		$capture = new class {
			public array $lastJson = [];
		};
		$transport = new ConnectorSourceTransport($this->buildContainer($capture), $this->logger, 'oc-1', 'mailgun');

		$transport->send($this->mail());

		$this->assertSame('user@example.test', $capture->lastJson['to']);
		$this->assertSame('<p>hi</p>', $capture->lastJson['html']);
		$this->assertSame('reply@example.test', $capture->lastJson['h:Reply-To']);
	}//end testMailgunBodyShape()

	/**
	 * `postmark` shapes the body as the `email` endpoint's fields.
	 *
	 * @return void
	 */
	public function testPostmarkBodyShape(): void {
		$capture = new class {
			public array $lastJson = [];
		};
		$transport = new ConnectorSourceTransport($this->buildContainer($capture), $this->logger, 'oc-1', 'postmark');

		$transport->send($this->mail());

		$this->assertSame('user@example.test', $capture->lastJson['To']);
		$this->assertSame('<p>hi</p>', $capture->lastJson['HtmlBody']);
	}//end testPostmarkBodyShape()

	/**
	 * No `connectorSourceId` fails closed without touching the container.
	 *
	 * @return void
	 */
	public function testSendFailsClosedWithNoConnectorSourceId(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');
		$transport = new ConnectorSourceTransport($container, $this->logger, '', 'sendgrid');

		$result = $transport->send($this->mail());

		$this->assertFalse($result->accepted);
	}//end testSendFailsClosedWithNoConnectorSourceId()

	/**
	 * A source that does not resolve fails closed.
	 *
	 * @return void
	 */
	public function testSendFailsClosedWhenSourceNotFound(): void {
		$objectService = new class {
			public function find(string $id, $register = null, $schema = null): ?array {
				return null;
			}//end find()
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($objectService) {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $objectService;
				}

				throw new \RuntimeException('not registered: ' . $id);
			}
		);
		$transport = new ConnectorSourceTransport($container, $this->logger, 'oc-missing', 'sendgrid');

		$result = $transport->send($this->mail());

		$this->assertFalse($result->accepted);
	}//end testSendFailsClosedWhenSourceNotFound()
}//end class
