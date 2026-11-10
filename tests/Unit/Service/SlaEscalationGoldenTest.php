<?php

/**
 * Golden test for SlaEngineService::executeEscalations() over every channel.
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
 * @spec openspec/specs/sla-engine-and-escalation/spec.md#requirement-escalation-chain-execution
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use OCA\Pipelinq\Service\BusinessHoursCalculator;
use OCA\Pipelinq\Service\HolidayCalendarService;
use OCA\Pipelinq\Service\SlaEngineService;
use OCA\Pipelinq\Service\SmsAdapter;
use OCA\Pipelinq\Service\WhatsAppAdapter;
use OCP\IAppConfig;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Every escalation channel and breach-event outcome of executeEscalations():
 * the result, and every collaborator call by parameter name, compared with
 * SlaEscalationGolden.json. Set SLA_ESCALATION_GOLDEN_RECORD=1 to rewrite the
 * file, then run once more without it.
 *
 * @spec openspec/specs/sla-engine-and-escalation/spec.md#requirement-escalation-chain-execution
 */
class SlaEscalationGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/SlaEscalationGolden.json';

	/**
	 * Calls made during the current scenario.
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
		if (getenv('SLA_ESCALATION_GOLDEN_RECORD') !== false) {
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
	 * The scenarios.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function scenarios(): array {
		$hot = [['kind' => 'response', 'consumedPercentage' => 0.9], ['kind' => 'resolution', 'consumedPercentage' => 0.85123456]];
		$nc = ['triggerAt' => 0.5, 'notify' => 'assignee', 'channel' => 'nextcloud-notification'];
		$sms = ['triggerAt' => 0.5, 'notify' => 'customer', 'channel' => 'sms'];
		$wa = ['triggerAt' => 0.5, 'notify' => 'customer', 'channel' => 'whatsapp'];
		return [
			'nc: three steps, two reached'        => ['chain' => [$nc, ['triggerAt' => 0.8, 'notify' => 'manager'], ['triggerAt' => 1.5, 'notify' => 'x']], 'targets' => $hot, 'config' => ['sla_actor_assignee' => 'alice', 'sla_actor_manager' => 'bob']],
			'nc: level already fired'             => ['chain' => [$nc, ['triggerAt' => 0.8, 'notify' => 'manager']], 'targets' => $hot, 'fired' => 1, 'config' => ['sla_actor_manager' => 'bob']],
			'nc: unresolved, entity uuid'         => ['chain' => [$nc], 'targets' => [['consumedPercentage' => 0.7]], 'save' => 'entity'],
			'nc: fallback actor, notify throws'   => ['chain' => [$nc], 'targets' => $hot, 'config' => ['sla_actor_fallback' => 'root'], 'notifyFails' => true, 'save' => 'id'],
			'nc: actor lookup throws'             => ['chain' => [['triggerAt' => 0.5, 'notify' => 'boom']], 'targets' => $hot],
			'nc: tie keeps the first kind'        => ['chain' => [$nc], 'targets' => [['kind' => 'response', 'consumedPercentage' => 0.9], ['kind' => 'resolution', 'consumedPercentage' => 0.9]], 'config' => ['sla_actor_assignee' => 'alice']],
			'nc: default trigger at 1.0'          => ['chain' => [['notify' => 'assignee']], 'targets' => [['kind' => 'response', 'consumedPercentage' => 0.9999999999]], 'config' => ['sla_actor_assignee' => 'alice']],
			'breach: not configured'              => ['chain' => [$nc], 'targets' => $hot, 'config' => ['sla_actor_assignee' => 'alice', 'sla_register' => '']],
			'breach: schema missing'              => ['chain' => [$nc], 'targets' => $hot, 'config' => ['sla_breach_event_schema' => '']],
			'breach: object service missing'      => ['chain' => [$nc], 'targets' => $hot, 'noObjects' => true],
			'breach: save throws'                 => ['chain' => [$nc], 'targets' => $hot, 'save' => 'throw'],
			'breach: save returns no id'          => ['chain' => [$nc], 'targets' => $hot, 'save' => 'empty'],
			'sms: sent to the linked contact'     => ['chain' => [$sms], 'targets' => $hot, 'sms' => 'sent'],
			'sms: client fallback, no consent'    => ['chain' => [$sms], 'targets' => $hot, 'sms' => 'consentMissing', 'target' => ['client' => 'client-1']],
			'sms: contact gone, client used'      => ['chain' => [$sms], 'targets' => $hot, 'sms' => 'sent', 'target' => ['contact' => 'gone', 'client' => 'client-1']],
			'sms: window expired'                 => ['chain' => [$sms], 'targets' => $hot, 'sms' => 'sessionWindowExpired'],
			'sms: other failure'                  => ['chain' => [$sms], 'targets' => $hot, 'sms' => 'noProviderAvailable'],
			'sms: contact without id'             => ['chain' => [$sms], 'targets' => $hot, 'sms' => 'sent', 'contact' => ['phoneNumber' => '+316', 'client' => 'client-1']],
			'sms: not for a team lead'            => ['chain' => [['triggerAt' => 0.5, 'notify' => 'team-lead', 'channel' => 'sms']], 'targets' => $hot, 'sms' => 'sent'],
			'sms: no customer'                    => ['chain' => [$sms], 'targets' => $hot, 'sms' => 'sent', 'target' => []],
			'sms: target schema override'         => ['chain' => [$sms], 'targets' => $hot, 'sms' => 'sent', 'config' => ['sla_target_schema_request' => 'ticket', 'contact_schema' => 'person', 'client_schema' => 'org']],
			'sms: no register for lookups'        => ['chain' => [$sms], 'targets' => $hot, 'sms' => 'sent', 'config' => ['register' => '']],
			'sms: adapter missing'                => ['chain' => [$sms], 'targets' => $hot],
			'sms: adapter without send'           => ['chain' => [$sms], 'targets' => $hot, 'sms' => 'nosend'],
			'sms: adapter throws'                 => ['chain' => [$sms], 'targets' => $hot, 'sms' => 'throw'],
			'whatsapp: template step'             => ['chain' => [[...$wa, 'templateId' => ' tpl-9 ']], 'targets' => $hot, 'wa' => 'sent'],
			'whatsapp: no template, in window'    => ['chain' => [$wa], 'targets' => $hot, 'wa' => 'sent', 'window' => true],
			'whatsapp: no template, out of window' => ['chain' => [$wa], 'targets' => $hot, 'wa' => 'sent', 'window' => false],
			'whatsapp: plain adapter, no template' => ['chain' => [$wa], 'targets' => $hot, 'wa' => 'plain'],
			'whatsapp: template, adapter throws'  => ['chain' => [[...$wa, 'templateId' => 'tpl-9']], 'targets' => $hot, 'wa' => 'throw'],
			'whatsapp: template, no consent'      => ['chain' => [[...$wa, 'templateId' => 'tpl-9']], 'targets' => $hot, 'wa' => 'consentMissing'],
			'whatsapp: adapter missing'           => ['chain' => [[...$wa, 'templateId' => 'tpl-9']], 'targets' => $hot],
			'email and webhook deferred'          => ['chain' => [['triggerAt' => 0.5, 'channel' => 'email'], ['triggerAt' => 0.6, 'channel' => 'webhook', 'notify' => 'manager']], 'targets' => $hot],
			'empty chain'                         => ['chain' => [], 'targets' => $hot, 'fired' => 2],
		];
	}//end scenarios()

	/**
	 * Run one scenario against a fresh engine.
	 *
	 * @param array<string, mixed> $scenario The scenario.
	 *
	 * @return array<string, mixed>
	 */
	private function runScenario(array $scenario): array {
		$this->calls = [];
		$engine = $this->engine(scenario: $scenario);
		$policy = ['id' => 'pol-1', 'name' => 'Gold', 'escalationChain' => $scenario['chain']];
		$result = $engine->executeEscalations(
			$policy,
			'request',
			'req-1',
			$scenario['targets'],
			[['kind' => 'prior']],
			($scenario['fired'] ?? 0),
		);

		$text = json_encode(['result' => $result, 'calls' => $this->calls]);
		$text = preg_replace('/\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[^"]*/', '<now>', $text);
		return json_decode($text, true);
	}//end runScenario()

	/**
	 * Build the engine with recording collaborators.
	 *
	 * @param array<string, mixed> $scenario The scenario.
	 *
	 * @return SlaEngineService
	 */
	private function engine(array $scenario): SlaEngineService {
		$config = [
			'register' => 'pipelinq',
			'sla_register' => 'sla',
			'sla_breach_event_schema' => 'slaBreachEvent',
			...($scenario['config'] ?? []),
		];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use ($config): string {
				if ($key === 'sla_actor_boom') {
					throw new RuntimeException('config down');
				}

				return ($config[$key] ?? $default);
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(function (...$args) use ($level) {
				$this->record(LoggerInterface::class, $level, $args);
			});
		}

		$services = $this->services(scenario: $scenario);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(function (string $id) use ($services) {
			$this->calls[] = ['container::get' => $id];
			if (isset($services[$id]) === true) {
				return $services[$id];
			}

			throw new RuntimeException('not registered: ' . $id);
		});

		$holidays = new HolidayCalendarService($appConfig, $logger);
		return new SlaEngineService(
			$holidays,
			new BusinessHoursCalculator($holidays, $appConfig, $logger),
			$container,
			$appConfig,
			$this->notifications(failNotify: ($scenario['notifyFails'] ?? false)),
			$logger,
		);
	}//end engine()

	/**
	 * The container's services for a scenario.
	 *
	 * @param array<string, mixed> $scenario The scenario.
	 *
	 * @return array<string, object>
	 */
	private function services(array $scenario): array {
		$services = [];
		if (($scenario['noObjects'] ?? false) === false) {
			$store = [
				'req-1' => ($scenario['target'] ?? ['contact' => 'contact-1', 'client' => 'client-1']),
				'contact-1' => ($scenario['contact'] ?? ['uuid' => 'contact-1', 'phoneNumber' => '+31611111111', 'client' => 'client-1']),
				'client-1' => ['id' => 'client-1', 'phoneNumber' => '+31622222222'],
			];
			$services['OCA\\OpenRegister\\Service\\ObjectService'] = $this->objectService(store: $store, save: ($scenario['save'] ?? 'uuid'));
		}

		if (isset($scenario['sms']) === true) {
			$services['OCA\\Pipelinq\\Service\\SmsAdapter'] = $this->adapter(class: SmsAdapter::class, mode: $scenario['sms'], window: false);
		}

		if (isset($scenario['wa']) === true) {
			$services['OCA\\Pipelinq\\Service\\WhatsAppAdapter'] = $this->adapter(class: WhatsAppAdapter::class, mode: $scenario['wa'], window: ($scenario['window'] ?? false));
		}

		return $services;
	}//end services()

	/**
	 * A recording messaging adapter.
	 *
	 * @param string $class  SmsAdapter or WhatsAppAdapter.
	 * @param string $mode   An outcome status, throw, nosend or plain.
	 * @param bool   $window The session-window answer.
	 *
	 * @return object
	 */
	private function adapter(string $class, string $mode, bool $window): object {
		if ($mode === 'nosend') {
			return new \stdClass();
		}

		if ($mode === 'plain') {
			return new class($this) {
				public function __construct(private SlaEscalationGoldenTest $test) {
				}

				public function send(array $contact = [], string $body = '', ?string $templateId = null, array $parameters = [], array $context = []): array {
					$this->test->note(['plain::send' => compact('contact', 'body', 'templateId', 'parameters', 'context')]);
					return ['status' => 'sent'];
				}
			};
		}

		$adapter = $this->createMock($class);
		$adapter->method('send')->willReturnCallback(function (...$args) use ($class, $mode) {
			$this->record($class, 'send', $args);
			if ($mode === 'throw') {
				throw new RuntimeException('provider blew up');
			}

			return ['status' => $mode, 'messageId' => 'msg-1'];
		});
		if ($class === WhatsAppAdapter::class) {
			$adapter->method('isWithinSessionWindow')->willReturnCallback(function (...$args) use ($window) {
				$this->record(WhatsAppAdapter::class, 'isWithinSessionWindow', $args);
				return $window;
			});
		}

		return $adapter;
	}//end adapter()

	/**
	 * A recording notification manager.
	 *
	 * @param bool $failNotify Whether notify() throws.
	 *
	 * @return INotificationManager
	 */
	private function notifications(bool $failNotify): INotificationManager {
		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject', 'setMessage'] as $setter) {
			$notification->method($setter)->willReturnCallback(function (...$args) use ($setter, $notification) {
				if ($setter !== 'setDateTime') {
					$this->record(INotification::class, $setter, $args);
				}

				return $notification;
			});
		}

		$manager = $this->createMock(INotificationManager::class);
		$manager->method('createNotification')->willReturn($notification);
		$manager->method('notify')->willReturnCallback(function () use ($failNotify) {
			$this->calls[] = ['INotificationManager::notify' => true];
			if ($failNotify === true) {
				throw new RuntimeException('notify down');
			}
		});
		return $manager;
	}//end notifications()

	/**
	 * A recording object service.
	 *
	 * @param array<string, array<string, mixed>> $store Objects by id.
	 * @param string                              $save  uuid, id, entity, empty or throw.
	 *
	 * @return object
	 */
	private function objectService(array $store, string $save): object {
		return new class($this, $store, $save) {
			private int $count = 0;

			public function __construct(private SlaEscalationGoldenTest $test, private array $store, private string $save) {
			}

			public function find($id, $register = null, $schema = null): ?array {
				$this->test->note(['find' => compact('id', 'register', 'schema')]);
				return ($this->store[$id] ?? null);
			}

			public function saveObject(array $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true, bool $_multitenancy = true): mixed {
				$extend = ($options->extend ?? []);
				$register = $options->target->register;
				$schema = $options->target->schema;
				$this->test->note(['saveObject' => compact('object', 'extend', 'register', 'schema')]);
				$this->count++;
				return match ($this->save) {
					'throw' => throw new RuntimeException('save down'),
					'empty' => [],
					'id' => ['id' => 'ev-id-' . $this->count],
					'entity' => new class('ev-entity-' . $this->count) {
						public function __construct(private string $uuid) {
						}

						public function getUuid(): string {
							return $this->uuid;
						}
					},
					default => ['uuid' => 'ev-' . $this->count],
				};
			}
		};
	}//end objectService()

	/**
	 * Record a call from a fake that has no reflection target.
	 *
	 * @param array<string, mixed> $call The call.
	 *
	 * @return void
	 */
	public function note(array $call): void {
		$this->calls[] = $call;
	}//end note()

	/**
	 * Record a mocked call by parameter name, leaving out arguments at their default.
	 *
	 * @param string            $class  The mocked class.
	 * @param string            $method The method.
	 * @param array<int, mixed> $args   The arguments as passed.
	 *
	 * @return void
	 */
	private function record(string $class, string $method, array $args): void {
		$named = [];
		foreach ((new ReflectionMethod($class, $method))->getParameters() as $i => $param) {
			if (array_key_exists($i, $args) === false) {
				continue;
			}

			if ($param->isDefaultValueAvailable() === true && $param->getDefaultValue() === $args[$i]) {
				continue;
			}

			$named[$param->getName()] = $args[$i];
		}

		$this->calls[] = [substr(strrchr($class, '\\'), 1) . '::' . $method => $named];
	}//end record()
}//end class
