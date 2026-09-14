<?php

/**
 * The connection declaration integriq reads.
 *
 * `lib/Settings/connections.json` is static JSON that integriq turns into the
 * rows of pipelinq's Integrations page. Nothing in pipelinq reads it at
 * runtime, so a broken file fails nowhere in this repo: integriq skips it whole
 * and the page goes empty on some other instance. Every assertion here is a way
 * that file could go wrong without a sound.
 *
 * @category Tests
 * @package  OCA\Pipelinq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md#requirement-req-as-130-pipelinq-declares-its-outside-connections-in-one-static-file
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use OCA\Pipelinq\Service\ConnectionReportService;
use OCA\Pipelinq\Service\LogiusConnector;
use PHPUnit\Framework\TestCase;

/**
 * Guards lib/Settings/connections.json against design D2 of connection-registry.
 *
 * The rules below mirror integriq's `lib/Settings/connections.schema.json`
 * (integriq#1996) field for field, because that schema is not a dependency of
 * this repo and a validator library for one file is not worth the weight.
 *
 * @coversNothing
 */
class ConnectionsDeclarationTest extends TestCase {

	/**
	 * The fields design D2 allows on one connection.
	 *
	 * @var array<int, string>
	 */
	private const ALLOWED_FIELDS = [
		'key',
		'title',
		'description',
		'order',
		'settingsUrl',
		'requiredConfig',
		'adapter',
		'available',
		'unavailableMessage',
		'unconfiguredMessage',
		'sourceTemplate',
		'reportedOnly',
	];

	/**
	 * The string fields, which the schema types as strings.
	 *
	 * @var array<int, string>
	 */
	private const STRING_FIELDS = [
		'key',
		'title',
		'description',
		'settingsUrl',
		'unavailableMessage',
		'unconfiguredMessage',
		'sourceTemplate',
	];

	/**
	 * The repository root.
	 *
	 * @return string
	 */
	private function root(): string {
		return dirname(__DIR__, 3);
	}//end root()

	/**
	 * The decoded declaration.
	 *
	 * @return array<string, mixed>
	 */
	private function declaration(): array {
		$raw = file_get_contents($this->root() . '/lib/Settings/connections.json');
		$this->assertIsString(actual: $raw, message: 'lib/Settings/connections.json must exist');

		$decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		$this->assertIsArray(actual: $decoded);

		return $decoded;
	}//end declaration()

	/**
	 * The declared connections, keyed by connection key.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function connectionsByKey(): array {
		$byKey = [];
		foreach ($this->declaration()['connections'] as $connection) {
			$byKey[$connection['key']] = $connection;
		}

		return $byKey;
	}//end connectionsByKey()

	/**
	 * The file names the app it ships in, and nothing else at the top level.
	 *
	 * Integriq refuses a file whose `app` differs from the app it was read from.
	 *
	 * @return void
	 */
	public function testTheFileNamesThisApp(): void {
		$declaration = $this->declaration();
		$infoXml     = simplexml_load_file($this->root() . '/appinfo/info.xml');

		$this->assertNotFalse(condition: $infoXml);
		$this->assertSame(expected: (string)$infoXml->id, actual: $declaration['app']);
		$this->assertMatchesRegularExpression(pattern: '/^[a-z0-9_]+$/', string: $declaration['app']);
		$this->assertSame(expected: ['app', 'connections'], actual: array_keys($declaration));
		$this->assertIsList(array: $declaration['connections']);
	}//end testTheFileNamesThisApp()

	/**
	 * The keys are unique and are exactly the ones the reporter accepts.
	 *
	 * A row is keyed by app and key. A key the reporter does not know is a row
	 * no check can ever reach, and a key the reporter sends without a row is a
	 * report integriq drops with a warning.
	 *
	 * @return void
	 */
	public function testTheKeysAreUniqueAndAreTheOnesTheReporterAccepts(): void {
		$keys = array_column($this->declaration()['connections'], 'key');

		$this->assertSame(expected: array_values(array_unique($keys)), actual: $keys, message: 'a key is declared twice');
		$this->assertSame(expected: ConnectionReportService::KEYS, actual: $keys);
	}//end testTheKeysAreUniqueAndAreTheOnesTheReporterAccepts()

