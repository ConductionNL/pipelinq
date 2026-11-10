<?php

/**
 * Golden test for SmsAdapter::send() over its provider attempts.
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

use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use OCA\Pipelinq\Service\BudgetService;
use OCA\Pipelinq\Service\ChannelProviderRepository;
use OCA\Pipelinq\Service\ConsentService;
use OCA\Pipelinq\Service\ContactmomentService;
use OCA\Pipelinq\Service\NotificationService;
use OCA\Pipelinq\Service\PhoneNormaliser;
use OCA\Pipelinq\Service\Provider\PermanentSmsProviderException;
use OCA\Pipelinq\Service\Provider\SmsProviderClientInterface;
use OCA\Pipelinq\Service\Provider\TransientSmsProviderException;
use OCA\Pipelinq\Service\SmsAdapter;
use OCA\Pipelinq\Service\SmsProviderFactory;
use OCA\Pipelinq\Tests\Unit\Support\FakeMessagingAccount;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;
use TypeError;

/**
 * Every provider outcome of send(), with and without a provider hint: the
 * envelope, and every collaborator call by parameter name, compared with
 * SmsAdapterSendGolden.json. Set SMS_SEND_GOLDEN_RECORD=1 to rewrite the
 * file, then run once more without it.
 *
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */
class SmsAdapterSendGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/SmsAdapterSendGolden.json';

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
		if (getenv('SMS_SEND_GOLDEN_RECORD') !== false) {
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
			['p2', 'beta', true, null],
			['p3', 'gamma', true, 'transient'],
			['p4', 'delta', true, 'runtime'],
			['p5', 'eps', true, 'error'],
			['p6', 'zeta', true, 'success'],
			['p7', 'eta', true, 'success'],
		];
		return [
			'failover through every miss'        => ['providers' => $mixed, 'hint' => null, 'context' => ['clientId' => 'cl-1', 'agent' => 'ann', 'inReplyTo' => '']],
			'all transient'                      => ['providers' => [['p1', 'a', true, 'transient'], ['p2', 'b', true, 'transient']], 'hint' => null],
			'permanent stops failover'           => ['providers' => [['p1', 'a', true, 'permanent'], ['p2', 'b', true, 'success']], 'hint' => null],
			'all budgets spent'                  => ['providers' => [['p1', 'a', false, 'success'], ['p2', 'b', false, 'success']], 'hint' => null],
			'hint: budget spent'                 => ['providers' => [['p9', 'hinted', false, 'success']], 'hint' => 'hinted'],
			'hint: transient'                    => ['providers' => [['p9', 'hinted', true, 'transient']], 'hint' => 'hinted'],
			'hint: permanent'                    => ['providers' => [['p9', 'hinted', true, 'permanent']], 'hint' => 'hinted'],
			'hint: runtime'                      => ['providers' => [['p9', 'hinted', true, 'runtime']], 'hint' => 'hinted'],
			'hint: type error'                   => ['providers' => [['p9', 'hinted', true, 'error']], 'hint' => 'hinted'],
			'hint: no client'                    => ['providers' => [['p9', 'hinted', true, null]], 'hint' => 'hinted'],
			'hint: success, no audit service'    => ['providers' => [['p9', 'hinted', true, 'success']], 'hint' => 'hinted', 'noAudit' => true],
			'hint: success, save fails'          => ['providers' => [['p9', 'hinted', true, 'success']], 'hint' => 'hinted', 'saveFails' => true, 'context' => ['agent' => 7]],
			'transient, notification fails'      => ['providers' => [['p1', 'a', true, 'transient']], 'hint' => 'a', 'notifyFails' => true],
			'empty hint lists active'            => ['providers' => [['p1', 'a', true, 'success']], 'hint' => ''],
			'no providers'                       => ['providers' => [], 'hint' => null],
			'consent refused'                    => ['providers' => [['p1', 'a', true, 'success']], 'hint' => null, 'consent' => false],
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
		$clients = [];
		$budget = [];
		foreach ($scenario['providers'] as [$uuid, $vendor, $budgetOk, $outcome]) {
			$rows[] = ['uuid' => $uuid, 'kind' => 'sms', 'vendor' => $vendor];
			$budget[$uuid] = $budgetOk;
			$clients[$uuid] = ($outcome === null) ? null : $this->client(vendor: $vendor, outcome: $outcome);
		}

		$adapter = $this->adapter(scenario: $scenario, world: ['rows' => $rows, 'clients' => $clients, 'budget' => $budget]);
		$result = $adapter->send(
			['uuid' => 'contact-1', 'phoneNumber' => '+31611111111'],
			'Hello there',
			$scenario['hint'],
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
	 * @param array<string, mixed> $world    Provider rows, clients and budgets.
	 *
	 * @return SmsAdapter
	 */
	private function adapter(array $scenario, array $world): SmsAdapter {
		$repo = $this->createMock(ChannelProviderRepository::class);
		$repo->method('listActive')->willReturnCallback(function (...$args) use ($world) {
			$this->record(ChannelProviderRepository::class, 'listActive', $args);
			return $world['rows'];
		});
		$repo->method('findByVendor')->willReturnCallback(function (...$args) use ($world) {
			$this->record(ChannelProviderRepository::class, 'findByVendor', $args);
			return ($world['rows'][0] ?? null);
		});

		$factory = $this->createMock(SmsProviderFactory::class);
		$factory->method('create')->willReturnCallback(function (...$args) use ($world) {
			$this->record(SmsProviderFactory::class, 'create', $args);
			return $world['clients'][$args[0]['uuid']];
		});

		$consent = $this->createMock(ConsentService::class);
		$consent->method('canSend')->willReturnCallback(function (...$args) use ($scenario) {
			$this->record(ConsentService::class, 'canSend', $args);
			return ($scenario['consent'] ?? true);
		});

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
		$objects = $this->objectService(saveFails: ($scenario['saveFails'] ?? false));
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

		return new SmsAdapter(
			$container,
			$appConfig,
			$repo,
			$factory,
			$consent,
			$budgetService,
			$notifications,
			$logger,
			new PhoneNormaliser($appConfig, $logger),
		);
	}//end adapter()

	/**
	 * A recording object service; saveObject throws when saveFails is set.
	 *
	 * @param bool $saveFails Whether saveObject throws.
	 *
	 * @return object
	 */
	private function objectService(bool $saveFails): object {
		$test = $this;
		return new class($test, $saveFails) {
			private int $count = 0;

			public function __construct(private SmsAdapterSendGoldenTest $test, private bool $saveFails) {
			}

			public function saveObject(array $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true, bool $_multitenancy = true): array {
				$register = $options->target->register;
				$schema = $options->target->schema;
				$uuid = $options->uuid;
				$this->test->note(['saveObject' => compact('object', 'register', 'schema', 'uuid', '_rbac', '_multitenancy')]);
				if ($this->saveFails === true && ($object['direction'] ?? '') === 'outbound') {
					throw new RuntimeException('save down');
				}

				$this->count++;
				return [...$object, 'uuid' => 'row-' . $this->count];
			}

			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->test->note(['findAll' => compact('config', '_rbac', '_multitenancy')]);
				return [];
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

	/**
	 * A provider client with a fixed outcome.
	 *
	 * @param string $vendor  Vendor key.
	 * @param string $outcome success, transient, permanent, runtime or error.
	 *
	 * @return SmsProviderClientInterface
	 */
	private function client(string $vendor, string $outcome): SmsProviderClientInterface {
		return new class($this, $vendor, $outcome) implements SmsProviderClientInterface {
			public function __construct(private SmsAdapterSendGoldenTest $test, private string $vendor, private string $outcome) {
			}

			public function send(string $toNumber, string $body): array {
				$this->test->note(['client::send' => ['vendor' => $this->vendor, 'toNumber' => $toNumber, 'body' => $body]]);
				return match ($this->outcome) {
					'transient' => throw new TransientSmsProviderException('5xx from ' . $this->vendor),
					'permanent' => throw new PermanentSmsProviderException('4xx from ' . $this->vendor),
					'runtime' => throw new RuntimeException('socket from ' . $this->vendor),
					'error' => throw new TypeError('type from ' . $this->vendor),
					default => ['externalMessageId' => 'ext-' . $this->vendor, 'vendor' => $this->vendor . '-v'],
				};
			}

			public function verifySignature(string $rawBody, string $signature): bool {
				return true;
			}

			public function getVendor(): string {
				$this->test->note(['client::getVendor' => $this->vendor]);
				return $this->vendor;
			}
		};
	}//end client()
}//end class
