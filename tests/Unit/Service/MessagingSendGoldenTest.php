<?php

/**
 * Golden test for MessagingService::send and resend.
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
 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-004-server-side-send-endpoint
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ChannelProviderRepository;
use OCA\Pipelinq\Service\ConsentService;
use OCA\Pipelinq\Service\Messaging\MessageContent;
use OCA\Pipelinq\Service\Messaging\MessageRoute;
use OCA\Pipelinq\Service\MessagingService;
use OCA\Pipelinq\Service\SmsAdapter;
use OCA\Pipelinq\Service\WhatsAppAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Every outcome send() and resend() give, and every adapter call they make
 * (by parameter name), compared with MessagingSendGolden.json. Set
 * MESSAGING_SEND_GOLDEN_RECORD=1 to rewrite the file, then run once more
 * without it.
 *
 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-004-server-side-send-endpoint
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class MessagingSendGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/MessagingSendGolden.json';

	/**
	 * Adapter calls made during the current scenario.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * Run every scenario and compare it with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$actual = [];
		foreach ($this->scenarios() as $name => $scenario) {
			$actual[$name] = $this->runScenario(scenario: $scenario);
		}

		$json = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n";
		if (getenv('MESSAGING_SEND_GOLDEN_RECORD') !== false) {
			file_put_contents(self::GOLDEN, $json);
			$this->markTestSkipped('recorded');
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		foreach ($expected as $name => $want) {
			$this->assertSame($want, ($actual[$name] ?? null), $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testGolden()

	/**
	 * The scenarios: the call, its arguments and the adapter's answer.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function scenarios(): array {
		$contact = ['uuid' => 'c-1', 'phoneNumber' => '+31611111111', 'client' => 'cl-contact'];
		$bare = ['uuid' => 'c-2', 'phoneNumber' => '+31622222222'];
		$sent = ['status' => 'sent', 'messageId' => 'm-new', 'vendor' => 'messagebird'];
		$sms = ['contact' => $contact, 'channel' => 'sms', 'body' => 'hallo', 'templateId' => null, 'parameters' => [], 'providerHint' => null, 'actor' => 'agent-1'];
		$wa = ['contact' => $contact, 'channel' => 'whatsapp', 'body' => 'hoi', 'templateId' => null, 'parameters' => [], 'providerHint' => null, 'actor' => 'agent-2'];
		return [
			'sms: client from the contact'              => ['call' => 'send', 'args' => $sms, 'answer' => $sent],
			'sms: explicit client wins'                 => ['call' => 'send', 'args' => [...$sms, 'clientId' => 'cl-explicit'], 'answer' => $sent],
			'sms: no client anywhere'                   => ['call' => 'send', 'args' => [...$sms, 'contact' => $bare], 'answer' => $sent],
			'sms: reply with a pinned vendor'           => ['call' => 'send', 'args' => [...$sms, 'providerHint' => 'cmcom', 'inReplyTo' => 'in-1'], 'answer' => $sent],
			'sms: template fields are not passed'       => ['call' => 'send', 'args' => [...$sms, 'templateId' => 'tpl-x', 'parameters' => ['a']], 'answer' => ['status' => 'failed', 'error' => 'HTTP 500']],
			'sms: consent missing'                      => ['call' => 'send', 'args' => $sms, 'answer' => ['status' => 'consentMissing']],
			'sms: budget exceeded'                      => ['call' => 'send', 'args' => $sms, 'answer' => ['status' => 'budgetExceeded']],
			'sms: no provider'                          => ['call' => 'send', 'args' => $sms, 'answer' => ['status' => 'noProviderAvailable']],
			'sms: sent without a message id'            => ['call' => 'send', 'args' => $sms, 'answer' => ['status' => 'sent']],
			'whatsapp: text in the window'              => ['call' => 'send', 'args' => $wa, 'answer' => $sent],
			'whatsapp: hint is not passed, reply kept'  => ['call' => 'send', 'args' => [...$wa, 'providerHint' => 'bsp', 'inReplyTo' => 'in-2', 'clientId' => 'cl-w'], 'answer' => $sent],
			'whatsapp: template with parameters'        => ['call' => 'send', 'args' => [...$wa, 'body' => '', 'templateId' => 'tpl-1', 'parameters' => ['Jan', '12:00']], 'answer' => ['status' => 'templateParameterMismatch']],
			'whatsapp: window expired'                  => ['call' => 'send', 'args' => $wa, 'answer' => ['status' => 'sessionWindowExpired']],
			'whatsapp: template not approved'           => ['call' => 'send', 'args' => $wa, 'answer' => ['status' => 'templateNotApproved']],
			'whatsapp: template not found'              => ['call' => 'send', 'args' => $wa, 'answer' => ['status' => 'templateNotFound']],
			'other channel: nothing is sent'            => ['call' => 'send', 'args' => [...$sms, 'channel' => 'email'], 'answer' => $sent],
			'empty channel: nothing is sent'            => ['call' => 'send', 'args' => [...$sms, 'channel' => ''], 'answer' => $sent],
			'resend: sms text'                          => ['call' => 'resend', 'args' => ['message' => ['id' => 'm-old', 'channel' => 'sms', 'body' => 'opnieuw', 'templateId' => 'ignored'], 'contact' => $contact, 'actor' => 'agent-3'], 'answer' => $sent],
			'resend: whatsapp template'                 => ['call' => 'resend', 'args' => ['message' => ['id' => 'm-old', 'channel' => 'whatsapp', 'body' => 'x', 'templateId' => 'tpl-2', 'templateParameters' => [1, 'b']], 'contact' => $bare, 'actor' => 'agent-4'], 'answer' => ['status' => 'failed']],
			'resend: whatsapp text'                     => ['call' => 'resend', 'args' => ['message' => ['id' => 'm-old', 'channel' => 'whatsapp', 'body' => 'tekst'], 'contact' => $contact, 'actor' => 'agent-5'], 'answer' => $sent],
		];
	}//end scenarios()

	/**
	 * Run one scenario against a fresh service.
	 *
	 * @param array<string, mixed> $scenario The scenario.
	 *
	 * @return array<string, mixed>
	 */
	private function runScenario(array $scenario): array {
		$this->calls = [];
		$sms = $this->createMock(SmsAdapter::class);
		$sms->method('send')->willReturnCallback($this->recorder(adapter: 'sms', answer: $scenario['answer']));
		$whatsApp = $this->createMock(WhatsAppAdapter::class);
		$whatsApp->method('send')->willReturnCallback($this->recorder(adapter: 'whatsapp', answer: $scenario['answer']));

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				return $default;
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no OpenRegister'));

		$service = new MessagingService(
			container: $container,
			appConfig: $appConfig,
			providerRepo: $this->createMock(ChannelProviderRepository::class),
			smsAdapter: $sms,
			whatsAppAdapter: $whatsApp,
			consentService: $this->createMock(ConsentService::class),
			logger: $this->createMock(LoggerInterface::class),
		);

		if ($scenario['call'] === 'resend') {
			$outcome = $service->resend(...$scenario['args']);
		} else {
			$outcome = $this->send(service: $service, args: $scenario['args']);
		}

		return ['outcome' => $outcome, 'calls' => $this->calls];
	}//end runScenario()

	/**
	 * Call send() with the scenario's arguments.
	 *
	 * @param MessagingService     $service The service.
	 * @param array<string, mixed> $args    The arguments by name.
	 *
	 * @return array<string, mixed>
	 */
	private function send(MessagingService $service, array $args): array {
		return $service->send(
			route: new MessageRoute(
				contact: $args['contact'],
				providerHint: $args['providerHint'],
				clientId: ($args['clientId'] ?? ''),
				inReplyTo: ($args['inReplyTo'] ?? ''),
			),
			content: new MessageContent(
				channel: $args['channel'],
				body: $args['body'],
				templateId: $args['templateId'],
				parameters: $args['parameters'],
			),
			actor: $args['actor'],
		);
	}//end send()

	/**
	 * A callback that records an adapter call by parameter name and answers.
	 *
	 * Arguments left at their default are not recorded.
	 *
	 * @param string               $adapter The adapter name.
	 * @param array<string, mixed> $answer  The adapter's answer.
	 *
	 * @return callable
	 */
	private function recorder(string $adapter, array $answer): callable {
		$class = SmsAdapter::class;
		if ($adapter === 'whatsapp') {
			$class = WhatsAppAdapter::class;
		}

		$params = (new ReflectionMethod($class, 'send'))->getParameters();
		return function (...$args) use ($adapter, $answer, $params): array {
			$named = [];
			foreach ($params as $i => $param) {
				if (array_key_exists($i, $args) === false) {
					continue;
				}

				if ($param->isDefaultValueAvailable() === true && $param->getDefaultValue() === $args[$i]) {
					continue;
				}

				$named[$param->getName()] = $args[$i];
			}

			$this->calls[] = ['adapter' => $adapter, 'args' => $named];
			return $answer;
		};
	}//end recorder()
}//end class
