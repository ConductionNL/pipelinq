<?php

/**
 * Unit tests: the register descriptor carries reference data only.
 *
 * OpenRegister imports a register descriptor's `components.objects` on every
 * install, upgrade and provisioning run. An administrator who picked "None" in
 * the setup wizard still received 256 example records (Cappuccino, Zonnereizen,
 * the tender leads) because they sat there. Example records now live in the
 * on-demand descriptor; these tests keep them out of the register.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Settings
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use OCA\Pipelinq\Service\ConfigFileLoaderService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Pipelinq\Service\ConfigFileLoaderService
 */
class RegisterCarriesNoExampleDataTest extends TestCase {
	/**
	 * Schemas whose records every instance needs, so the register may seed them.
	 *
	 * Adding a schema here is a product decision: its records arrive on every
	 * production install, whatever the administrator chose.
	 *
	 * @var array<int, string>
	 */
	private const REFERENCE_SCHEMAS = [
		'billingCategory',
		'berichtenboxTemplate',
		'dsarPolicyPack',
		'mailTransport',
		'partyIndicator',
		'paymentProvider',
		'pipeline',
		'posRole',
		'posTenderType',
		'refundReason',
		'segment',
		'skill',
		'slaPolicy',
	];

	/**
	 * Records that read as example data although their schema is reference
	 * data (pipelinq-audit-admin-forms-pos, Ruben 7 October). They live in the
	 * example descriptor, slugs unchanged, and never in the register.
	 *
	 * @var array<string, string> slug => name
	 */
	private const EXAMPLE_ONLY = [
		'skill-vergunningen'              => 'Vergunningen',
		'skill-wmo-zorg'                  => 'WMO / Zorg',
		'goud-tier-request-sla'           => 'Goud-tier klant-SLA',
		'segment-service-without-product' => 'Advice customers without a product',
	];

	/**
	 * The merged register configuration.
	 *
	 * @var array<string, mixed>
	 */
	private array $config;

	/**
	 * The on-demand example descriptor.
	 *
	 * @var array<string, mixed>
	 */
	private array $example;

	/**
	 * Load the merged register and the example descriptor.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$appPath = dirname(__DIR__, 3);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn($appPath);

		$this->config = (new ConfigFileLoaderService($appManager))->loadConfigurationFile();
		$this->example = (array)json_decode(
			(string)file_get_contents($appPath . '/lib/Settings/pipelinq_example_register.json'),
			true,
			512,
			JSON_THROW_ON_ERROR
		);
	}//end setUp()

	/**
	 * Every record the register seeds belongs to a reference schema.
	 *
	 * @return void
	 */
	public function testTheRegisterSeedsReferenceDataOnly(): void {
		$objects = ($this->config['components']['objects'] ?? []);
		$this->assertNotEmpty($objects, 'the reference data itself must still be seeded');

		foreach ($objects as $object) {
			$this->assertContains(
				$object['@self']['schema'],
				self::REFERENCE_SCHEMAS,
				$object['@self']['slug'] . ' is example data in the register: move it to pipelinq_example_register.json'
			);
		}
	}//end testTheRegisterSeedsReferenceDataOnly()

	/**
	 * The example-looking reference records are example data only.
	 *
	 * @return void
	 */
	public function testExampleLookingReferenceRecordsAreExampleDataOnly(): void {
		$inRegister = [];
		foreach (($this->config['components']['objects'] ?? []) as $object) {
			$inRegister[$object['@self']['slug']] = (string)($object['title'] ?? $object['name'] ?? '');
		}

		$inExample = [];
		foreach ($this->example['components']['objects'] as $object) {
			$inExample[$object['@self']['slug']] = (string)($object['title'] ?? $object['name'] ?? '');
		}

		foreach (self::EXAMPLE_ONLY as $slug => $name) {
			$this->assertArrayNotHasKey($slug, $inRegister, $slug . ' must not ship on every install');
			$this->assertNotContains($name, $inRegister, $name . ' must not ship on every install');
			$this->assertSame($name, $inExample[$slug] ?? null, $slug . ' belongs in pipelinq_example_register.json');
		}
	}//end testExampleLookingReferenceRecordsAreExampleDataOnly()

	/**
	 * The example descriptor is a mock, which OpenRegister never imports by itself.
	 *
	 * @return void
	 */
	public function testTheExampleDescriptorIsOnDemand(): void {
		$this->assertSame('mock', $this->example['x-openregister']['type'] ?? null);
		$this->assertSame('pipelinq', $this->example['x-openregister']['app'] ?? null);
		$this->assertArrayNotHasKey('schemas', $this->example['components']);
		$this->assertNotEmpty($this->example['components']['objects']);
	}//end testTheExampleDescriptorIsOnDemand()

	/**
	 * Every example record has a slug, names a pipelinq schema and fills its required fields.
	 *
	 * OpenRegister skips a seed without a slug and drops one that fails its
	 * schema, both without an error, so a gap here is a record nobody sees.
	 *
	 * @return void
	 */
	public function testEveryExampleRecordCanBeImported(): void {
		$schemas = $this->config['components']['schemas'];
		$registers = array_merge(['pipelinq'], array_keys((array)($this->config['components']['registers'] ?? [])));
		$slugs = [];

		foreach ($this->example['components']['objects'] as $object) {
			$self = $object['@self'];
			$this->assertNotEmpty($self['slug'] ?? null, 'an example record has no slug');
			// A register the app's own descriptor declares (pipelinq, or a
			// fragment's own register such as `sla`), never a foreign one.
			$this->assertContains($self['register'] ?? null, $registers, $self['slug'] . ' targets another register');
			$this->assertArrayHasKey($self['schema'], $schemas, $self['slug'] . ' names an unknown schema');

			foreach (($schemas[$self['schema']]['required'] ?? []) as $field) {
				$this->assertNotNull($object[$field] ?? null, $self['slug'] . ' is missing the required field ' . $field);
			}

			$key = $self['schema'] . '/' . $self['slug'];
			$this->assertArrayNotHasKey($key, $slugs, $key . ' appears twice');
			$slugs[$key] = true;
		}
	}//end testEveryExampleRecordCanBeImported()

	/**
	 * No record is in both places, so removing the examples cannot touch reference data.
	 *
	 * @return void
	 */
	public function testNoRecordIsBothReferenceAndExample(): void {
		$reference = [];
		foreach (($this->config['components']['objects'] ?? []) as $object) {
			$reference[$object['@self']['schema'] . '/' . $object['@self']['slug']] = true;
		}

		foreach ($this->example['components']['objects'] as $object) {
			$key = $object['@self']['schema'] . '/' . $object['@self']['slug'];
			$this->assertArrayNotHasKey($key, $reference, $key . ' is both reference and example data');
		}
	}//end testNoRecordIsBothReferenceAndExample()
}//end class
