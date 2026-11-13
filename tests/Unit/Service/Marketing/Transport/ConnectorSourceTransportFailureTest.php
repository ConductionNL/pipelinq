<?php

/**
 * ConnectorSourceTransport: every fail-closed result, the provider id read
 * from the call log, and address formatting without optional parts.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Marketing\Transport
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

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing\Transport;

use OCA\Pipelinq\Service\Marketing\Transport\ConnectorSourceTransport;
use OCA\Pipelinq\Service\Marketing\Transport\MailAddressing;
use OCA\Pipelinq\Service\Marketing\Transport\MailContent;
use OCA\Pipelinq\Service\Marketing\Transport\RenderedMail;
use OCA\Pipelinq\Service\Marketing\Transport\SendResult;
use OCA\Pipelinq\Tests\Unit\Support\FakeSlugResolver;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A send that cannot reach its provider is refused with the reason, never
 * thrown; an accepted send carries the provider's message id when it gave one.
 *
 * @covers \OCA\Pipelinq\Service\Marketing\Transport\ConnectorSourceTransport
 * @uses   \OCA\Pipelinq\Service\Marketing\Transport\MailAddressing
 * @uses   \OCA\Pipelinq\Service\Marketing\Transport\MailContent
 * @uses   \OCA\Pipelinq\Service\Marketing\Transport\RenderedMail
 * @uses   \OCA\Pipelinq\Service\Marketing\Transport\SendResult
 * @uses   \OCA\Pipelinq\Service\ConnectorSourceRegister
 * @uses   \OCA\Pipelinq\Support\FleetAppId
 */
class ConnectorSourceTransportFailureTest extends TestCase {

	/**
	 * Warnings and errors logged, as [level, message].
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $logged = [];

	/**
	 * The JSON body of the last call.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $lastJson = null;

	/**
	 * Without a connector source id nothing is looked up.
	 *
	 * @return void
	 */
	public function testNoConnectorSource(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$result = $this->transport(container: $container, sourceId: '')->send($this->mail());

		$this->assertRefused('no-connector-source', $result);
		$this->assertSame([['warning', 'ConnectorSourceTransport.send: no connectorSourceId']], $this->logged);
	}//end testNoConnectorSource()

	/**
	 * A register that is not on this instance is told apart from a missing source.
	 *
	 * @return void
	 */
	public function testRegisterAbsent(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$result = $this->transport(container: $container, present: [])->send($this->mail());

		$this->assertRefused('connector-register-absent', $result);
	}//end testRegisterAbsent()

	/**
	 * No object service, a failing lookup and an absent source all read as a missing source.
	 *
	 * @return void
	 */
	public function testSourceNotFound(): void {
		$cases = [
			'no object service' => [null, 'ConnectorSourceTransport.resolveObjectService: unavailable'],
			'lookup throws' => [$this->objectService(source: new RuntimeException('db')), 'ConnectorSourceTransport.resolveSource: lookup failed'],
			'no such source' => [$this->objectService(source: null), 'ConnectorSourceTransport.resolveSource: connector source not found'],
			'not a source' => [$this->objectService(source: 'text'), 'ConnectorSourceTransport.resolveSource: connector source not found'],
		];

		foreach ($cases as $label => [$objectService, $warning]) {
			$this->logged = [];
			$result = $this->transport(container: $this->container(objectService: $objectService, callService: $this->callService(log: []))
			)->send($this->mail());

			$this->assertRefused('connector-source-not-found', $result, $label);
			$this->assertSame([['warning', $warning]], $this->logged, $label);
			$this->assertNull($this->lastJson, $label);
		}
	}//end testSourceNotFound()

	/**
	 * A missing call service, or one without call(), is logged as an error and refuses the send.
	 *
	 * @return void
	 */
	public function testCallServiceUnavailable(): void {
		$withoutCall = new class {
		};

		foreach ([[null, 'ConnectorSourceTransport.resolveCallService: unavailable'], [$withoutCall, 'ConnectorSourceTransport.resolveCallService: CallService lacks call()']] as [$callService, $error]) {
			$this->logged = [];
			$result = $this->transport(container: $this->container(objectService: $this->objectService(source: ['uuid' => 'oc-1']), callService: $callService))->send($this->mail());

			$this->assertRefused('call-service-unavailable', $result);
			$this->assertContains(['error', $error], $this->logged);
		}
	}//end testCallServiceUnavailable()

