<?php

/**
 * Update notification rules exist and address real owner and assignee fields.
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
 *
 * @spec openspec/changes/review-audit-fixes-b/specs/notifications/spec.md#requirement-an-update-notifies-the-objects-owner-and-assignee-req-raf-060
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ConfigFileLoaderService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Audit item E1: changing a client, lead, ticket or task tells its owner and
 * assignee. Read from the register as the app ships it (base file plus every
 * register.d fragment, merged the way ConfigFileLoaderService merges them).
 *
 * @spec openspec/changes/review-audit-fixes-b/specs/notifications/spec.md#requirement-an-update-notifies-the-objects-owner-and-assignee-req-raf-060
 */
class UpdateNotificationRulesTest extends TestCase {
	private const APP_ROOT = __DIR__ . '/../../..';

	/**
	 * The owner and assignee fields each schema's update rule must address.
	 */
	private const EXPECTED = [
		'client' => ['accountOwner'],
		'lead' => ['assignee'],
		'ticket' => ['assignee'],
		'crmTask' => ['assigneeUserId'],
	];

	/**
	 * The merged register schemas.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function schemas(): array {
		$merge = new ReflectionMethod(ConfigFileLoaderService::class, 'deepMergeConfig');
		$merge->setAccessible(true);

		$config = json_decode((string)file_get_contents(self::APP_ROOT . '/lib/Settings/pipelinq_register.json'), true);
		$fragments = glob(self::APP_ROOT . '/lib/Settings/register.d/*.json');
		sort($fragments);
		foreach ($fragments as $file) {
			$fragment = json_decode((string)file_get_contents($file), true);
			unset($fragment['_meta']);
			$config = $merge->invoke(null, $config, $fragment, '');
		}

		return $config['components']['schemas'];
	}//end schemas()

	/**
	 * The fields addressed by a schema's `updated` rules.
	 *
	 * @param array<string, mixed> $schema The schema.
	 *
	 * @return array<int, string>
	 */
	private static function updateRecipientFields(array $schema): array {
		$fields = [];
		foreach (($schema['x-openregister-notifications'] ?? []) as $rule) {
			if (($rule['trigger']['type'] ?? null) !== 'updated' || ($rule['enabled'] ?? true) !== true) {
				continue;
			}

			foreach (($rule['recipients'] ?? []) as $recipient) {
				if (($recipient['kind'] ?? null) === 'field') {
					$fields[] = (string)$recipient['field'];
				}
			}
		}

		return $fields;
	}//end updateRecipientFields()

	/**
	 * Each main schema has an enabled `updated` rule to its owner and assignee.
	 *
	 * Fails on the old register, which declared no `updated` rule at all.
	 *
	 * @return void
	 */
	public function testMainSchemasNotifyOwnerAndAssigneeOnUpdate(): void {
		$schemas = $this->schemas();
		foreach (self::EXPECTED as $slug => $fields) {
			$this->assertArrayHasKey($slug, $schemas);
			$this->assertSame($fields, self::updateRecipientFields(schema: $schemas[$slug]), "Update recipients of $slug");
		}
	}//end testMainSchemasNotifyOwnerAndAssigneeOnUpdate()

	/**
	 * Every field an `updated` rule addresses is a real user field of its
	 * schema; a misspelt field would resolve to nobody without an error.
	 *
	 * @return void
	 */
	public function testUpdateRecipientsAreRealUserFields(): void {
		$checked = 0;
		foreach ($this->schemas() as $slug => $schema) {
			foreach (self::updateRecipientFields(schema: $schema) as $field) {
				$checked++;
				$this->assertArrayHasKey($field, $schema['properties'] ?? [], "$slug.$field exists");
				$this->assertSame('user', $schema['properties'][$field]['format'] ?? null, "$slug.$field holds a user id");
			}
		}

		$this->assertGreaterThanOrEqual(4, $checked, 'Positive control: the rules were found.');
	}//end testUpdateRecipientsAreRealUserFields()

	/**
	 * The rules use the canonical dialect: a trigger object, the in-app
	 * channel and a subject in English and Dutch.
	 *
	 * @return void
	 */
	public function testUpdateRulesUseTheCanonicalDialect(): void {
		$schemas = $this->schemas();
		foreach (array_keys(self::EXPECTED) as $slug) {
			foreach ($schemas[$slug]['x-openregister-notifications'] as $name => $rule) {
				if (($rule['trigger']['type'] ?? null) !== 'updated') {
					continue;
				}

				$this->assertSame(['nc-notification'], $rule['channels'], "$slug.$name channels");
				$this->assertNotEmpty($rule['subject']['en'] ?? null, "$slug.$name English subject");
				$this->assertNotEmpty($rule['subject']['nl'] ?? null, "$slug.$name Dutch subject");
			}
		}
	}//end testUpdateRulesUseTheCanonicalDialect()
}//end class
