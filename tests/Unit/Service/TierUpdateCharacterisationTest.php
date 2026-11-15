<?php

/**
 * Characterisation test for TierService::updateTierIfNeeded().
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
 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\LoyaltyAccountService;
use OCA\Pipelinq\Service\TierService;
use OCP\EventDispatcher\GenericEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the answer, the setTier calls, the event and the log lines per path.
 */
class TierUpdateCharacterisationTest extends TestCase {
	/**
	 * Everything the fakes saw, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $seen = [];

	/**
	 * Three rules: bronze (0), silver (100), gold (500).
	 *
	 * @param string $silverPolicy Downgrade policy on silver.
	 * @param string $goldPolicy   Downgrade policy on gold.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function rules(string $silverPolicy, string $goldPolicy): array {
		return [
			['@self' => ['id' => 'gold'], 'name' => 'Goud', 'sequence' => 3, 'thresholdValue' => 500, 'downgradePolicy' => $goldPolicy, 'benefits' => ['m' => 2]],
			['tierId' => 'bronze', 'name' => 'Brons', 'sequence' => 1, 'thresholdValue' => 0],
			['uuid' => 'silver', 'name' => 'Zilver', 'sequence' => 2, 'thresholdValue' => 100, 'downgradePolicy' => $silverPolicy],
		];
	}//end rules()

	/**
	 * Scenarios: account, rules, dispatch fails, expected answer, expected setTier calls.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public static function scenarios(): array {
		$year = (int)(new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y');
		$month = (int)(new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('n');
		$endOfYear = sprintf('%d-12-31T23:59:59+00:00', $year);
		$endOfQuarter = (new DateTimeImmutable(sprintf('%d-%02d-01 00:00:00', $year, ((int)ceil($month / 3)) * 3), new DateTimeZone('UTC')))
			->modify('last day of this month 23:59:59')->format('c');
		$account = static fn (?string $tier, int $points): array => ['programmeId' => 'p1', 'lifetimePoints' => $points, 'currentTierId' => $tier];
		return [
			'no account' => [null, self::rules('none', 'none'), false, ['from' => null, 'to' => null, 'changed' => false], []],
			'no rules' => [$account('silver', 50), [], false, ['from' => 'silver', 'to' => null, 'changed' => false], []],
			'same tier' => [$account('silver', 150), self::rules('none', 'none'), false, ['from' => 'silver', 'to' => 'silver', 'changed' => false], []],
			'first tier' => [$account(null, 600), self::rules('none', 'end_of_year'), false, ['from' => null, 'to' => 'gold', 'changed' => true], [['acc', 'gold', 'NOW', $endOfYear]]],
			'empty current tier' => [$account('', 10), self::rules('none', 'none'), false, ['from' => '', 'to' => 'bronze', 'changed' => true], [['acc', 'bronze', 'NOW', null]]],
			'upgrade quarter policy' => [$account('silver', 700), self::rules('none', 'end_of_quarter'), false, ['from' => 'silver', 'to' => 'gold', 'changed' => true], [['acc', 'gold', 'NOW', null]]],
			'upgrade dispatch fails' => [$account('bronze', 120), self::rules('none', 'none'), true, ['from' => 'bronze', 'to' => 'silver', 'changed' => true], [['acc', 'silver', 'NOW', null]]],
			'downgrade end of year' => [$account('gold', 120), self::rules('none', 'end_of_year'), false, ['from' => 'gold', 'to' => 'gold', 'changed' => false], [['acc', 'gold', null, $endOfYear]]],
			'downgrade end of quarter' => [$account('gold', 120), self::rules('none', 'end_of_quarter'), false, ['from' => 'gold', 'to' => 'gold', 'changed' => false], [['acc', 'gold', null, $endOfQuarter]]],
			'downgrade immediately' => [$account('gold', 120), self::rules('end_of_year', 'none'), false, ['from' => 'gold', 'to' => 'silver', 'changed' => true], [['acc', 'silver', 'NOW', null]]],
			'downgrade unknown current' => [$account('ghost', 120), self::rules('none', 'end_of_year'), false, ['from' => 'ghost', 'to' => 'silver', 'changed' => true], [['acc', 'silver', 'NOW', null]]],
			'downgrade without policy key' => [$account('gold', 0), [['@self' => ['id' => 'gold'], 'sequence' => 3, 'thresholdValue' => 500], ['tierId' => 'bronze', 'sequence' => 1, 'thresholdValue' => 0]], false, ['from' => 'gold', 'to' => 'bronze', 'changed' => true], [['acc', 'bronze', 'NOW', null]]],
		];
	}//end scenarios()

	/**
	 * One scenario.
	 *
	 * @param array<string, mixed>|null        $account      Account answer.
	 * @param array<int, array<string, mixed>> $rules        Tier rules.
	 * @param bool                             $dispatchFails Whether dispatch throws.
	 * @param array<string, mixed>             $answer       Expected answer.
	 * @param array<int, array<int, mixed>>    $setTier      Expected setTier calls.
	 *
	 * @return void
	 */
	#[DataProvider('scenarios')]
	public function testScenario(?array $account, array $rules, bool $dispatchFails, array $answer, array $setTier): void {
		$service = $this->service(account: $account, rules: $rules, dispatchFails: $dispatchFails);

		self::assertSame($answer, $service->updateTierIfNeeded('acc'));

		$calls = array_values(array_filter($this->seen, static fn (array $s): bool => $s[0] === 'setTier'));
		$calls = array_map(
			static function (array $s): array {
				$achieved = $s[3];
				if ($achieved !== null) {
					self::assertNotFalse(DateTimeImmutable::createFromFormat(DATE_ATOM, $achieved));
					$achieved = 'NOW';
				}

				return [$s[1], $s[2], $achieved, $s[4]];
			},
			$calls
		);
		self::assertSame($setTier, $calls);

		$events = array_values(array_filter($this->seen, static fn (array $s): bool => $s[0] === 'event'));
		$logs = array_values(array_filter($this->seen, static fn (array $s): bool => $s[0] === 'warning'));
		if ($answer['changed'] === false) {
			self::assertSame([], $events);
			self::assertSame([], $logs);
			return;
		}

		$to = null;
		foreach ($rules as $rule) {
			if (($rule['@self']['id'] ?? $rule['tierId'] ?? $rule['uuid'] ?? null) === $answer['to']) {
				$to = $rule;
			}
		}

		self::assertSame(
			[[
				'event',
				[
					'type' => 'loyalty.tier.changed',
					'accountId' => 'acc',
					'fromTierId' => $answer['from'],
					'toTierId' => $answer['to'],
					'toTierNaam' => (string)($to['name'] ?? ''),
					'benefits' => $to['benefits'] ?? [],
				],
			]],
			$events
		);
		$expectedLogs = [];
		if ($dispatchFails === true) {
			$expectedLogs[] = ['warning', 'Pipelinq: tier-changed event dispatch failed', ['exception' => 'bus down']];
		}

		self::assertSame($expectedLogs, $logs);
	}//end testScenario()

	/**
	 * Build the service over recording fakes.
	 *
	 * @param array<string, mixed>|null        $account       Account answer.
	 * @param array<int, array<string, mixed>> $rules         Tier rules.
	 * @param bool                             $dispatchFails Whether dispatch throws.
	 *
	 * @return TierService
	 */
	private function service(?array $account, array $rules, bool $dispatchFails): TierService {
		$this->seen = [];
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static fn (string $app, string $key): string => 'id-' . $key);
		$accounts = $this->createMock(LoyaltyAccountService::class);
		$accounts->method('getAccount')->willReturn($account);
		$accounts->method('setTier')->willReturnCallback(
			function (...$args): ?array {
				$this->seen[] = array_merge(['setTier'], $args);
				return null;
			}
		);
		$events = $this->createMock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(
			function (GenericEvent $event) use ($dispatchFails): void {
				$this->seen[] = ['event', $event->getArguments()];
				if ($dispatchFails === true) {
					throw new RuntimeException('bus down');
				}
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->seen[] = ['warning', $message, $context];
			}
		);
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturn($rules);

		return new TierService($config, $accounts, $events, $logger, $objects);
	}//end service()
}//end class
