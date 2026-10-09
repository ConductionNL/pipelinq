<?php

/**
 * Unit tests: the register half of the round 3 review points.
 *
 * Each assertion reads the merged register (monolith plus every fragment in
 * register.d, in the loader's own order), which is what OpenRegister imports.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Settings
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/round3-review-points/tasks.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use OCA\Pipelinq\Service\ConfigFileLoaderService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Pipelinq\Service\ConfigFileLoaderService
 */
class ReviewRound3RegisterTest extends TestCase {
	/**
	 * The merged register's schemas.
	 *
	 * @var array<string, mixed>
	 */
	private array $schemas;

	/**
	 * Load the merged register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn(dirname(__DIR__, 3));
		$config = (new ConfigFileLoaderService($appManager))->loadConfigurationFile();
		$this->schemas = $config['components']['schemas'];
	}//end setUp()

	/**
	 * Name, email and phone of a client and a contact are not read-only.
	 *
	 * OpenRegister refuses any change to a `readOnly: true` property on
	 * update, so the client Edit dialog failed with "Cannot modify readOnly
	 * property: phone" (review point 1).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/round3-review-points/specs/client-forms/spec.md
	 */
	public function testPartyIdentityIsEditable(): void {
		$readOnly = [];
		foreach (['client', 'contact'] as $schema) {
			foreach (['name', 'email', 'phone'] as $field) {
				if (($this->schemas[$schema]['properties'][$field]['readOnly'] ?? false) === true) {
					$readOnly[] = $schema . '.' . $field;
				}
			}
		}

		$this->assertSame([], $readOnly);
	}//end testPartyIdentityIsEditable()

	/**
	 * A line item's name is a template, which OpenRegister resolves to the product's name.
	 *
	 * MetadataHydrationHandler only resolves a relation uuid inside a
	 * `{{ field }}` template; a bare field path keeps the raw uuid (review point 4).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/round3-review-points/specs/lead-product-link/spec.md
	 */
	public function testLineItemIsNamedThroughATemplate(): void {
		$leadProduct = $this->schemas['leadProduct'];
		$this->assertMatchesRegularExpression(
			'/^\{\{\s*product\s*\}\}$/',
			(string)($leadProduct['configuration']['objectNameField'] ?? '')
		);
		// The template only resolves when the field is a relation.
		$this->assertSame('uuid', $leadProduct['properties']['product']['format'] ?? null);
	}//end testLineItemIsNamedThroughATemplate()

	/**
	 * Every notification rule opens its record, and the action fragment names no unknown rule.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/round3-review-points/specs/notifications/spec.md
	 */
	public function testEveryNotificationRuleOpensItsRecord(): void {
		$missing = [];
		$partial = [];
		$count = 0;
		foreach ($this->schemas as $slug => $schema) {
			foreach (($schema['x-openregister-notifications'] ?? []) as $ruleId => $rule) {
				$count++;
				if (isset($rule['trigger']) === false) {
					$partial[] = $slug . '.' . $ruleId;
				}

				$opens = false;
				foreach (($rule['actions'] ?? []) as $action) {
					if (($action['target']['kind'] ?? '') === 'object-detail'
						&& ($action['label']['en'] ?? '') !== ''
						&& ($action['label']['nl'] ?? '') !== ''
					) {
						$opens = true;
					}
				}

				if ($opens === false) {
					$missing[] = $slug . '.' . $ruleId;
				}
			}//end foreach
		}//end foreach

		$this->assertGreaterThan(0, $count, 'the register declares notification rules');
		$this->assertSame([], $missing, 'rules without an Open action');
		$this->assertSame([], $partial, 'rules with an action but no trigger');
	}//end testEveryNotificationRuleOpensItsRecord()
}//end class
