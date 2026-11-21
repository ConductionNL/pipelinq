<?php

/**
 * RateOverrideStore: every stored and every saved override.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
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

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\RateOverrideStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Overrides keep a positive rate per currency, with its reason and the day it was set.
 */
class RateOverrideStoreTest extends TestCase {

	/**
	 * A store over the given stored JSON, today being 2026-05-01.
	 *
	 * @param string $json The stored overrides.
	 *
	 * @return RateOverrideStore
	 */
	private function store(string $json): RateOverrideStore {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === RateOverrideStore::OVERRIDES_KEY ? $json : $default)
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(new \DateTimeImmutable('2026-05-01T12:00:00+00:00'));

		return new RateOverrideStore($appConfig, $time);
	}//end store()

	/**
	 * Every stored override: unreadable or non-list storage is none; entries
	 * that are not lists or have no positive numeric rate are skipped; codes
	 * are trimmed and upper-cased and sorted; the date is its first ten
	 * characters when it reads as a day, else none.
	 *
	 * @return void
	 */
	public function testEveryStoredOverride(): void {
		$this->assertSame([], $this->store('not json')->getOverrides());
		$this->assertSame([], $this->store('"text"')->getOverrides());
		$this->assertSame(
			[
				'GBP' => ['rate' => 1.2, 'reason' => '', 'date' => null],
				'USD' => ['rate' => 0.9, 'reason' => 'bank', 'date' => '2026-04-01'],
			],
			$this->store('{" usd ": {"rate": "0.9", "reason": "bank", "date": "2026-04-01T10:00:00Z"}, "gbp": {"rate": 1.2, "date": "soon"}, "chf": {"rate": 0}, "jpy": {"rate": "x"}, "sek": "junk"}')->getOverrides()
		);
	}//end testEveryStoredOverride()

	/**
	 * Every saved override keeps the day it was first set when its rate and
	 * reason did not change, and takes today otherwise; codes are
	 * normalised and sorted.
	 *
	 * @return void
	 */
	public function testEverySavedOverride(): void {
		$store = $this->store('{"USD": {"rate": 0.9, "reason": "bank", "date": "2026-04-01"}, "GBP": {"rate": 1.2, "reason": "x", "date": "2026-04-02"}, "CHF": {"rate": 1.0, "reason": "y"}}');
		$this->assertSame(
			[
				'CHF' => ['rate' => 1.0, 'reason' => 'y', 'date' => '2026-05-01'],
				'GBP' => ['rate' => 1.3, 'reason' => 'x', 'date' => '2026-05-01'],
				'USD' => ['rate' => 0.9, 'reason' => 'bank', 'date' => '2026-04-01'],
			],
			$store->normaliseOverrides(input: [' usd ' => ['rate' => '0.9', 'reason' => ' bank '], 'GBP' => ['rate' => 1.3, 'reason' => 'x'], 'chf' => ['rate' => 1, 'reason' => 'y']])
		);
	}//end testEverySavedOverride()
}//end class
