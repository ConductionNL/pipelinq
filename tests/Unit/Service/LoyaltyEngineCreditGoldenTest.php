<?php

/**
 * Golden test for crediting POS transactions to loyalty accounts.
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

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\LoyaltyAccountService;
use OCA\Pipelinq\Service\LoyaltyEngineService;
use OCA\Pipelinq\Service\PointsLedgerService;
use OCA\Pipelinq\Service\PointsRuleEngine;
use OCA\Pipelinq\Service\TierService;
use OCP\EventDispatcher\GenericEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;
use Throwable;

/**
 * Every path of processPosTransaction: which ledger credit is written (amount,
 * rule, source document, processor), which tier and event follow, and what
 * comes back per programme. Compared with LoyaltyEngineCreditGolden.json; set
 * LOYALTY_CREDIT_GOLDEN_RECORD=1 to rewrite it, then run once more without it.
 *
 * @covers \OCA\Pipelinq\Service\LoyaltyEngineService
 * @covers \OCA\Pipelinq\Service\Loyalty\PointsCreditRun
 * @covers \OCA\Pipelinq\Service\Loyalty\PointsGrant
 *
 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-002
 */
class LoyaltyEngineCreditGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/LoyaltyEngineCreditGolden.json';

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
		if (getenv('LOYALTY_CREDIT_GOLDEN_RECORD') !== false) {
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
	 * The scenarios: a customer, a transaction and how the world answers.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function scenarios(): array {
		$tx = ['amount' => '12.50', 'category' => 'food', 'channel' => 'store', 'segment' => 'b2c', 'timestamp' => '2026-10-01T10:00:00+00:00', 'posTransactionId' => 'pos-77', 'posTerminalId' => 'term-3', 'trigger' => 'purchase'];
		$rule = ['@self' => ['id' => 'rule-1'], 'formula' => ['perEuro' => 2], 'maxPerCustomerPerPeriod' => null];
		return [
			'credit'                           => ['tx' => $tx, 'rules' => [$rule]],
			'credit with a tier multiplier'    => ['tx' => $tx, 'rules' => [$rule], 'account' => ['currentTierId' => 'tier-gold'], 'tiers' => [['uuid' => 'tier-silver', 'm' => 1.2], ['uuid' => 'tier-gold', 'm' => 1.5]]],
			'credit, tier changes'             => ['tx' => $tx, 'rules' => [$rule], 'tierChanged' => true],
			'credit, no terminal, no pos id'   => ['tx' => ['amount' => 10, 'channel' => 'web'], 'rules' => [$rule]],
			'credit, amount not a number'      => ['tx' => ['amount' => 'abc', 'posTerminalId' => 5] + $tx, 'rules' => [['uuid' => 'rule-u', 'formula' => ['flat' => 3]]]],
			'credit, integer pos id'           => ['tx' => ['posTransactionId' => 501, 'channel' => 7] + $tx, 'rules' => [$rule], 'account' => ['currentTierId' => 'tier-none'], 'tiers' => [['uuid' => 'tier-gold', 'm' => 1.5]]],
			'credit, rule without id'          => ['tx' => $tx, 'rules' => [['formula' => ['perEuro' => 1]]]],
			'credit, formula not a list'       => ['tx' => $tx, 'rules' => [['id' => 'rule-i', 'formula' => 'x']]],
			'credit, under the period max'     => ['tx' => $tx, 'rules' => [['id' => 'rule-m', 'formula' => ['perEuro' => 2], 'maxPerCustomerPerPeriod' => 30, 'maxPerCustomerPeriod' => 'week']], 'history' => [['type' => 'credit', 'ruleId' => 'rule-m', 'count' => 10], ['type' => 'debit', 'ruleId' => 'rule-m', 'count' => 99], ['type' => 'credit', 'ruleId' => 'other', 'count' => 99]]],
			'period max reached'               => ['tx' => $tx, 'rules' => [['id' => 'rule-m', 'formula' => ['perEuro' => 2], 'maxPerCustomerPerPeriod' => 10]], 'history' => [['type' => 'credit', 'ruleId' => 'rule-m', 'count' => 10]]],
			'zero points'                      => ['tx' => ['amount' => 0] + $tx, 'rules' => [$rule]],
			'credit throws'                    => ['tx' => $tx, 'rules' => [$rule], 'creditThrows' => true],
			'event dispatch throws'            => ['tx' => $tx, 'rules' => [$rule], 'dispatchThrows' => true],
			'ledger entry without numbers'     => ['tx' => $tx, 'rules' => [$rule], 'entry' => []],
			'no matching rule'                 => ['tx' => $tx, 'rules' => []],
			'account inactive'                 => ['tx' => $tx, 'rules' => [$rule], 'account' => ['status' => 'frozen']],
			'not enrolled'                     => ['tx' => $tx, 'rules' => [$rule], 'account' => null],
			'two programmes'                   => ['tx' => $tx, 'rules' => [$rule], 'programmes' => [['@self' => ['id' => 'prog-1']], ['programmeId' => 'prog-2'], ['name' => 'no id']]],
			'empty customer'                   => ['tx' => $tx, 'rules' => [$rule], 'customer' => ''],
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
		$this->trace = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'register' => 'reg-1',
				'loyaltyProgramme_schema' => 'schema-programme',
				default => $default,
			}
		);

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturn(($scenario['programmes'] ?? [['@self' => ['id' => 'prog-1']]]));

		$account = array_key_exists('account', $scenario) === true ? $scenario['account'] : [];
		if ($account !== null) {
			$account = $account + ['uuid' => 'acc-1', 'status' => 'active'];
		}

		$accounts = $this->createMock(LoyaltyAccountService::class);
		$accounts->method('findAccountByKlantAndProgramme')->willReturnCallback(
			fn (...$args): ?array => $this->record(class: LoyaltyAccountService::class, method: 'findAccountByKlantAndProgramme', args: $args, answer: $account)
		);

		$ledger = $this->createMock(PointsLedgerService::class);
		$ledger->method('getLedgerHistory')->willReturnCallback(
			fn (...$args): array => $this->record(class: PointsLedgerService::class, method: 'getLedgerHistory', args: $args, answer: ($scenario['history'] ?? []))
		);
		$ledger->method('creditPoints')->willReturnCallback(
			function (...$args) use ($scenario): array {
				$entry = ($scenario['entry'] ?? ['count' => $args[1], 'balanceAfter' => 100 + $args[1]]);
				$this->record(class: PointsLedgerService::class, method: 'creditPoints', args: $args, answer: $entry);
				if (($scenario['creditThrows'] ?? false) === true) {
					throw new RuntimeException('ledger write failed');
				}

				return $entry;
			}
		);

		$rules = $this->createMock(PointsRuleEngine::class);
		$rules->method('evaluateRules')->willReturnCallback(
			fn (...$args): array => $this->record(class: PointsRuleEngine::class, method: 'evaluateRules', args: $args, answer: $scenario['rules'])
		);
		$rules->method('getHighestPriorityRule')->willReturnCallback(
			fn (array $matches): ?array => ($matches[0] ?? null)
		);
		$rules->method('calculatePoints')->willReturnCallback(
			function (...$args): int {
				[$formula, $amount, $multiplier] = $args + [2 => 1.0];
				$points = (int)floor(($amount * (float)($formula['perEuro'] ?? 0) + (float)($formula['flat'] ?? 0)) * $multiplier);
				return $this->record(class: PointsRuleEngine::class, method: 'calculatePoints', args: $args, answer: $points);
			}
		);
		$rules->method('applyMaxPerCustomer')->willReturnCallback(
			function (...$args): int {
				[$earned, $points, $max] = $args;
				$award = ($max === null ? $points : max(0, min($points, ($max - $earned))));
				return $this->record(class: PointsRuleEngine::class, method: 'applyMaxPerCustomer', args: $args, answer: $award);
			}
		);

		$tiers = $this->createMock(TierService::class);
		$tiers->method('getTierRules')->willReturnCallback(
			fn (...$args): array => $this->record(class: TierService::class, method: 'getTierRules', args: $args, answer: ($scenario['tiers'] ?? []))
		);
		$tiers->method('applyTierBenefits')->willReturnCallback(
			fn (...$args): float => $this->record(class: TierService::class, method: 'applyTierBenefits', args: $args, answer: (float)$args[0]['m'])
		);
		$tiers->method('updateTierIfNeeded')->willReturnCallback(
			fn (...$args): array => $this->record(class: TierService::class, method: 'updateTierIfNeeded', args: $args, answer: ['changed' => ($scenario['tierChanged'] ?? false)])
		);

		$events = $this->createMock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(
			function (GenericEvent $event) use ($scenario): void {
				$this->trace[] = ['dispatchTyped' => $event->getArguments()];
				if (($scenario['dispatchThrows'] ?? false) === true) {
					throw new RuntimeException('dispatch failed');
				}
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

		$engine = new LoyaltyEngineService(
			appConfig: $appConfig,
			accountService: $accounts,
			ledgerService: $ledger,
			ruleEngine: $rules,
			tierService: $tiers,
			eventDispatcher: $events,
			logger: $logger,
			objectService: $objects,
		);

		try {
			$result = ['returned' => $engine->processPosTransaction(customerId: ($scenario['customer'] ?? 'cust-1'), transaction: $scenario['tx'])];
		} catch (Throwable $e) {
			$result = ['threw' => get_class($e), 'message' => $e->getMessage()];
		}

		return $this->normalise(value: $result + ['trace' => $this->trace]);
	}//end runScenario()

	/**
	 * Record a collaborator call by parameter name, leaving out defaults, and pass its answer through.
	 *
	 * @param string            $class  The class.
	 * @param string            $method The method.
	 * @param array<int, mixed> $args   The positional arguments.
	 * @param mixed             $answer What the collaborator answers.
	 *
	 * @return mixed
	 */
	private function record(string $class, string $method, array $args, mixed $answer): mixed {
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

		$this->trace[] = [$method => $named];
		return $answer;
	}//end record()

	/**
	 * Replace the clock: an ISO-8601 timestamp of today becomes `<now>`.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private function normalise(mixed $value): mixed {
		if (is_array($value) === true) {
			return array_map(fn (mixed $item): mixed => $this->normalise(value: $item), $value);
		}

		if (is_string($value) === true && str_starts_with($value, '2026-10-01') === false && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $value) === 1) {
			return '<now>';
		}

		return $value;
	}//end normalise()
}//end class
