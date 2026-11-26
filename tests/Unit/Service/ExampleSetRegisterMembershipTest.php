<?php

/**
 * Guards the pipelinq register's schema membership against what the app writes
 * into it: the Zuiddrecht example set and the ticket supertype.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/archive/2026-11-26-example-set-zuiddrecht/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ConfigFileLoaderService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * The merged register (monolith plus every register.d fragment) lists each
 * schema the app's example data puts in the `pipelinq` register.
 *
 * @covers \OCA\Pipelinq\Service\ConfigFileLoaderService
 */
class ExampleSetRegisterMembershipTest extends TestCase {

	/**
	 * The real register, merged the way the import reads it.
	 *
	 * @return array<string, mixed>
	 */
	private function mergedRegister(): array {
		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('getAppPath')->willReturn(dirname(__DIR__, 3));

		return (new ConfigFileLoaderService(appManager: $appManager))->loadConfigurationFile();
	}//end mergedRegister()

	/**
	 * The schemas the `pipelinq` register lists.
	 *
	 * @param array<string, mixed> $register The merged register.
	 *
	 * @return list<string>
	 */
	private static function members(array $register): array {
		return array_values((array)($register['components']['registers']['pipelinq']['schemas'] ?? []));
	}//end members()

	/**
	 * The ticket supertype is a member of the register its tickets are saved in.
	 *
	 * @return void
	 */
	public function testTheTicketSchemaIsAMemberOfThePipelinqRegister(): void {
		$register = $this->mergedRegister();

		self::assertArrayHasKey(key: 'ticket', array: $register['components']['schemas']);
		self::assertContains(needle: 'ticket', haystack: self::members(register: $register));
	}//end testTheTicketSchemaIsAMemberOfThePipelinqRegister()

	/**
	 * The programme schemas are members of the register the programme pages read.
	 *
	 * @return void
	 */
	public function testTheProgrammeSchemasAreMembersOfThePipelinqRegister(): void {
		$members = self::members(register: $this->mergedRegister());

		$programme = [
			'deliveryProgramme',
			'programmeTask',
			'programmeTeamMember',
			'programmeWorkItem',
			'estimationScale',
			'programmeEstimate',
			'programmeCycle',
		];
		foreach ($programme as $schema) {
			self::assertContains(
				needle: $schema,
				haystack: $members,
				message: $schema . ' is not in the pipelinq register'
			);
		}
	}//end testTheProgrammeSchemasAreMembersOfThePipelinqRegister()

	/**
	 * Every object of the Zuiddrecht fragment names a schema the register lists.
	 *
	 * @return void
	 */
	public function testEveryZuiddrechtObjectNamesAMemberSchema(): void {
		$members = self::members(register: $this->mergedRegister());
		$set     = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/example-sets/zuiddrecht.json'), true);

		$used = [];
		foreach ((array)($set['objects'] ?? []) as $object) {
			if (($object['register'] ?? null) === 'pipelinq') {
				$used[(string)$object['schema']] = true;
			}
		}

		self::assertNotSame(expected: [], actual: $used);
		self::assertSame(expected: [], actual: array_values(array_diff(array_keys($used), $members)));
	}//end testEveryZuiddrechtObjectNamesAMemberSchema()
}//end class
