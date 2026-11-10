<?php

/**
 * Golden test for the points ledger movements.
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
 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\Pipelinq\Service\LoyaltyAccountService;
use OCA\Pipelinq\Service\PointsLedgerService;
use OCA\Pipelinq\Tests\Unit\Support\SaveCallShape;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;
use Throwable;

/**
 * Every credit, debit, expiry, adjustment and refund: what is written to the
 * ledger, what the account balance is set to, what is logged and what comes
 * back. Compared with PointsLedgerGolden.json; set POINTS_LEDGER_GOLDEN_RECORD=1
 * to rewrite it, then run once more without it.
 *
 * @covers \OCA\Pipelinq\Service\PointsLedgerService
 * @uses   \OCA\Pipelinq\Service\Loyalty\LedgerMovement
 * @uses   \OCA\Pipelinq\Service\Loyalty\LedgerOrigin
 * @uses   \OCA\Pipelinq\AppInfo\OpenRegisterAutoloader
 * @uses \OCA\Pipelinq\Util\EntityPayload
 * @uses \OCA\Pipelinq\Util\PayloadToArray
 * @uses \OCA\Pipelinq\Util\SerialisedPayload
 * @uses \OCA\Pipelinq\Util\ObjectToPlainArray
 * @uses \OCA\Pipelinq\Util\AnyPayloadToArray
 * @uses \OCA\Pipelinq\Util\CastingPayloadToArray
 * @uses \OCA\Pipelinq\Util\NullablePayloadToArray
 * @uses \OCA\Pipelinq\Util\OptionalPayloadToArray
 * @uses \OCA\Pipelinq\Util\SerialisedToArray
 *
 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-002
 */
class PointsLedgerGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/PointsLedgerGolden.json';

	/**
	 * What the current scenario did, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $trace = [];

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

		$json = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)) . "\n";
		if (getenv('POINTS_LEDGER_GOLDEN_RECORD') !== false) {
			file_put_contents(self::GOLDEN, $json);
			$this->markTestSkipped('recorded');
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		$actual = json_decode($json, true);
		foreach ($expected as $name => $want) {
			$this->assertSame($want, ($actual[$name] ?? null), $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testGolden()

	/**
	 * A credit stamps the account's last activity with the ledger entry's own timestamp.
	 *
	 * @return void
	 */
	public function testCreditStampsLastActivityWithTheEntryTimestamp(): void {
		$raw = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'register' => 'reg-1',
				'pointsLedgerEntry_schema' => 'schema-ledger',
				default => $default,
			}
		);

		$accountService = $this->createMock(LoyaltyAccountService::class);
		$accountService->method('getAccount')->willReturn(['uuid' => 'acc-1', 'customerId' => 'cust-1', 'currentBalance' => 100, 'lifetimePoints' => 500]);
		$accountService->method('updateBalances')->willReturnCallback(
			function (...$args) use (&$raw): ?array {
				$raw['lastActivityDate'] = $this->named(class: LoyaltyAccountService::class, method: 'updateBalances', args: $args)['lastActivityDate'];
				return ['uuid' => 'acc-1'];
			}
		);

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('saveObject')->willReturnCallback(
			function (...$args) use (&$raw) {
				$object = $this->named(class: ObjectServiceInterface::class, method: 'saveObject', args: $args)['object'];
				$raw['timestamp'] = $object['timestamp'];
				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($object + ['uuid' => 'entry-1']);
				return $entity;
			}
		);

		$service = new PointsLedgerService(
			appConfig: $appConfig,
			accountService: $accountService,
			logger: $this->createMock(LoggerInterface::class),
			objectService: $objects,
			aggregationRunner: $this->createMock(AggregationRunner::class),
		);
		$service->creditPoints(accountId: 'acc-1', amount: 40, ruleId: 'rule-1', sourceDocument: [], processedBy: 'alice');

		$this->assertIsString($raw['timestamp'] ?? null);
		$this->assertSame($raw['timestamp'], ($raw['lastActivityDate'] ?? null));
	}//end testCreditStampsLastActivityWithTheEntryTimestamp()

	/**
	 * The scenarios: a public method, its arguments, and how the world answers.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function scenarios(): array {
		$doc = ['orderId' => 'o-1', 'channel' => 'web'];
		return [
			'credit'                         => ['call' => 'creditPoints', 'args' => ['accountId' => 'acc-1', 'amount' => 40, 'ruleId' => 'rule-1', 'sourceDocument' => $doc, 'processedBy' => 'system:rules']],
			'credit without a rule'          => ['call' => 'creditPoints', 'args' => ['accountId' => 'acc-1', 'amount' => 1, 'ruleId' => null, 'sourceDocument' => [], 'processedBy' => 'alice']],
			'credit of zero'                 => ['call' => 'creditPoints', 'args' => ['accountId' => 'acc-1', 'amount' => 0, 'ruleId' => null, 'sourceDocument' => [], 'processedBy' => 'alice']],
			'credit, unknown account'        => ['call' => 'creditPoints', 'args' => ['accountId' => 'acc-x', 'amount' => 5, 'ruleId' => null, 'sourceDocument' => [], 'processedBy' => 'alice']],
			'credit, account without fields' => ['call' => 'creditPoints', 'args' => ['accountId' => 'acc-bare', 'amount' => 5, 'ruleId' => 'r', 'sourceDocument' => $doc, 'processedBy' => 'alice']],
			'credit, not configured'         => ['call' => 'creditPoints', 'args' => ['accountId' => 'acc-1', 'amount' => 5, 'ruleId' => null, 'sourceDocument' => [], 'processedBy' => 'alice'], 'schema' => ''],
			'credit, save throws'            => ['call' => 'creditPoints', 'args' => ['accountId' => 'acc-1', 'amount' => 5, 'ruleId' => null, 'sourceDocument' => [], 'processedBy' => 'alice'], 'saveThrows' => true],
			'credit, balance update throws'  => ['call' => 'creditPoints', 'args' => ['accountId' => 'acc-1', 'amount' => 7, 'ruleId' => null, 'sourceDocument' => $doc, 'processedBy' => 'alice'], 'updateThrows' => true],
			'credit, only getObject'        => ['call' => 'creditPoints', 'args' => ['accountId' => 'acc-1', 'amount' => 3, 'ruleId' => null, 'sourceDocument' => [], 'processedBy' => 'alice'], 'entity' => true],
			'debit'                          => ['call' => 'debitPoints', 'args' => ['accountId' => 'acc-1', 'amount' => 30, 'redemptionId' => 'red-1', 'sourceDocument' => $doc, 'processedBy' => 'bob']],
			'debit of the whole balance'     => ['call' => 'debitPoints', 'args' => ['accountId' => 'acc-1', 'amount' => 100, 'redemptionId' => 'red-2', 'sourceDocument' => ['redemptionId' => 'old'], 'processedBy' => 'bob']],
			'debit over the balance'         => ['call' => 'debitPoints', 'args' => ['accountId' => 'acc-1', 'amount' => 101, 'redemptionId' => 'red-3', 'sourceDocument' => [], 'processedBy' => 'bob']],
			'debit of zero'                  => ['call' => 'debitPoints', 'args' => ['accountId' => 'acc-1', 'amount' => 0, 'redemptionId' => 'red-4', 'sourceDocument' => [], 'processedBy' => 'bob']],
			'debit, unknown account'         => ['call' => 'debitPoints', 'args' => ['accountId' => 'acc-x', 'amount' => 1, 'redemptionId' => 'red-5', 'sourceDocument' => [], 'processedBy' => 'bob']],
			'debit, balance update throws'   => ['call' => 'debitPoints', 'args' => ['accountId' => 'acc-1', 'amount' => 2, 'redemptionId' => 'red-6', 'sourceDocument' => [], 'processedBy' => 'bob'], 'updateThrows' => true],
			'expire'                         => ['call' => 'expirePoints', 'args' => ['accountId' => 'acc-1', 'amount' => 25, 'reason' => 'policy-12m']],
			'expire of a negative amount'    => ['call' => 'expirePoints', 'args' => ['accountId' => 'acc-1', 'amount' => -1, 'reason' => 'policy-12m']],
			'expire, unknown account'        => ['call' => 'expirePoints', 'args' => ['accountId' => 'acc-x', 'amount' => 1, 'reason' => 'policy']],
			'adjust up'                      => ['call' => 'adjustPoints', 'args' => ['accountId' => 'acc-1', 'delta' => 12, 'reason' => 'goodwill', 'processedBy' => 'carol']],
			'adjust down'                    => ['call' => 'adjustPoints', 'args' => ['accountId' => 'acc-1', 'delta' => -150, 'reason' => 'correction', 'processedBy' => 'carol']],
			'adjust by zero'                 => ['call' => 'adjustPoints', 'args' => ['accountId' => 'acc-1', 'delta' => 0, 'reason' => 'none', 'processedBy' => 'carol']],
			'adjust, not configured'         => ['call' => 'adjustPoints', 'args' => ['accountId' => 'acc-1', 'delta' => 4, 'reason' => 'x', 'processedBy' => 'carol'], 'register' => ''],
			'refund'                         => ['call' => 'refundPoints', 'args' => ['accountId' => 'acc-1', 'amount' => 30, 'redemptionId' => 'red-1', 'processedBy' => 'dave']],
			'refund of zero'                 => ['call' => 'refundPoints', 'args' => ['accountId' => 'acc-1', 'amount' => 0, 'redemptionId' => 'red-1', 'processedBy' => 'dave']],
			'refund, balance update throws'  => ['call' => 'refundPoints', 'args' => ['accountId' => 'acc-1', 'amount' => 9, 'redemptionId' => 'red-9', 'processedBy' => 'dave'], 'updateThrows' => true],
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
		$this->trace = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'register' => ($scenario['register'] ?? 'reg-1'),
				'pointsLedgerEntry_schema' => ($scenario['schema'] ?? 'schema-ledger'),
				default => $default,
			}
		);

		$accounts = [
			'acc-1' => ['uuid' => 'acc-1', 'customerId' => 'cust-1', 'currentBalance' => 100, 'lifetimePoints' => 500],
			'acc-bare' => ['uuid' => 'acc-bare'],
		];
		$accountService = $this->createMock(LoyaltyAccountService::class);
		$accountService->method('getAccount')->willReturnCallback(
			function (string $accountId) use ($accounts): ?array {
				$this->trace[] = ['getAccount' => $accountId];
				return ($accounts[$accountId] ?? null);
			}
		);
		$accountService->method('updateBalances')->willReturnCallback(
			function (...$args) use ($scenario): ?array {
				$this->trace[] = ['updateBalances' => $this->named(class: LoyaltyAccountService::class, method: 'updateBalances', args: $args)];
				if (($scenario['updateThrows'] ?? false) === true) {
					throw new RuntimeException('account write failed');
				}

				return ['uuid' => 'acc-1'];
			}
		);

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('saveObject')->willReturnCallback(
			function (...$args) use ($scenario) {
				$named = $this->named(class: ObjectServiceInterface::class, method: 'saveObject', args: $args);
				$this->trace[] = ['saveObject' => $named];
				if (($scenario['saveThrows'] ?? false) === true) {
					throw new RuntimeException('save failed');
				}

				$saved = $named['object'] + ['uuid' => 'entry-1'];
				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('getObject')->willReturn($saved + ['readBy' => 'getObject']);
				$entity->method('jsonSerialize')->willReturn(($scenario['entity'] ?? false) === true ? 'not an array' : $saved);
				return $entity;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning', 'info', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message, array $context = []) use ($level): void {
					$this->trace[] = ['log' => $level, 'message' => (string)$message, 'context' => $context];
				}
			);
		}

		$service = new PointsLedgerService(
			appConfig: $appConfig,
			accountService: $accountService,
			logger: $logger,
			objectService: $objects,
			aggregationRunner: $this->createMock(AggregationRunner::class),
		);

		try {
			$result = ['returned' => $service->{$scenario['call']}(...$scenario['args'])];
		} catch (Throwable $e) {
			$result = ['threw' => get_class($e), 'message' => $e->getMessage()];
		}

		return $this->normalise(value: $result + ['trace' => $this->trace]);
	}//end runScenario()

	/**
	 * Name mock call arguments by the loaded method's parameters, leaving out defaults.
	 *
	 * @param string            $class  The class.
	 * @param string            $method The method.
	 * @param array<int, mixed> $args   The positional arguments.
	 *
	 * @return array<string, mixed>
	 */
	private function named(string $class, string $method, array $args): array {
		if ($method === 'saveObject') {
			return SaveCallShape::named(args: $args);
		}

		$out = [];
		foreach ((new ReflectionMethod($class, $method))->getParameters() as $i => $param) {
			if (array_key_exists($i, $args) === false) {
				continue;
			}

			if ($param->isDefaultValueAvailable() === true && $param->getDefaultValue() === $args[$i]) {
				continue;
			}

			$out[$param->getName()] = $args[$i];
		}

		return $out;
	}//end named()

	/**
	 * Replace the clock: an ISO-8601 timestamp of now becomes `<now>`.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private function normalise(mixed $value): mixed {
		if (is_array($value) === true) {
			return array_map(fn (mixed $item): mixed => $this->normalise(value: $item), $value);
		}

		if (is_string($value) === true && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $value) === 1) {
			return '<now>';
		}

		return $value;
	}//end normalise()
}//end class
