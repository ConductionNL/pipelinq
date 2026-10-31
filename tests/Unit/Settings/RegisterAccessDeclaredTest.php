<?php

/**
 * The pipelinq registers are closed to everybody outside the staff.
 *
 * Found live on 2026-10-14: a self-registered account in the resident group
 * read all 96 tickets and 49 clients through OpenRegister's objects API,
 * because no register had a block (so 120 schemas were open to every
 * signed-in account) and 8 schemas named `authenticated`. Swept over the
 * fragment-merged register the import ships, so a schema added by any
 * fragment, today or later, is held to it.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit
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
 * @spec openspec/changes/close-pipelinq-register-to-staff/specs/register-access/spec.md#requirement-pipelinqs-registers-are-closed-to-everybody-outside-the-staff
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use OCA\Pipelinq\Service\ConfigFileLoaderService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 *
 * @spec openspec/changes/close-pipelinq-register-to-staff/specs/register-access/spec.md#requirement-pipelinqs-registers-are-closed-to-everybody-outside-the-staff
 */
class RegisterAccessDeclaredTest extends TestCase {

	/**
	 * The staff: the built-in group and the system account.
	 */
	private const STAFF = ['sales', 'user:pipelinq-system'];

	/**
	 * Shared vocabularies other apps read as the signed-in user; no personal data.
	 */
	private const VOCABULARIES = ['partyFieldSet', 'partyIndicator', 'partyKind', 'product', 'productCategory'];

	/**
	 * The merged register configuration.
	 *
	 * @var array<string, mixed>
	 */
	private array $config = [];

	/**
	 * Merge the shipped register the way the import does.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn(dirname(__DIR__, 3));
		$this->config = (new ConfigFileLoaderService($appManager))->loadConfigurationFile();
	}//end setUp()

	/**
	 * Every register grants every verb to the staff only, at a version OpenRegister will write.
	 *
	 * @return void
	 */
	public function testEveryRegisterIsClosedToEverybodyButTheStaff(): void {
		$this->assertSame(['pipelinq', 'pipelinq-portal', 'sla'], array_keys($this->config['components']['registers']));
		foreach ($this->config['components']['registers'] as $slug => $register) {
			foreach (['read', 'create', 'update', 'delete', 'export'] as $verb) {
				$this->assertSame(self::STAFF, $register['authorization'][$verb] ?? null, $slug.'.'.$verb);
			}

			$this->assertTrue(version_compare((string)$register['version'], '1.0.0', '>'), $slug.': OpenRegister writes a register\'s own fields only when its version is newer');
		}
	}//end testEveryRegisterIsClosedToEverybodyButTheStaff()

	/**
	 * No schema opens a verb to every signed-in account, except the vocabularies' read.
	 *
	 * A schema's own block replaces the register's for that schema, so one
	 * `authenticated` here is that schema open again.
	 *
	 * @return void
	 */
	public function testNoSchemaGrantsEverySignedInAccount(): void {
		$open = [];
		foreach ($this->config['components']['schemas'] as $slug => $schema) {
			foreach ((array)($schema['authorization'] ?? []) as $verb => $rules) {
				if (in_array('authenticated', (array)$rules, true) === false) {
					continue;
				}

				if (in_array($slug, self::VOCABULARIES, true) === true && in_array($verb, ['read', 'export'], true) === true) {
					continue;
				}

				$open[] = $slug.'.'.$verb;
			}
		}

		$this->assertSame([], $open);
	}//end testNoSchemaGrantsEverySignedInAccount()

	/**
	 * The vocabularies are readable by every signed-in account and written by the staff only.
	 *
	 * @return void
	 */
	public function testTheVocabulariesAreReadableButWrittenByTheStaff(): void {
		foreach (self::VOCABULARIES as $slug) {
			$authorization = $this->config['components']['schemas'][$slug]['authorization'];
			$this->assertContains('authenticated', $authorization['read'], $slug);
			foreach (['create', 'update', 'delete'] as $verb) {
				$this->assertSame(self::STAFF, $authorization[$verb], $slug.'.'.$verb);
			}
		}
	}//end testTheVocabulariesAreReadableButWrittenByTheStaff()

	/**
	 * The service accounts and the web form keep exactly what they had.
	 *
	 * @return void
	 */
	public function testServiceAccountsAndTheWebFormKeepTheirGrants(): void {
		$schemas = $this->config['components']['schemas'];

		foreach (['ticket', 'channelMessage', 'channelConversation', 'messagingConsentRecord'] as $slug) {
			$this->assertContains('pipelinq-messaging-service', $schemas[$slug]['authorization']['create'], $slug);
			$this->assertContains('pipelinq-messaging-service', $schemas[$slug]['authorization']['update'], $slug);
			$this->assertSame(self::STAFF, $schemas[$slug]['authorization']['read'], $slug);
		}

		foreach (['crmPortalAccount', 'crmPortalSession', 'portalDelegation', 'portalAuditEvent', 'portalTenantConfig'] as $slug) {
			$this->assertSame(['pipelinq-portal-service'], $schemas[$slug]['authorization']['create'], $slug);
			$this->assertSame([], $schemas[$slug]['authorization']['read'], $slug);
		}

		$this->assertContains('public', $schemas['lead']['authorization']['create'], 'the website form still creates a lead');
		$this->assertSame(self::STAFF, $schemas['lead']['authorization']['read']);
	}//end testServiceAccountsAndTheWebFormKeepTheirGrants()

	/**
	 * The schemas the CRM read group reads carry the staff block as their own.
	 *
	 * The read group is granted on a schema's own block; a schema that
	 * followed the register would take it from the register and hand it every
	 * other schema too.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/close-pipelinq-register-to-staff/specs/register-access/spec.md#requirement-a-configured-read-group-reads-the-parties-and-contact-moments-and-writes-nothing
	 */
	public function testTheReadGroupSchemasCarryTheirOwnStaffBlock(): void {
		foreach (\OCA\Pipelinq\Service\Access\RegisterAccessSync::READ_SCHEMAS as $slug) {
			$authorization = $this->config['components']['schemas'][$slug]['authorization'] ?? [];
			foreach (['read', 'export'] as $verb) {
				$this->assertSame(self::STAFF, $authorization[$verb] ?? null, $slug.'.'.$verb);
			}

			// Writes name the staff; ticket also keeps the messaging account's create and update.
			foreach (['create', 'update', 'delete'] as $verb) {
				$this->assertSame(self::STAFF, array_slice($authorization[$verb] ?? [], 0, 2), $slug.'.'.$verb);
			}
		}
	}//end testTheReadGroupSchemasCarryTheirOwnStaffBlock()
}//end class
