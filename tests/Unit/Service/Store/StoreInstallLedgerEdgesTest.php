<?php

/**
 * Edge cases of StoreInstallLedger: what a record stores, where an installed
 * item opens, older registry versions and ledgers that hold no list.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Store
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.conduction.nl
 *
 * @spec openspec/specs/pipelinq-store-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Store;

use DateTimeImmutable;
use OCA\Pipelinq\Service\Store\StoreInstallLedger;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/specs/pipelinq-store-surface/spec.md
 */
#[CoversClass(StoreInstallLedger::class)]
class StoreInstallLedgerEdgesTest extends TestCase {

	/**
	 * The app config values the fake holds, per app and key.
	 *
	 * @var array<string, string>
	 */
	private array $values = [];

	/**
	 * The route calls the URL generator received.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $routes = [];

	/**
	 * A ledger over an in-memory app config.
	 *
	 * @return StoreInstallLedger
	 */
	private function ledger(): StoreInstallLedger {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default=''): string => ($this->values[$app.'/'.$key] ?? $default)
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->values[$app.'/'.$key] = $value;
				return true;
			}
		);
		$urls = $this->createMock(originalClassName: IURLGenerator::class);
		$urls->method('linkToRoute')->willReturnCallback(
			function (string $route, array $params=[]): string {
				$this->routes[] = [$route, $params];
				return '/apps/pipelinq/'.($params['path'] ?? '');
			}
		);

		return new StoreInstallLedger(appConfig: $config, urlGenerator: $urls);
	}//end ledger()

	/**
	 * A record keeps earlier items and stores the version with an ATOM install time, under the app.
	 *
	 * @return void
	 */
	public function testARecordKeepsTheOthersAndStampsTheTime(): void {
		$ledger = $this->ledger();
		$ledger->record(slug: 'a', version: '1.0.0');
		$ledger->record(slug: 'b', version: '2.0.0');

		$stored = json_decode(json: $this->values['pipelinq/store_installed'], associative: true);

		$this->assertSame(expected: ['a', 'b'], actual: array_keys($stored));
		$this->assertSame(expected: ['version', 'installedAt'], actual: array_keys($stored['b']));
		$this->assertSame(expected: '2.0.0', actual: $stored['b']['version']);
		$this->assertNotFalse(
			condition: DateTimeImmutable::createFromFormat(format: DATE_ATOM, datetime: $stored['a']['installedAt'])
		);
	}//end testARecordKeepsTheOthersAndStampsTheTime()

	/**
	 * Kinds and the dashboard path an installed item opens at.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function kinds(): array {
		return [
			'pipeline'          => ['pipeline', 'pipelines'],
			'queue routing'     => ['queue-routing', 'queue'],
			'catalogue'         => ['catalogue', 'products'],
			'pos setup'         => ['pos-setup', 'pos'],
			'loyalty programme' => ['loyalty-programme', 'loyalty/reporting'],
			'connector'         => ['connector', 'integrations'],
			'an unknown kind'   => ['lead', ''],
		];
	}//end kinds()

	/**
	 * An installed item opens on the dashboard page at its kind's path.
	 *
	 * @param string $kind The card kind.
	 * @param string $path The expected dashboard path.
	 *
	 * @return void
	 */
	#[DataProvider('kinds')]
	public function testAnInstalledItemOpensAtItsKind(string $kind, string $path): void {
		$ledger = $this->ledger();
		$ledger->record(slug: 'item', version: '1.0.0');

		$cards = $ledger->annotate(cards: [['slug' => 'item', 'version' => '1.0.0', 'kind' => $kind]]);

		$this->assertSame(expected: '/apps/pipelinq/'.$path, actual: $cards[0]['openUrl']);
		$this->assertSame(expected: [['pipelinq.dashboard.page', ['path' => $path]]], actual: $this->routes);
	}//end testAnInstalledItemOpensAtItsKind()

	/**
	 * Registry versions against an installed 1.10.0: only a newer one is an update.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function versions(): array {
		return [
			'older'           => ['1.9.0', false],
			'the same'        => ['1.10.0', false],
			'newer patch'     => ['1.10.1', true],
			'newer by number' => ['1.11.0', true],
			'major'           => ['2.0.0', true],
		];
	}//end versions()

	/**
	 * Versions compare numerically, not as text.
	 *
	 * @param string $latest   The registry version.
	 * @param bool   $isUpdate Whether it is an update.
	 *
	 * @return void
	 */
	#[DataProvider('versions')]
	public function testOnlyANewerVersionIsAnUpdate(string $latest, bool $isUpdate): void {
		$ledger = $this->ledger();
		$ledger->record(slug: 'item', version: '1.10.0');

		$cards = $ledger->annotate(cards: [['slug' => 'item', 'version' => $latest, 'kind' => 'pipeline']]);

		$this->assertSame(expected: $isUpdate, actual: $cards[0]['updateAvailable']);
	}//end testOnlyANewerVersionIsAnUpdate()

	/**
	 * Cards keep their own fields and order; a card without a slug is not installed and asks no route.
	 *
	 * @return void
	 */
	public function testCardsKeepTheirFieldsAndOrder(): void {
		$ledger = $this->ledger();
		$ledger->record(slug: 'b', version: '1.0.0');

		$cards = $ledger->annotate(
			cards: [
				['title' => 'Zonder slug'],
				['slug' => 'b', 'title' => 'Bee', 'version' => '1.0.0', 'kind' => 'connector'],
			]
		);

		$this->assertSame(expected: ['title' => 'Zonder slug', 'installed' => false, 'updateAvailable' => false], actual: $cards[0]);
		$this->assertSame(
			expected: [
				'slug' => 'b',
				'title' => 'Bee',
				'version' => '1.0.0',
				'kind' => 'connector',
				'installed' => true,
				'updateAvailable' => false,
				'installedVersion' => '1.0.0',
				'openUrl' => '/apps/pipelinq/integrations',
			],
			actual: $cards[1]
		);
		$this->assertCount(expectedCount: 1, haystack: $this->routes);
	}//end testCardsKeepTheirFieldsAndOrder()

	/**
	 * A ledger holding valid JSON that is not a list reads as empty, and a record starts it afresh.
	 *
	 * @return void
	 */
	public function testALedgerThatIsNoListReadsAsEmpty(): void {
		$this->values['pipelinq/store_installed'] = '"b2b"';
		$ledger = $this->ledger();

		$this->assertFalse(condition: $ledger->annotate(cards: [['slug' => 'b2b']])[0]['installed']);

		$ledger->record(slug: 'c', version: '3.0.0');
		$this->assertSame(
			expected: ['c'],
			actual: array_keys(array: json_decode(json: $this->values['pipelinq/store_installed'], associative: true))
		);
	}//end testALedgerThatIsNoListReadsAsEmpty()

	/**
	 * An installed entry that is not a record does not count as installed.
	 *
	 * @return void
	 */
	public function testAnEntryThatIsNoRecordIsNotInstalled(): void {
		$this->values['pipelinq/store_installed'] = '{"a":"1.0.0"}';

		$cards = $this->ledger()->annotate(cards: [['slug' => 'a', 'version' => '2.0.0', 'kind' => 'pipeline']]);

		$this->assertSame(expected: [false, false], actual: [$cards[0]['installed'], $cards[0]['updateAvailable']]);
		$this->assertArrayNotHasKey(key: 'installedVersion', array: $cards[0]);
	}//end testAnEntryThatIsNoRecordIsNotInstalled()

	/**
	 * The last check is stored in ATOM under the app's own key, keeping its offset.
	 *
	 * @return void
	 */
	public function testTheCheckIsStoredUnderTheAppKey(): void {
		$this->ledger()->markChecked(when: new DateTimeImmutable('2026-11-15T02:30:00Z'));

		$this->assertSame(expected: ['pipelinq/store_checked_at' => '2026-11-15T02:30:00+00:00'], actual: $this->values);
	}//end testTheCheckIsStoredUnderTheAppKey()
}//end class
