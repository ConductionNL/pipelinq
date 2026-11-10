<?php

/**
 * Golden test for WhatsAppAdapter::send() over its provider attempts.
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
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\Pipelinq\Service\BudgetService;
use OCA\Pipelinq\Service\ChannelProviderRepository;
use OCA\Pipelinq\Service\ConsentService;
use OCA\Pipelinq\Service\ContactmomentService;
use OCA\Pipelinq\Service\NotificationService;
use OCA\Pipelinq\Service\PhoneNormaliser;
use OCA\Pipelinq\Service\Provider\PermanentSmsProviderException;
use OCA\Pipelinq\Service\Provider\TransientSmsProviderException;
use OCA\Pipelinq\Service\WhatsAppAdapter;
use OCA\Pipelinq\Service\WhatsAppProviderClient;
use OCA\Pipelinq\Tests\Unit\Support\FakeMessagingAccount;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;
use TypeError;

/**
 * Every provider outcome of send(), for template and free-form sends: the
 * envelope, and every collaborator call by parameter name, compared with
 * WhatsAppAdapterSendGolden.json. Set WHATSAPP_SEND_GOLDEN_RECORD=1 to
 * rewrite the file, then run once more without it.
 *
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */
class WhatsAppAdapterSendGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/WhatsAppAdapterSendGolden.json';

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
		if (getenv('WHATSAPP_SEND_GOLDEN_RECORD') !== false) {
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
	 * The scenarios: providers as [uuid, vendor, budget ok, client outcome].
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function scenarios(): array {
		$mixed = [
			['p1', 'alpha', false, 'success'],
			['p2', 'beta', true, 'transient'],
			['p3', 'gamma', true, 'runtime'],
			['p4', 'delta', true, 'error'],
			['p5', 'eps', true, 'success'],
			['p6', 'zeta', true, 'success'],
		];
		$ctx = ['clientId' => 'cl-1', 'agent' => 'ann'];
		return [
			'template: failover through every miss'  => ['providers' => $mixed, 'template' => 'tpl-1', 'context' => $ctx],
			'free-form: failover through every miss' => ['providers' => $mixed, 'template' => null, 'context' => $ctx],
			'template: pinned provider first'        => ['providers' => [['p1', 'a', true, 'success'], ['p2', 'b', true, 'success']], 'template' => 'tpl-1', 'pin' => 'p2'],
			'template: all transient'                => ['providers' => [['p1', 'a', true, 'transient'], ['p2', 'b', true, 'runtime']], 'template' => 'tpl-1'],
			'free-form: all transient'               => ['providers' => [['p1', 'a', true, 'transient']], 'template' => null],
			'template: permanent stops failover'     => ['providers' => [['p1', 'a', true, 'permanent'], ['p2', 'b', true, 'success']], 'template' => 'tpl-1'],
			'free-form: permanent stops failover'    => ['providers' => [['p1', 'a', true, 'permanent'], ['p2', 'b', true, 'success']], 'template' => null],
			'permanent, notification fails'          => ['providers' => [['p1', 'a', true, 'permanent']], 'template' => null, 'notifyFails' => true],
			'all budgets spent'                      => ['providers' => [['p1', 'a', false, 'success'], ['p2', 'b', false, 'success']], 'template' => 'tpl-1'],
			'success, no audit service'              => ['providers' => [['p1', 'a', true, 'success']], 'template' => 'tpl-1', 'noAudit' => true],
			'success, save fails'                    => ['providers' => [['p1', 'a', true, 'success']], 'template' => null, 'saveFails' => true, 'context' => ['agent' => 7]],
			'no providers'                           => ['providers' => [], 'template' => 'tpl-1'],
			'consent refused'                        => ['providers' => [['p1', 'a', true, 'success']], 'template' => null, 'consent' => false],
		];
	}//end scenarios()

	/**
	 * Run one scenario against a fresh adapter.
	 *
	 * @param array<string, mixed> $scenario The scenario.
	 *
	 * @return array<string, mixed>
	 */
	private function runScenario(array $scenario): array {
		$this->calls = [];
		$rows = [];
		$outcomes = [];
		$budget = [];
		foreach ($scenario['providers'] as [$uuid, $vendor, $budgetOk, $outcome]) {
			$rows[] = ['uuid' => $uuid, 'kind' => 'whatsapp-cloud-api', 'vendor' => $vendor];
			$budget[$uuid] = $budgetOk;
			$outcomes[$uuid] = $outcome;
		}

		$adapter = $this->adapter(scenario: $scenario, world: ['rows' => $rows, 'outcomes' => $outcomes, 'budget' => $budget]);
		$parameters = [];
		if ($scenario['template'] !== null) {
			$parameters = ['Ann', '12'];
		}

		$result = $adapter->send(
			['uuid' => 'contact-1', 'phoneNumber' => '+31611111111'],
			'Hello there',
			$scenario['template'],
			$parameters,
			($scenario['context'] ?? []),
		);

		$text = json_encode(['result' => $result, 'calls' => $this->calls]);
		$text = preg_replace('/\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[^"]*/', '<now>', $text);
		return json_decode($text, true);
	}//end runScenario()

	/**
	 * Build the adapter with recording collaborators.
	 *
	 * @param array<string, mixed> $scenario The scenario.
	 * @param array<string, mixed> $world    Provider rows, client outcomes and budgets.
	 *
	 * @return WhatsAppAdapter
	 */
	private function adapter(array $scenario, array $world): WhatsAppAdapter {
		$repo = $this->createMock(ChannelProviderRepository::class);
		$repo->method('listActive')->willReturnCallback(function (...$args) use ($world) {
			$this->record(ChannelProviderRepository::class, 'listActive', $args);
			if ($args[0] === 'whatsapp-cloud-api') {
				return $world['rows'];
			}

			return [];
		});

		$client = $this->createMock(WhatsAppProviderClient::class);
		$client->method('sendTemplate')->willReturnCallback(function (...$args) use ($world) {
			$this->record(WhatsAppProviderClient::class, 'sendTemplate', $args);
			return $this->clientOutcome(row: $args[0], outcome: $world['outcomes'][$args[0]['uuid']]);
		});
		$client->method('sendFreeForm')->willReturnCallback(function (...$args) use ($world) {
			$this->record(WhatsAppProviderClient::class, 'sendFreeForm', $args);
			return $this->clientOutcome(row: $args[0], outcome: $world['outcomes'][$args[0]['uuid']]);
		});

		$consent = $this->createMock(ConsentService::class);
		$consent->method('canSend')->willReturnCallback(function (...$args) use ($scenario) {
			$this->record(ConsentService::class, 'canSend', $args);
			return ($scenario['consent'] ?? true);
		});
		$consent->method('canSendBusinessInitiated')->willReturnCallback(function (...$args) use ($scenario) {
			$this->record(ConsentService::class, 'canSendBusinessInitiated', $args);
			return ($scenario['consent'] ?? true);
		});
		$consent->method('isOptOutKeyword')->willReturn(false);

		$budgetService = $this->createMock(BudgetService::class);
		$budgetService->method('canSend')->willReturnCallback(function (...$args) use ($world) {
			$this->record(BudgetService::class, 'canSend', $args);
			return $world['budget'][$args[1]];
		});
		$budgetService->method('recordSend')->willReturnCallback(function (...$args) {
			$this->record(BudgetService::class, 'recordSend', $args);
		});

		$notifications = $this->createMock(NotificationService::class);
		$notifications->method('sendNotification')->willReturnCallback(function (...$args) use ($scenario) {
			$this->record(NotificationService::class, 'sendNotification', $args);
			if (($scenario['notifyFails'] ?? false) === true) {
				throw new RuntimeException('notify down');
			}
		});

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (...$args) {
			$this->record(LoggerInterface::class, 'warning', $args);
		});

		$moments = $this->createMock(ContactmomentService::class);
		$moments->method('recordOutboundMessage')->willReturnCallback(function (...$args) {
			$this->record(ContactmomentService::class, 'recordOutboundMessage', $args);
			return null;
		});

		$messaging = (new FakeMessagingAccount($this))->usable();
		$objects = $this->objectService(scenario: $scenario);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(function (string $id) use ($objects, $moments, $messaging, $scenario) {
			if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
				return $objects;
			}

			if ($id === 'OCA\\Pipelinq\\Service\\ContactmomentService' && ($scenario['noAudit'] ?? false) === false) {
				return $moments;
			}

			if ($id === 'OCA\\Pipelinq\\Service\\MessagingServiceAccount') {
				return $messaging->account;
			}

			throw new RuntimeException('not registered: ' . $id);
		});

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default) => match ($key) {
				'register' => 'pipelinq',
				'tenant_id' => 'tenant-1',
				default => $default,
			}
		);

		return new WhatsAppAdapter(
			$container,
			$appConfig,
			$repo,
			$client,
			$consent,
			$budgetService,
			$notifications,
			$logger,
			new PhoneNormaliser($appConfig, $logger),
		);
	}//end adapter()

	/**
	 * What the provider client returns or throws for one row.
	 *
	 * @param array<string, mixed> $row     The provider row.
	 * @param string               $outcome success, transient, permanent, runtime or error.
	 *
	 * @return array<string, string>
	 */
	private function clientOutcome(array $row, string $outcome): array {
		$vendor = $row['vendor'];
		return match ($outcome) {
			'transient' => throw new TransientSmsProviderException('5xx from ' . $vendor),
			'permanent' => throw new PermanentSmsProviderException('4xx from ' . $vendor),
			'runtime' => throw new RuntimeException('socket from ' . $vendor),
			'error' => throw new TypeError('type from ' . $vendor),
			default => ['externalMessageId' => 'wamid-' . $vendor, 'vendor' => $vendor . '-v'],
		};
	}//end clientOutcome()

	/**
	 * A recording object service with one approved template and one recent
	 * inbound message; saveObject throws for outbound rows when saveFails is set.
	 *
	 * @param array<string, mixed> $scenario The scenario.
	 *
	 * @return object
	 */
	private function objectService(array $scenario): object {
		$template = [
			'uuid' => 'tpl-1',
			'status' => 'approved',
			'externalId' => 'order_ready',
			'language' => 'en',
			'body' => 'Hi {{1}}, order {{2}} is ready',
			'providerId' => ($scenario['pin'] ?? ''),
		];
		return new class($this, ($scenario['saveFails'] ?? false), $template) {
			private int $count = 0;

			public function __construct(private WhatsAppAdapterSendGoldenTest $test, private bool $saveFails, private array $template) {
			}

			public function saveObject(array $object, $register = null, $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->test->note(['saveObject' => compact('object', 'register', 'schema', 'uuid', '_rbac', '_multitenancy')]);
				if ($this->saveFails === true && ($object['direction'] ?? '') === 'outbound') {
					throw new RuntimeException('save down');
				}

				$this->count++;
				return [...$object, 'uuid' => 'row-' . $this->count];
			}

			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->test->note(['findAll' => compact('config', '_rbac', '_multitenancy')]);
				if (($config['filters']['direction'] ?? '') === 'inbound') {
					return [['uuid' => 'in-1', 'contactId' => 'contact-1', 'body' => 'hi', 'sentAt' => gmdate('Y-m-d\TH:i:s\Z', time() - 60)]];
				}

				return [];
			}

			public function find($id, ?ObjectFindOptions $options = null, bool $_rbac = true, bool $_multitenancy = true): ?array {
				$this->test->note(['find' => compact('id', 'register', 'schema')]);
				if ($id === $this->template['uuid']) {
					return $this->template;
				}

				return null;
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
