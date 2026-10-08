<?php

/**
 * Verifies the website-enquiry register fragment and its import wiring.
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
 * @spec openspec/changes/website-enquiry-intake/specs/website-enquiry-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\SettingsLoadService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Ensures the enquiry schema says what the website needs it to say.
 */
class WebsiteEnquiryFragmentTest extends TestCase {

	private const FRAGMENT = __DIR__ . '/../../../lib/Settings/register.d/26-website-enquiry.json';

	/**
	 * Load the fragment's enquiry schema.
	 *
	 * @return array<string, mixed> The schema.
	 */
	private function schema(): array {
		$this->assertFileExists(self::FRAGMENT, 'Website enquiry register fragment is missing');
		$data = json_decode((string)file_get_contents(self::FRAGMENT), true);
		$this->assertIsArray($data, 'Fragment JSON is invalid');

		$schema = ($data['components']['schemas']['enquiry'] ?? null);
		$this->assertIsArray($schema, 'Fragment must declare the enquiry schema');

		return $schema;
	}//end schema()

	/**
	 * The four fields the website posts must exist, because their absence from
	 * `lead` is precisely why website submissions stopped reaching the CRM.
	 *
	 * @return void
	 */
	public function testDeclaresTheFieldsTheWebsitePosts(): void {
		$properties = $this->schema()['properties'];
		foreach (['contactName', 'contactEmail', 'contactPhone', 'organisation', 'message'] as $field) {
			$this->assertArrayHasKey($field, $properties, "Intake field missing: {$field}");
		}
	}//end testDeclaresTheFieldsTheWebsitePosts()

	/**
	 * An enquiry arrives before a client exists, so requiring one would make
	 * every anonymous submission a 400. That is the `lead` defect this schema
	 * exists to avoid repeating.
	 *
	 * @return void
	 */
	public function testRequiresOnlyTitle(): void {
		$required = $this->schema()['required'];
		$this->assertSame(['title'], $required, 'Only title may be required at intake');
		$this->assertNotContains('client', $required);
		$this->assertNotContains('pipeline', $required);
	}//end testRequiresOnlyTitle()

	/**
	 * Anonymous create, authenticated everything else.
	 *
	 * @return void
	 */
	public function testAllowsPublicCreateAndNothingElse(): void {
		$authorization = $this->schema()['authorization'];
		$this->assertContains('public', $authorization['create']);
		foreach (['read', 'update', 'delete'] as $verb) {
			$this->assertNotContains(
				'public',
				$authorization[$verb],
				"A website visitor must not be able to {$verb} enquiries"
			);
		}
	}//end testAllowsPublicCreateAndNothingElse()

	/**
	 * Status is constrained, and starts where the sales inbox looks.
	 *
	 * @return void
	 */
	public function testStatusIsConstrainedAndDefaultsToNew(): void {
		$status = $this->schema()['properties']['status'];
		$this->assertSame('new', $status['default']);
		$this->assertSame(['new', 'converted', 'spam', 'closed'], $status['enum']);
	}//end testStatusIsConstrainedAndDefaultsToNew()

	/**
	 * The fragment must add itself to the register's schema list, or the
	 * schema is defined and never imported.
	 *
	 * @return void
	 */
	public function testFragmentRegistersTheSchema(): void {
		$data = json_decode((string)file_get_contents(self::FRAGMENT), true);
		$this->assertContains('enquiry', $data['components']['registers']['pipelinq']['schemas']);
	}//end testFragmentRegistersTheSchema()

	/**
	 * Without the slug, `applySchemaConfig()` never writes `enquiry_schema`,
	 * EnquiryIntakeService cannot resolve the schema, and the endpoint 503s
	 * with nothing in the import to say why. The same omission is recorded
	 * against posTenderType in SCHEMA_SLUGS' own comments.
	 *
	 * @return void
	 */
	public function testSchemaSlugIsWiredIntoTheImport(): void {
		$reflection = new ReflectionClass(SettingsLoadService::class);
		$slugs = $reflection->getConstant('SCHEMA_SLUGS');

		$this->assertIsArray($slugs);
		$this->assertContains(
			'enquiry',
			$slugs,
			'enquiry must be in SCHEMA_SLUGS or its schema id never reaches app config'
		);
	}//end testSchemaSlugIsWiredIntoTheImport()
}//end class
