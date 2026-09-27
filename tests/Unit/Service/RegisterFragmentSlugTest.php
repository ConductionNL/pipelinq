<?php

/**
 * Every schema the register configuration declares must carry a slug.
 *
 * OpenRegister's ImportHandler rejects a schema fragment without a non-empty
 * `slug` (it logs "imported schema fragment is missing a 'slug'" and skips
 * it), and the rest of the import carries on. On 2026-09-26 that silently
 * dropped sixteen schemas across four fragments (party kinds, party fields
 * and indicators, customer satisfaction, programme portfolio) while the
 * re-import reported success. A fragment that only OVERRIDES a schema the
 * monolith already names inherits the monolith's slug through the deep
 * merge, so the second test checks the merged payload, which is what
 * OpenRegister actually receives; the first test checks every file, so the
 * failure names the fragment to fix.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/openregister-integration/spec.md#requirement-register-configuration-file
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ConfigFileLoaderService;
use OCA\Pipelinq\Service\SettingsLoadService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class RegisterFragmentSlugTest extends TestCase {

	private const APP_ROOT = __DIR__ . '/../../..';

	/**
	 * Every schema entry, in the monolith and in every fragment, names its own key as slug.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/openregister-integration/spec.md#requirement-register-configuration-file
	 */
	public function testEverySchemaEntryInEveryFileCarriesItsKeyAsSlug(): void {
		$fragments = glob(self::APP_ROOT . '/lib/Settings/register.d/*.json');
		if ($fragments === false) {
			$fragments = [];
		}

		$files = array_merge([self::APP_ROOT . '/lib/Settings/pipelinq_register.json'], $fragments);
		$this->assertGreaterThan(expected: 10, actual: count($files), message: 'Positive control: the fragment directory was found.');

		$seen = 0;
		$offenders = [];
		foreach ($files as $file) {
			$data = json_decode((string)file_get_contents($file), true);
			$this->assertIsArray(actual: $data, message: basename($file) . ' is valid JSON');
			foreach (($data['components']['schemas'] ?? []) as $key => $schema) {
				$seen++;
				if (($schema['slug'] ?? null) !== $key) {
					$offenders[] = basename($file) . ': ' . $key . ' (slug ' . var_export($schema['slug'] ?? null, true) . ')';
				}
			}
		}

		// Positive control: a loop that visited nothing would also report no offenders.
		$this->assertGreaterThan(expected: 100, actual: $seen);
		$this->assertSame(
			expected: [],
			actual: $offenders,
			message: "Schema entries whose slug is missing or differs from their key:\n" . implode("\n", $offenders)
		);
	}//end testEverySchemaEntryInEveryFileCarriesItsKeyAsSlug()

	/**
	 * The merged payload OpenRegister receives has a slug on every schema.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/openregister-integration/spec.md#requirement-register-configuration-file
	 */
	public function testMergedConfigurationHasNoSchemaWithoutSlug(): void {
		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('getAppPath')->willReturn(realpath(self::APP_ROOT));

		$merged = (new ConfigFileLoaderService(appManager: $appManager))->loadConfigurationFile();
		$schemas = $merged['components']['schemas'] ?? [];

		// Positive controls: the merge produced the fragments' schemas, including one of the sixteen that went dark.
		$this->assertGreaterThan(expected: 100, actual: count($schemas));
		$this->assertArrayHasKey(key: 'partyFieldSet', array: $schemas);

		$withoutSlug = array_keys(array_filter(
			$schemas,
			static fn (array $schema): bool => is_string($schema['slug'] ?? null) === false || trim($schema['slug']) === ''
		));
		$this->assertSame(expected: [], actual: $withoutSlug, message: 'OpenRegister rejects these schemas: ' . implode(', ', $withoutSlug));
	}//end testMergedConfigurationHasNoSchemaWithoutSlug()

	/**
	 * The install writes a config key for each of the sixteen schemas that went dark.
	 *
	 * Importing a schema is half the job: the services find it through the
	 * `<slug>_schema` app-config key that SettingsLoadService writes for every
	 * slug in SCHEMA_SLUGS, and thirteen of the sixteen were not listed there.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/openregister-integration/spec.md#requirement-register-configuration-file
	 */
	public function testTheInstallWritesAConfigKeyForEveryFormerlyRejectedSchema(): void {
		$reflection = new ReflectionClass(SettingsLoadService::class);
		$slugs = $reflection->getConstant('SCHEMA_SLUGS');
		$this->assertIsArray(actual: $slugs);

		$formerlyRejected = [
			'partyKind',
			'partyKindAcceptance',
			'partyLink',
			'partyFieldSet',
			'partyIndicator',
			'partyIndicatorValue',
			'survey',
			'surveyInvitation',
			'surveyResponse',
			'deliveryProgramme',
			'programmeTask',
			'programmeTeamMember',
			'programmeWorkItem',
			'estimationScale',
			'programmeEstimate',
			'programmeCycle',
		];
		$missing = array_values(array_diff($formerlyRejected, $slugs));
		$this->assertSame(expected: [], actual: $missing, message: 'Not in SCHEMA_SLUGS, so their config key is never written: ' . implode(', ', $missing));

		// The services read `programme_schema` for the delivery programme; the mapping must say so.
		$this->assertSame(expected: 'programme_schema', actual: SettingsLoadService::SCHEMA_CONFIG_KEYS['deliveryProgramme'] ?? null);
	}//end testTheInstallWritesAConfigKeyForEveryFormerlyRejectedSchema()
}//end class
