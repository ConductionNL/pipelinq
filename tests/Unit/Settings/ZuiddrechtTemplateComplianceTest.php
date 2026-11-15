<?php
/**
 * Example data for PqNieuwsbriefWizard: every email template in the Zuiddrecht
 * set passes the compliance check the wizard runs when a template is chosen.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use OCA\Pipelinq\Service\ComplianceService;
use OCA\Pipelinq\Service\Marketing\PhysicalAddressRenderer;
use PHPUnit\Framework\TestCase;

/**
 * The set's email templates carry an unsubscribe link and the sender's address.
 */
class ZuiddrechtTemplateComplianceTest extends TestCase {

	/**
	 * The set's email templates by key, from every file of the set.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function emailTemplates(): array {
		$dir   = __DIR__.'/../../../lib/Settings/example-sets/';
		$files = array_merge([$dir.'zuiddrecht.json'], (glob($dir.'zuiddrecht.d/*.json') ?: []));

		$templates = [];
		foreach ($files as $file) {
			$data = json_decode((string)file_get_contents($file), true);
			$this->assertIsArray(actual: $data, message: $file);
			foreach (($data['objects'] ?? []) as $object) {
				if (($object['schema'] ?? '') === 'campaignTemplate' && ($object['data']['channel'] ?? '') === 'email') {
					$templates[$object['key']] = $object['data'];
				}
			}
		}

		return $templates;
	}//end emailTemplates()

	/**
	 * Each email template passes ComplianceService::validateTemplate, so the
	 * wizard can go on to the content step with any of them.
	 *
	 * @return void
	 */
	public function testEveryEmailTemplatePassesTheComplianceCheck(): void {
		$templates = $this->emailTemplates();
		$this->assertNotEmpty(actual: $templates);

		$compliance = (new \ReflectionClass(ComplianceService::class))->newInstanceWithoutConstructor();
		$errors     = [];
		foreach ($templates as $key => $template) {
			$error = $compliance->validateTemplate(templateData: $template, channel: 'email');
			if ($error !== null) {
				$errors[$key] = $error;
			}
		}

		$this->assertSame(expected: [], actual: $errors);
	}//end testEveryEmailTemplatePassesTheComplianceCheck()

	/**
	 * The text mail prints the footer address once, at the token that closes
	 * its body.
	 *
	 * @return void
	 */
	public function testTheTextMailPrintsTheAddressOnce(): void {
		$address  = 'Gemeente Zuiddrecht, Marktplein 1, 2851 AB Zuiddrecht';
		$renderer = new PhysicalAddressRenderer();
		foreach ($this->emailTemplates() as $key => $template) {
			$this->assertSame(expected: $address, actual: ($template['footerOverride'] ?? null), message: $key);
			$text = $renderer->render(body: $template['bodyText'], footerOverride: $template['footerOverride'], format: 'text');
			$this->assertSame(expected: 1, actual: substr_count($text, $address), message: $key);
			$this->assertStringEndsWith(suffix: $address, string: $text, message: $key);
		}
	}//end testTheTextMailPrintsTheAddressOnce()
}//end class
