<?php

/**
 * Saving the settings, and every import, writes the CRM groups into the registers' access rules.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/close-pipelinq-register-to-staff/specs/register-access/spec.md#requirement-the-configured-crm-group-is-granted-wherever-the-staff-group-is
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\Access\RegisterAccessSync;
use OCA\Pipelinq\Service\DefaultPipelineService;
use OCA\Pipelinq\Service\DefaultSkillService;
use OCA\Pipelinq\Service\SettingsLoadService;
use OCA\Pipelinq\Service\SettingsService;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Pipelinq\Service\SettingsService
 *
 * @spec openspec/changes/close-pipelinq-register-to-staff/specs/register-access/spec.md#requirement-the-configured-crm-group-is-granted-wherever-the-staff-group-is
 */
class SettingsServiceRegisterAccessTest extends TestCase {

	/**
	 * The stored app config, as the double keeps it.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	/**
	 * Build the service over a config double and a sync double.
	 *
	 * @param RegisterAccessSync $sync The sync double.
	 *
	 * @return SettingsService
	 */
	private function service(RegisterAccessSync $sync): SettingsService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default=''): string => ($this->stored[$key] ?? $default));
		$config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->stored[$key] = $value;
			return true;
		});
		$load = $this->createMock(SettingsLoadService::class);
		$load->method('loadSettings')->willReturn(['ok' => true]);

		return new SettingsService(
			$config,
			$this->createMock(IConfig::class),
			$load,
			$this->createMock(DefaultPipelineService::class),
			$this->createMock(DefaultSkillService::class),
			$this->createMock(LoggerInterface::class),
			$sync,
		);
	}//end service()

	/**
	 * Saving the settings applies the CRM groups; the sync itself skips when nothing changed.
	 *
	 * @return void
	 */
	public function testSavingTheSettingsAppliesTheCrmGroups(): void {
		$sync = $this->createMock(RegisterAccessSync::class);
		$sync->expects($this->once())->method('sync')->with();

		$this->service(sync: $sync)->updateSettings(['crm_read_group' => 'dossiq-medewerkers']);

		$this->assertSame('dossiq-medewerkers', $this->stored['crm_read_group'], 'crm_read_group is a setting the screen saves');
	}//end testSavingTheSettingsAppliesTheCrmGroups()

	/**
	 * Every import is followed by a forced sync, because the import writes the file's rules back.
	 *
	 * @return void
	 */
	public function testEveryImportIsFollowedByAForcedSync(): void {
		$sync = $this->createMock(RegisterAccessSync::class);
		$sync->expects($this->once())->method('sync')->with(true);

		$this->assertSame(['ok' => true], $this->service(sync: $sync)->loadSettings());
	}//end testEveryImportIsFollowedByAForcedSync()

	/**
	 * The read group is offered with the other settings, empty by default.
	 *
	 * @return void
	 */
	public function testTheReadGroupIsASettingEmptyByDefault(): void {
		$settings = $this->service(sync: $this->createMock(RegisterAccessSync::class))->getSettings();

		$this->assertArrayHasKey('crm_read_group', $settings);
		$this->assertSame('', $settings['crm_read_group']);
	}//end testTheReadGroupIsASettingEmptyByDefault()
}//end class
