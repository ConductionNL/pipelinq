<?php

/**
 * Example data for PqOfferte and PqBiExport: offerte OFF-2026-0042's contact,
 * expiry and history, and the export jobs' last and next runs.
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
 *
 * @spec exclude example-set data integrity; no requirement covers the demo fragment
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The quote and the export jobs carry what the boards draw.
 */
class ZuiddrechtQuoteAndExportRunsTest extends TestCase {

	/**
	 * The fragment with its parts' lists appended, as the loader reads it.
	 *
	 * @return array<string, mixed> The fragment.
	 */
	private function fragment(): array {
		$folder   = __DIR__.'/../../../lib/Settings/example-sets';
		$fragment = json_decode((string)file_get_contents($folder.'/zuiddrecht.json'), true);
		$parts    = glob($folder.'/zuiddrecht.d/*.json');
		if ($parts === false) {
			$parts = [];
		}

		sort($parts);
		foreach ($parts as $file) {
			foreach (json_decode((string)file_get_contents($file), true) as $key => $value) {
				if (is_array($value) === true && array_is_list($value) === true) {
					$fragment[$key] = array_merge(($fragment[$key] ?? []), $value);
				}
			}
		}

		return $fragment;
	}//end fragment()

	/**
	 * The quote names the bakery's owner, expires in 30 days and has three history entries whose subtotals end at the quote's.
	 *
	 * @return void
	 */
	public function testTheQuoteHasItsContactExpiryAndHistory(): void {
		$fragment = $this->fragment();
		$objects  = array_column($fragment['objects'], null, 'key');
		$changes  = array_column($fragment['changes'], null, 'key');

		$change = $changes['offerte-0042-contact-en-vervaldatum'];
		$this->assertSame(expected: ['number' => 'OFF-2026-0042'], actual: $change['where']);
		$this->assertSame(expected: '{{object:contact-kees-bakker.id}}', actual: $change['set']['contact']);
		$this->assertSame(expected: '{{date:30}}', actual: $change['set']['expiresAt']);
		$this->assertSame(expected: 'Eigenaar', actual: $objects['contact-kees-bakker']['data']['role']);

		$config = array_column($fragment['appConfig'], 'value', 'key');
		$this->assertSame(
			expected: ['salesQuote' => ['template' => 'offerte', 'ownerField' => 'assignee']],
			actual: json_decode($config['record_document_templates'], true)
		);

		$history = [];
		foreach ($fragment['auditEntries'] as $entry) {
			if ($entry['data']['object'] === '{{object:offerte-0042.id}}') {
				$history[] = $entry['data'];
			}
		}

		$this->assertSame(expected: ['create', 'update', 'update'], actual: array_column($history, 'action'));
		$this->assertSame(expected: $objects['offerte-0042']['data']['subtotal'], actual: $history[2]['changed']['subtotal']['new']);
		$this->assertSame(expected: $history[1]['changed']['subtotal']['new'], actual: $history[2]['changed']['subtotal']['old']);
	}//end testTheQuoteHasItsContactExpiryAndHistory()

	/**
	 * Each export job's last run agrees with its exportRun record, and the disabled leads job has no next run.
	 *
	 * @return void
	 */
	public function testTheExportJobsCarryTheirLastRun(): void {
		$fragment = $this->fragment();
		$objects  = array_column($fragment['objects'], null, 'key');
		$jobs     = [];
		foreach ($fragment['changes'] as $change) {
			if ($change['schema'] === 'exportJob') {
				$jobs[$change['where']['name']] = $change['set'];
			}
		}

		$runs = [
			'export-job-kcc-tickets'          => 'export-run-kcc-tickets',
			'export-job-contacts-per-channel' => 'export-run-contacts-per-channel',
			'export-job-appointments'         => 'export-run-appointments',
			'export-job-leads'                => 'export-run-leads',
		];
		foreach ($runs as $jobKey => $runKey) {
			$set = $jobs[$objects[$jobKey]['data']['name']];
			$run = $objects[$runKey]['data'];
			$this->assertSame(expected: $run['status'], actual: $set['lastRunStatus'], message: $jobKey);
			$this->assertSame(expected: $run['endedAt'], actual: $set['lastRunAt'], message: $jobKey);
		}

		$this->assertNull(actual: $jobs['Leads en waarde in de pijplijn']['nextRunAt']);
		$this->assertSame(expected: 3, actual: $jobs['Afspraken en niet verschenen']['lastRunFilesExpected']);
		$this->assertSame(expected: 3, actual: $objects['export-run-appointments']['data']['fileCountExpected']);
	}//end testTheExportJobsCarryTheirLastRun()
}//end class
