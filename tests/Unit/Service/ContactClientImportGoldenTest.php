<?php

/**
 * Pins the whole client row ContactDataBuilder builds from a Nextcloud contact.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/contacts-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ContactDataBuilder;
use PHPUnit\Framework\TestCase;

/**
 * A person, an organisation without a name and a bare card, against the fixture.
 */
class ContactClientImportGoldenTest extends TestCase {

	/**
	 * The whole client row is exact, key order included.
	 *
	 * @return void
	 */
	public function testClientImportRowsAreExact(): void {
		$builder = new ContactDataBuilder();
		$rows    = [];
		foreach (
			[
				['FN' => 'Jan de Vries', 'ORG' => 'Bakkerij', 'EMAIL' => ['jan@example.nl', ['value' => 'werk@example.nl', 'type' => 'work']], 'TEL' => '0612345678', 'URL' => 'https://jan.example', 'X-SOCIALPROFILE' => [['value' => 'https://x.com/jan', 'type' => 'x']]],
				['FN' => '', 'ORG' => 'Gemeente Voorbeeld', 'TEL' => ['010-1234567']],
				[],
			] as $i => $card
		) {
			$rows[] = $builder->buildClientImportData($card, 'uid-'.$i);
		}

		$this->assertSame(json_decode((string)file_get_contents(__DIR__.'/Fixtures/contact-client-import.json'), true), json_decode((string)json_encode($rows), true));
	}//end testClientImportRowsAreExact()
}//end class
