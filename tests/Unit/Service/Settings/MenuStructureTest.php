<?php

/**
 * Unit tests for the structure settings.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation-ia/spec.md#REQ-NIA-101
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Settings;

use OCA\Pipelinq\Controller\DashboardController;
use OCA\Pipelinq\Service\DefaultPipelineService;
use OCA\Pipelinq\Service\DefaultSkillService;
use OCA\Pipelinq\Service\Settings\MenuStructure;
use OCA\Pipelinq\Service\SettingsLoadService;
use OCA\Pipelinq\Service\SettingsService;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the structure settings.
 */
final class MenuStructureTest extends TestCase {
	/**
	 * An unset key, an empty string and a typing mistake all read as simple.
	 *
	 * @return void
	 */
	public function testAnythingThatIsNotFullReadsAsSimple(): void {
		foreach (['', 'simple', 'Simple', 'ful', 'uitgebreid', 'yes', '1'] as $stored) {
			$this->assertSame(
				MenuStructure::SIMPLE,
				(new MenuStructure())->normalise($stored),
				sprintf("'%s' should read as the simple structure.", $stored),
			);
		}
	}//end testAnythingThatIsNotFullReadsAsSimple()

	/**
	 * The word full, however an operator types it into occ, brings the full one back.
	 *
	 * @return void
	 */
	public function testTheWordFullBringsTheFullStructureBack(): void {
		foreach (['full', 'FULL', ' full '] as $stored) {
			$this->assertSame(MenuStructure::FULL, (new MenuStructure())->normalise($stored));
		}
	}//end testTheWordFullBringsTheFullStructureBack()

	/**
	 * Only known modules are switched on, in the declared order.
	 *
	 * @return void
	 */
	public function testOnlyKnownModulesAreSwitchedOn(): void {
		$structure = new MenuStructure();

		$this->assertSame('', $structure->normaliseModules(''));
		$this->assertSame('sales,loyalty', $structure->normaliseModules(' Loyalty , sales,verkoop,'));
		$this->assertSame(
			implode(',', MenuStructure::MODULES),
			$structure->normaliseModules(implode(',', array_reverse(MenuStructure::MODULES)))
		);
	}//end testOnlyKnownModulesAreSwitchedOn()

	/**
	 * The page asks for its own keys and an instance that never set them gets the defaults.
	 *
	 * A mock of `getValueString()` answers whatever it is told to, so this
	 * records what the controller ASKED and hands the default back, the way an
	 * instance that never set the keys does.
	 *
	 * @return void
	 */
	public function testAnInstanceThatNeverSetTheKeysShowsTheSimpleStructureWithNoModules(): void {
		$asked = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use (&$asked): string {
				$asked[] = [$app, $key];

				return $default;
			}
		);

		$this->assertSame(
			[MenuStructure::KEY => MenuStructure::SIMPLE, MenuStructure::MODULES_KEY => ''],
			$this->initialStateFrom(appConfig: $appConfig)
		);
		$this->assertSame([['pipelinq', MenuStructure::KEY], ['pipelinq', MenuStructure::MODULES_KEY]], $asked);
	}//end testAnInstanceThatNeverSetTheKeysShowsTheSimpleStructureWithNoModules()

	/**
	 * Stored values reach the page, read through the normalisers.
	 *
	 * @return void
	 */
	public function testStoredValuesReachThePage(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnMap([
			['pipelinq', MenuStructure::KEY, '', false, 'FULL'],
			['pipelinq', MenuStructure::MODULES_KEY, '', false, 'pos, nonsense, sales'],
		]);

		$this->assertSame(
			[MenuStructure::KEY => MenuStructure::FULL, MenuStructure::MODULES_KEY => 'sales,pos'],
			$this->initialStateFrom(appConfig: $appConfig)
		);
	}//end testStoredValuesReachThePage()

	/**
	 * The settings endpoint stores both keys, or the admin section saves into nothing.
	 *
	 * `SettingsService::updateSettings()` writes only the keys on its own list
	 * and answers success for the rest, so a key missing there is a save that
	 * reports done and changes nothing. This drives the real service and
	 * records what it wrote.
	 *
	 * @return void
	 */
	public function testTheSettingsWriteStoresBothKeys(): void {
		$written = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) use (&$written): bool {
				$written[$key] = $value;

				return true;
			}
		);

		$service = new SettingsService(
			$appConfig,
			$this->createMock(IConfig::class),
			$this->createMock(SettingsLoadService::class),
			$this->createMock(DefaultPipelineService::class),
			$this->createMock(DefaultSkillService::class),
			$this->createMock(LoggerInterface::class),
		);
		$config = $service->updateSettings([
			MenuStructure::KEY => MenuStructure::FULL,
			MenuStructure::MODULES_KEY => 'sales,pos',
		]);

		$this->assertSame(
			[MenuStructure::KEY => MenuStructure::FULL, MenuStructure::MODULES_KEY => 'sales,pos'],
			$written
		);
		$this->assertArrayHasKey(MenuStructure::KEY, $config);
		$this->assertArrayHasKey(MenuStructure::MODULES_KEY, $config);
	}//end testTheSettingsWriteStoresBothKeys()

	/**
	 * The PHP half and the JavaScript half use the same keys and the same words.
	 *
	 * @return void
	 */
	public function testBothHalvesSpellTheSettingsTheSameWay(): void {
		$root = __DIR__ . '/../../../../src/';
		$profile = file_get_contents($root . 'utils/structureProfile.js');
		$modules = file_get_contents($root . 'utils/menuModules.js');
		$this->assertIsString($profile);
		$this->assertIsString($modules);

		foreach ([
			'STRUCTURE_SETTING' => [$profile, MenuStructure::KEY],
			'STRUCTURE_SIMPLE' => [$profile, MenuStructure::SIMPLE],
			'STRUCTURE_FULL' => [$profile, MenuStructure::FULL],
			'MODULES_SETTING' => [$modules, MenuStructure::MODULES_KEY],
		] as $constant => [$source, $expected]) {
			$matched = preg_match('/export const ' . $constant . " = '([^']+)'/", $source, $matches);
			$this->assertSame(1, $matched, sprintf('The frontend no longer declares %s.', $constant));
			$this->assertSame($expected, $matches[1], sprintf('%s differs between PHP and JavaScript.', $constant));
		}
	}//end testBothHalvesSpellTheSettingsTheSameWay()

	/**
	 * The module list is the one the simple profile file declares, in its order.
	 *
	 * @return void
	 */
	public function testTheModuleListIsTheProfileFilesOwn(): void {
		$file = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../../src/menu-layout.simple.json'),
			true,
			512,
			JSON_THROW_ON_ERROR
		);

		$this->assertSame(MenuStructure::MODULES, array_keys($file['modules']));
	}//end testTheModuleListIsTheProfileFilesOwn()

	/**
	 * Render the page and return the initial state it provided.
	 *
	 * @param IAppConfig $appConfig The app config to read from.
	 *
	 * @return array<string, mixed> The initial state, by key.
	 */
	private function initialStateFrom(IAppConfig $appConfig): array {
		$provided = [];
		$initialState = $this->createMock(IInitialState::class);
		$initialState->method('provideInitialState')->willReturnCallback(
			function (string $key, mixed $value) use (&$provided): void {
				$provided[$key] = $value;
			}
		);

		$controller = new DashboardController(
			$this->createMock(IRequest::class),
			$initialState,
			$appConfig,
			new MenuStructure(),
		);
		$controller->page();

		return $provided;
	}//end initialStateFrom()
}//end class