	/**
	 * A call that throws is logged and refused.
	 *
	 * @return void
	 */
	public function testCallFailure(): void {
		$result = $this->transport(container: $this->container(objectService: $this->objectService(source: ['uuid' => 'oc-1']), callService: $this->callService(log: new RuntimeException('timeout'))))->send($this->mail());

		$this->assertRefused('connector-call-failed', $result);
		$this->assertSame([['warning', 'ConnectorSourceTransport.send: connector call failed']], $this->logged);
	}//end testCallFailure()

	/**
	 * A call log that is not readable has no status, so it is a non-2xx response.
	 *
	 * @return void
	 */
	public function testUnreadableCallLogIsNon2xx(): void {
		$result = $this->send(log: 'not a call log');

		$this->assertRefused('non-2xx-response', $result);
	}//end testUnreadableCallLogIsNon2xx()

	/**
	 * The provider id comes from the first known key of a UTF-8 JSON body, read through jsonSerialize().
	 *
	 * @return void
	 */
	public function testProviderIdFromTheResponseBody(): void {
		$cases = [
			'messageId' => [['messageId' => 'm-1', 'id' => 'x'], 'm-1'],
			'MessageID' => [['MessageID' => 7], '7'],
			'empty values skipped' => [['providerId' => '', 'id' => 'i-1'], 'i-1'],
			'no known key' => [['other' => 'x'], null],
		];

		foreach ($cases as $label => [$body, $expected]) {
			$log = new class (['statusCode' => 202, 'response' => ['body' => json_encode($body), 'encoding' => 'UTF-8']]) implements \JsonSerializable {
				/**
				 * @param array<string, mixed> $data The call log.
				 */
				public function __construct(private array $data) {
				}

				/**
				 * @return array<string, mixed>
				 */
				public function jsonSerialize(): array {
					return $this->data;
				}
			};
			$result = $this->send(log: $log);

			$this->assertTrue($result->accepted, $label);
			$this->assertSame($expected, $result->providerId, $label);
			$this->assertNull($result->error, $label);
		}
	}//end testProviderIdFromTheResponseBody()

	/**
	 * An unusable response body is accepted without a provider id.
	 *
	 * @return void
	 */
	public function testUnusableResponseBodyHasNoProviderId(): void {
		$responses = [
			'no response' => null,
			'empty body' => ['body' => ''],
			'not utf-8' => ['body' => '{"id":"x"}', 'encoding' => 'ISO-8859-1'],
			'invalid json' => ['body' => '{nope'],
			'json scalar' => ['body' => '"x"'],
		];

		foreach ($responses as $label => $response) {
			$result = $this->send(log: ['statusCode' => 200, 'response' => $response]);

			$this->assertTrue($result->accepted, $label);
			$this->assertNull($result->providerId, $label);
		}
	}//end testUnusableResponseBodyHasNoProviderId()

	/**
	 * Without a sender name or reply-to, SES gets the bare address and no reply-to list.
	 *
	 * @return void
	 */
	public function testSesBodyWithoutOptionalAddressParts(): void {
		$this->send(log: ['statusCode' => 200], provider: 'ses', mail: $this->mail(fromName: '', replyTo: ''));

		$this->assertSame('noreply@example.test', $this->lastJson['FromEmailAddress']);
		$this->assertSame([], $this->lastJson['ReplyToAddresses']);
	}//end testSesBodyWithoutOptionalAddressParts()

	/**
	 * Quotes are stripped from a sender name before it is quoted.
	 *
	 * @return void
	 */
	public function testSenderNameQuotesAreStripped(): void {
		$this->send(log: ['statusCode' => 200], provider: 'postmark', mail: $this->mail(fromName: 'The "Best" Shop', replyTo: ''));

		$this->assertSame('"The Best Shop" <noreply@example.test>', $this->lastJson['From']);
		$this->assertArrayNotHasKey('ReplyTo', $this->lastJson);
	}//end testSenderNameQuotesAreStripped()

	/**
	 * Send one mail through a working source and a call service returning the given log.
	 *
	 * @param mixed            $log      The call log the call service returns.
	 * @param string           $provider The provider.
	 * @param RenderedMail|null $mail    The mail, or the default one.
	 *
	 * @return SendResult
	 */
	private function send(mixed $log, string $provider = 'sendgrid', ?RenderedMail $mail = null): SendResult {
		$container = $this->container(objectService: $this->objectService(source: ['uuid' => 'oc-1']), callService: $this->callService(log: $log));

		return $this->transport(container: $container, provider: $provider)->send($mail ?? $this->mail());
	}//end send()

