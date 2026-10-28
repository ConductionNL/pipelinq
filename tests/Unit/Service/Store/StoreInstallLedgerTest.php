<?php

/**
 * Unit tests for StoreInstallLedger: what the store page knows about the
 * items this instance installed.
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
 * @spec openspec/changes/store-installed-and-updates/specs/pipelinq-store-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Store;

use OCA\Pipelinq\Service\Store\StoreInstallLedger;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/store-installed-and-updates/specs/pipelinq-store-surface/spec.md
 */
class StoreInstallLedgerTest extends TestCase {

	/**
	 * The app config values the fake holds.
	 *
	 * @var array<string, string>
	 */
	private array $values = [];

	/**
	 * A ledger over an in-memory app config.
	 *
	 * @return StoreInstallLedger
	 */
	private function ledger(): StoreInstallLedger {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default=''): string => ($this->values[$key] ?? $default)
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->values[$key] = $value;
				return true;
			}
		);
		$urls = $this->createMock(originalClassName: IURLGenerator::class);
		$urls->method('linkToRoute')->willReturnCallback(
			static fn (string $route, array $params=[]): string => '/index.php/apps/pipelinq/'.($params['path'] ?? '')
		);

		return new StoreInstallLedger(appConfig: $config, urlGenerator: $urls);

	}//end ledger()

	/**
	 * An item installed at the registry's version reads as installed, with no update.
	 *
	 * @return void
	 */
	public function testAnInstalledItemIsMarkedInstalled(): void {
		$ledger = $this->ledger();
		$ledger->record(slug: 'b2b-sales-pipeline', version: '1.3.0');

		$cards = $ledger->annotate(cards: [['slug' => 'b2b-sales-pipeline', 'version' => '1.3.0', 'kind' => 'pipeline']]);

		$this->assertTrue($cards[0]['installed']);
		$this->assertSame('1.3.0', $cards[0]['installedVersion']);
		$this->assertFalse($cards[0]['updateAvailable']);
		$this->assertStringContainsString('pipelines', $cards[0]['openUrl']);

	}//end testAnInstalledItemIsMarkedInstalled()

	/**
	 * A newer registry version than the installed one is an update.
	 *
	 * @return void
	 */
	public function testANewerRegistryVersionIsAnUpdate(): void {
		$ledger = $this->ledger();
		$ledger->record(slug: 'service-desk-routing', version: '2.0.1');

		$cards = $ledger->annotate(cards: [['slug' => 'service-desk-routing', 'version' => '2.1.0', 'kind' => 'queue-routing']]);

		$this->assertTrue($cards[0]['installed']);
		$this->assertTrue($cards[0]['updateAvailable']);
		$this->assertSame('2.0.1', $cards[0]['installedVersion']);

	}//end testANewerRegistryVersionIsAnUpdate()

	/**
	 * An item never installed carries no installed state and no open link.
	 *
	 * @return void
	 */
	public function testAnItemNotInstalledIsNotMarked(): void {
		$cards = $this->ledger()->annotate(cards: [['slug' => 'retail-pos-setup', 'version' => '1.1.0', 'kind' => 'pos-setup']]);

		$this->assertFalse($cards[0]['installed']);
		$this->assertFalse($cards[0]['updateAvailable']);
		$this->assertArrayNotHasKey('openUrl', $cards[0]);

	}//end testAnItemNotInstalledIsNotMarked()

	/**
	 * Installing again records the new version: the update is gone.
	 *
	 * @return void
	 */
	public function testRecordingAgainReplacesTheVersion(): void {
		$ledger = $this->ledger();
		$ledger->record(slug: 'service-desk-routing', version: '2.0.1');
		$ledger->record(slug: 'service-desk-routing', version: '2.1.0');

		$cards = $ledger->annotate(cards: [['slug' => 'service-desk-routing', 'version' => '2.1.0', 'kind' => 'queue-routing']]);

		$this->assertFalse($cards[0]['updateAvailable']);
		$this->assertSame('2.1.0', $cards[0]['installedVersion']);

	}//end testRecordingAgainReplacesTheVersion()

	/**
	 * A stored ledger that is not JSON reads as nothing installed.
	 *
	 * @return void
	 */
	public function testAnUnreadableLedgerReadsAsEmpty(): void {
		$this->values['store_installed'] = 'not json';

		$cards = $this->ledger()->annotate(cards: [['slug' => 'b2b-sales-pipeline', 'version' => '1.3.0']]);

		$this->assertFalse($cards[0]['installed']);

	}//end testAnUnreadableLedgerReadsAsEmpty()

	/**
	 * The last answer of the registry is kept and read back.
	 *
	 * @return void
	 */
	public function testTheLastCheckIsKept(): void {
		$ledger = $this->ledger();
		$this->assertSame('', $ledger->checkedAt());

		$ledger->markChecked(when: new \DateTimeImmutable('2026-10-28T06:00:00+01:00'));

		$this->assertSame('2026-10-28T06:00:00+01:00', $ledger->checkedAt());

	}//end testTheLastCheckIsKept()
}//end class