	/**
	 * Every entry has the shape integriq's schema validates.
	 *
	 * @return void
	 */
	public function testEveryEntryHasTheShapeIntegriqValidates(): void {
		$previousOrder = PHP_INT_MIN;
		foreach ($this->declaration()['connections'] as $connection) {
			$key = (string)($connection['key'] ?? '');

			$this->assertSame(
				expected: [],
				actual: array_values(array_diff(array_keys($connection), self::ALLOWED_FIELDS)),
				message: $key . ' carries a field D2 does not allow'
			);
			$this->assertMatchesRegularExpression(pattern: '/^[a-z0-9]+(-[a-z0-9]+)*$/', string: $key);
			foreach (self::STRING_FIELDS as $field) {
				if (array_key_exists($field, $connection) === true) {
					$this->assertIsString(actual: $connection[$field], message: $key . '.' . $field);
				}
			}

			$this->assertNotSame(expected: '', actual: trim((string)($connection['title'] ?? '')), message: $key . ' has no title');
			$this->assertIsInt(actual: $connection['order']);
			$this->assertGreaterThan(expected: $previousOrder, actual: $connection['order'], message: $key . ' breaks the page order');
			$previousOrder = $connection['order'];

			if (array_key_exists('settingsUrl', $connection) === true) {
				$this->assertStringStartsWith(prefix: '/', string: $connection['settingsUrl'], message: $key);
			}

			if (array_key_exists('requiredConfig', $connection) === true) {
				$this->assertIsList(array: $connection['requiredConfig']);
				foreach ($connection['requiredConfig'] as $configKey) {
					$this->assertIsString(actual: $configKey);
					$this->assertNotSame(expected: '', actual: $configKey);
				}
			}

			foreach (['available', 'reportedOnly'] as $field) {
				if (array_key_exists($field, $connection) === true) {
					$this->assertIsBool(actual: $connection[$field], message: $key . '.' . $field);
				}
			}
		}//end foreach
	}//end testEveryEntryHasTheShapeIntegriqValidates()

	/**
	 * No entry declares an adapter seam, because no pipelinq connection picks
	 * its adapter with an app-config key.
	 *
	 * An `adapter.configKey` naming a key nothing reads would make integriq
	 * show Simulated forever (contract D4 rule 3) about a connection that is
	 * real. That is the untruth this page exists to stop.
	 *
	 * @return void
	 */
	public function testNoEntryDeclaresAnAdapterSeamNothingReads(): void {
		foreach ($this->declaration()['connections'] as $connection) {
			$this->assertArrayNotHasKey(key: 'adapter', array: $connection, message: (string)$connection['key']);
		}
	}//end testNoEntryDeclaresAnAdapterSeamNothingReads()

	/**
	 * Only CTI is reported-only, because only its platform lives outside app config.
	 *
	 * CTI picks its platform with a field on the `ctiAdapterConfig` object in
	 * OpenRegister, so integriq cannot judge it and must skip rules 3 and 5
	 * (contract D4, hydra#673). The other rows keep those rules: Berichtenbox
	 * needs rule 5 to read Configured once its four keys are set, and the
	 * social and mail rows carry no config keys, so the flag would say nothing.
	 *
	 * @return void
	 */
	public function testOnlyCtiIsReportedOnly(): void {
		$reportedOnly = array_filter(
			$this->declaration()['connections'],
			static fn (array $connection): bool => ($connection['reportedOnly'] ?? false) === true
		);

		$this->assertSame(expected: ['cti'], actual: array_column($reportedOnly, 'key'));
		$this->assertArrayNotHasKey(key: 'requiredConfig', array: $this->connectionsByKey()['cti']);
	}//end testOnlyCtiIsReportedOnly()

