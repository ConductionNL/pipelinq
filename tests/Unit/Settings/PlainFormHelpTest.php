<?php

/**
 * Unit tests: create and edit forms show plain help, not schema jargon.
 *
 * A property description is the helper line under a form field and, empty
 * field, its placeholder. Descriptions such as "Denormalised read-only mirror
 * of the NC contact name (vCard FN)." or "UUID reference to the parent client
 * object" told a user nothing (pipelinq-audit-admin-forms-pos). The technical
 * text stays for developers in the property's own fragment and in `x-notes`.
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
class PlainFormHelpTest extends TestCase {
	/**
	 * Words that belong to the data model, not to a form.
	 *
	 * @var string
	 */
	private const JARGON = '/schema:|vCard|UUID|[Dd]enormalis|\bNC\b|BCP 47|IANA|[Nn]ull for|\bVNG\b|\bMDM\b/';

	/**
	 * The schemas behind Create contact, Create task, Create product and the client forms.
	 *
	 * @var array<int, string>
	 */
	private const FORM_SCHEMAS = ['client', 'contact', 'crmTask', 'product', 'leadProduct'];

	/**
	 * The merged register.
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
	 * No form field shows schema jargon as its help.
	 *
	 * @return void
	 */
	public function testFormFieldsCarryPlainHelp(): void {
		$found = [];
		foreach (self::FORM_SCHEMAS as $schema) {
			foreach ($this->schemas[$schema]['properties'] as $key => $property) {
				if (($property['visible'] ?? true) === false) {
					continue;
				}

				if (preg_match(self::JARGON, (string)($property['description'] ?? '')) === 1) {
					$found[] = $schema . '.' . $key . ': ' . $property['description'];
				}
			}
		}

		$this->assertSame([], $found);
	}//end testFormFieldsCarryPlainHelp()

	/**
	 * The technical description is kept for developers.
	 *
	 * @return void
	 */
	public function testTheTechnicalDescriptionIsKept(): void {
		$this->assertStringContainsString('vCard FN', $this->schemas['client']['properties']['name']['x-notes']);
		$this->assertStringContainsString('UUID reference', $this->schemas['contact']['properties']['client']['x-notes']);
	}//end testTheTechnicalDescriptionIsKept()

	/**
	 * A new client or contact gets no `language` beside its correspondence language.
	 *
	 * @return void
	 */
	public function testPartyLanguageHasNoDefault(): void {
		foreach (['client', 'contact'] as $schema) {
			$this->assertArrayNotHasKey(
				'default',
				$this->schemas[$schema]['properties']['language'],
				$schema . '.language must not default: correspondenceLanguage holds the choice'
			);
		}
	}//end testPartyLanguageHasNoDefault()
}//end class
