<?php

/**
 * The install writes every schema key the party panel reads.
 *
 * The party indicator model (pipelinq#1973) read `partyIndicator_schema`,
 * `partyIndicatorValue_schema` and `partyFieldSet_schema`, but the three slugs
 * were never added to SettingsLoadService::SCHEMA_SLUGS, so the install never
 * wrote those keys and every panel answered "no indicators" on a live
 * instance (pipelinq#2036). This runs the REAL writer and checks every key the
 * two readers spell, so a reader that asks for a key the install does not
 * write fails here.
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use ArrayObject;
use OCA\Pipelinq\Integration\PartyLeafProvider;
use OCA\Pipelinq\Service\PartyIndicatorService;
use OCA\Pipelinq\Service\SettingsLoadService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Keys read by the party panel against keys written by the install.
 */
class PartyIndicatorSchemaKeysTest extends TestCase {
	/**
	 * Every `<x>_schema` key the party readers spell is written by the install.
	 *
	 * @return void
	 */
	public function testEverySchemaKeyThePartyPanelReadsIsWritten(): void {
		$written = $this->keysTheInstallWrites();

		$read = [];
		foreach ([PartyIndicatorService::class, PartyLeafProvider::class] as $reader) {
			$source = (string)file_get_contents((string)(new ReflectionClass($reader))->getFileName());
			preg_match_all("/'([A-Za-z]+_schema)'/", $source, $matches);
			$read = array_merge($read, $matches[1]);
		}

		// Positive control: a regex that matches nothing would read as agreement.
		$this->assertContains('partyIndicator_schema', $read);
		$this->assertContains('partyIndicatorValue_schema', $read);
		$this->assertContains('partyFieldSet_schema', $read);

		foreach (array_unique($read) as $key) {
			$this->assertContains($key, $written, "the party panel reads '{$key}', which the install never writes");
		}
	}//end testEverySchemaKeyThePartyPanelReadsIsWritten()

	/**
	 * Run the real applySchemaConfig() over every slug and collect the keys.
	 *
	 * @return array<int, string> The app-config keys written.
	 */
	private function keysTheInstallWrites(): array {
		$store = new ArrayObject();
		$config = $this->createMock(IAppConfig::class);
		$config->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use ($store): bool {
				$store[$key] = $value;
				return true;
			}
		);

		$loader = new ReflectionClass(SettingsLoadService::class);
		$writer = $loader->newInstanceWithoutConstructor();
		$loader->getProperty('appConfig')->setValue($writer, $config);

		$schemaMap = [];
		foreach ((array)$loader->getConstant('SCHEMA_SLUGS') as $slug) {
			$schemaMap[$slug] = 'id-' . $slug;
		}

		$loader->getMethod('applySchemaConfig')->invoke($writer, $schemaMap);

		return array_keys($store->getArrayCopy());
	}//end keysTheInstallWrites()
}//end class