	/**
	 * Assert a refused send with this error.
	 *
	 * @param string     $error  The expected error.
	 * @param SendResult $result The result.
	 * @param string     $label  The case.
	 *
	 * @return void
	 */
	private function assertRefused(string $error, SendResult $result, string $label = ''): void {
		$this->assertFalse($result->accepted, $label);
		$this->assertNull($result->providerId, $label);
		$this->assertSame($error, $result->error, $label);
	}//end assertRefused()

	/**
	 * The transport under test, with a logger that records warnings and errors.
	 *
	 * @param ContainerInterface $container The container.
	 * @param string             $sourceId  The connector source id.
	 * @param array<int, string> $present   The register slugs present on the instance.
	 * @param string             $provider  The provider.
	 *
	 * @return ConnectorSourceTransport
	 */
	private function transport(ContainerInterface $container, string $sourceId = 'oc-1', array $present = ['integriq'], string $provider = 'sendgrid'): ConnectorSourceTransport {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message): void {
			$this->logged[] = ['warning', $message];
		});
		$logger->method('error')->willReturnCallback(function (string $message): void {
			$this->logged[] = ['error', $message];
		});

		return new ConnectorSourceTransport($container, $logger, FakeSlugResolver::connectorRegister(present: $present), $sourceId, $provider);
	}//end transport()

	/**
	 * A container answering for the object service and the call service, throwing for anything else.
	 *
	 * @param object|null $objectService The object service, or null for none.
	 * @param object|null $callService   The call service, or null for none.
	 *
	 * @return ContainerInterface
	 */
	private function container(?object $objectService, ?object $callService): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objectService, $callService): object {
				$service = match ($id) {
					'OCA\\OpenRegister\\Service\\ObjectService' => $objectService,
					'OCA\\Integriq\\Service\\CallService' => $callService,
					default => null,
				};
				if ($service === null) {
					throw new RuntimeException('not registered: ' . $id);
				}

				return $service;
			}
		);

		return $container;
	}//end container()

	/**
	 * An object service whose find() returns, or throws, the given source.
	 *
	 * @param mixed $source The source, or a Throwable to throw.
	 *
	 * @return object
	 */
	private function objectService(mixed $source): object {
		return new class ($source) {
			/**
			 * @param mixed $source The source.
			 */
			public function __construct(private mixed $source) {
			}

			/**
			 * @param string $id       The id.
			 * @param mixed  $register The register.
			 * @param mixed  $schema   The schema.
			 *
			 * @return mixed
			 */
			public function find(string $id, $register = null, $schema = null): mixed {
				if ($this->source instanceof \Throwable) {
					throw $this->source;
				}

				return $this->source;
			}
		};
	}//end objectService()

	/**
	 * A call service that records the JSON body and returns, or throws, the given log.
	 *
	 * @param mixed $log The call log, or a Throwable to throw.
	 *
	 * @return object
	 */
	private function callService(mixed $log): object {
		$test = $this;
		return new class ($log, function (array $json) use ($test): void {
			$test->recordJson($json);
		}) {
			/**
			 * @param mixed    $log    The call log.
			 * @param \Closure $record Records the body.
			 */
			public function __construct(private mixed $log, private \Closure $record) {
			}

			/**
			 * @param array<string, mixed>|object $source   The source.
			 * @param string                      $endpoint The endpoint.
			 * @param string                      $method   The method.
			 * @param array<string, mixed>        $config   The request config.
			 *
			 * @return mixed
			 */
			public function call(array|object $source, string $endpoint, string $method, array $config): mixed {
				($this->record)($config['json']);
				if ($this->log instanceof \Throwable) {
					throw $this->log;
				}

				return $this->log;
			}
		};
	}//end callService()

	/**
	 * Record a request body.
	 *
	 * @param array<string, mixed> $json The body.
	 *
	 * @return void
	 */
	public function recordJson(array $json): void {
		$this->lastJson = $json;
	}//end recordJson()

	/**
	 * A rendered mail.
	 *
	 * @param string $fromName The sender name.
	 * @param string $replyTo  The reply-to address.
	 *
	 * @return RenderedMail
	 */
	private function mail(string $fromName = 'Pipelinq', string $replyTo = 'reply@example.test'): RenderedMail {
		return new RenderedMail(
			addressing: new MailAddressing(fromEmail: 'noreply@example.test', fromName: $fromName, replyTo: $replyTo, toEmail: 'user@example.test'),
			content: new MailContent(subject: 'Hi', html: '<p>hi</p>', text: 'hi'),
			headers: [],
			deliveryId: 'd-1',
		);
	}//end mail()
}//end class