	/**
	 * No text a reader sees carries an em-dash (voice rule 8).
	 *
	 * @return void
	 */
	public function testNoTextCarriesAnEmDash(): void {
		$raw = (string)file_get_contents($this->root() . '/lib/Settings/connections.json');

		$this->assertStringNotContainsString(needle: "\u{2014}", haystack: $raw);
		$this->assertStringNotContainsString(needle: ' -- ', haystack: $raw);
	}//end testNoTextCarriesAnEmDash()

	/**
	 * Every settings link lands on a section or a page that really exists.
	 *
	 * A link into a section that does not exist scrolls nowhere and logs
	 * nothing, which is the untruth this page exists to stop.
	 *
	 * @return void
	 */
	public function testEverySettingsLinkLandsOnSomethingThatExists(): void {
		$anchors = [
			'/settings/admin/pipelinq#section-cti' => 'src/views/settings/CtiPage.vue',
			'/settings/admin/pipelinq#section-mail-transports' => 'src/views/settings/DeliverabilitySettings.vue',
		];
		$socialFragment = (string)file_get_contents($this->root() . '/src/manifest.d/78-social-publishing.json');
		$linked         = 0;

		foreach ($this->declaration()['connections'] as $connection) {
			if (array_key_exists('settingsUrl', $connection) === false) {
				continue;
			}

			$url = (string)$connection['settingsUrl'];
			$linked++;

			if (str_starts_with($url, '/apps/pipelinq/') === true) {
				$route = substr($url, strlen('/apps/pipelinq'));
				$this->assertStringContainsString(
					needle: '"route": "' . $route . '"',
					haystack: $socialFragment,
					message: $connection['key'] . ' links to a route no page declares'
				);
				continue;
			}

			$this->assertArrayHasKey(key: $url, array: $anchors, message: $connection['key'] . ' links somewhere this test does not know');
			$anchor = substr($url, (int)strpos($url, '#') + 1);
			$this->assertStringContainsString(
				needle: 'id="' . $anchor . '"',
				haystack: (string)file_get_contents($this->root() . '/' . $anchors[$url]),
				message: $connection['key'] . ' links to a missing section'
			);
		}//end foreach

		$this->assertSame(expected: 9, actual: $linked);
		$this->assertArrayNotHasKey(key: 'settingsUrl', array: $this->connectionsByKey()['berichtenbox']);
	}//end testEverySettingsLinkLandsOnSomethingThatExists()

	/**
	 * Berichtenbox requires exactly the keys its dispatch path reads.
	 *
	 * Integriq reads Configured once every key is filled (contract D4 rule 5).
	 * A key missing here would read Configured while a dispatch still refuses;
	 * a key added here that the code never reads would keep the row Not
	 * configured for nothing.
	 *
	 * @return void
	 */
	public function testBerichtenboxRequiresTheKeysItsDispatchReads(): void {
		$berichtenbox = $this->connectionsByKey()['berichtenbox'];
		$required     = $berichtenbox['requiredConfig'];

		$this->assertSame(
			expected: [LogiusConnector::CONFIG_CLIENT_ID, LogiusConnector::CONFIG_CLIENT_SECRET, 'pki_cert', 'pki_key'],
			actual: $required
		);

		$service = (string)file_get_contents($this->root() . '/lib/Service/BerichtenboxService.php');
		foreach (['pki_cert', 'pki_key'] as $key) {
			$this->assertStringContainsString(needle: "'" . $key . "'", haystack: $service);
		}

		foreach ($required as $key) {
			$this->assertStringContainsString(needle: $key, haystack: (string)$berichtenbox['unconfiguredMessage']);
		}
	}//end testBerichtenboxRequiresTheKeysItsDispatchReads()
}//end class
