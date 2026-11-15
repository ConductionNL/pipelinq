<?php

/**
 * Example data for PqPipeline and PqLeads: both draw the set's "Verkoop"
 * pipeline, with the value and chance under each stage. Verkoop is the lead
 * default and maps leads with their value as the stage total, the way the
 * demo seed's sales pipeline does, while "Vragen en meldingen" stays the
 * request default.
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

use PHPUnit\Framework\TestCase;

/**
 * The Verkoop pipeline in the Zuiddrecht set.
 */
class ZuiddrechtVerkoopPipelineTest extends TestCase {

	/**
	 * The set's pipelines by key, from the main file and its parts.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function pipelines(): array {
		$dir   = __DIR__.'/../../../lib/Settings/example-sets/';
		$files = array_merge([$dir.'zuiddrecht.json'], glob($dir.'zuiddrecht.d/*.json'));
		$found = [];
		foreach ($files as $file) {
			$set = json_decode((string)file_get_contents($file), true);
			foreach (($set['objects'] ?? []) as $object) {
				if (($object['schema'] ?? '') === 'pipeline') {
					$found[$object['key']] = $object['data'];
				}
			}
		}

		return $found;
	}//end pipelines()

	/**
	 * Verkoop is the default pipeline, so the board opens on it.
	 *
	 * @return void
	 */
	public function testVerkoopIsTheDefaultPipeline(): void {
		$this->assertTrue($this->pipelines()['pipeline-verkoop']['isDefault']);
	}//end testVerkoopIsTheDefaultPipeline()

	/**
	 * Verkoop boards leads by stage with their value as the stage total.
	 *
	 * @return void
	 */
	public function testVerkoopMapsLeadsWithTheirValueAsTheStageTotal(): void {
		$this->assertSame(
			expected: [['schemaSlug' => 'lead', 'columnProperty' => 'stage', 'totalsProperty' => 'value']],
			actual: $this->pipelines()['pipeline-verkoop']['propertyMappings'] ?? null
		);
	}//end testVerkoopMapsLeadsWithTheirValueAsTheStageTotal()

	/**
	 * Each entity has one default: Verkoop for leads, Vragen en meldingen for requests.
	 *
	 * @return void
	 */
	public function testEachEntityHasOneDefaultPipeline(): void {
		$defaults = [];
		foreach ($this->pipelines() as $key => $pipeline) {
			if (($pipeline['isDefault'] ?? false) !== true) {
				continue;
			}

			foreach (($pipeline['propertyMappings'] ?? []) as $mapping) {
				$defaults[$mapping['schemaSlug']][] = $key;
			}
		}

		$this->assertSame(
			expected: ['lead' => ['pipeline-verkoop'], 'request' => ['pipeline-vragen-en-meldingen']],
			actual: $defaults
		);
	}//end testEachEntityHasOneDefaultPipeline()
}//end class
