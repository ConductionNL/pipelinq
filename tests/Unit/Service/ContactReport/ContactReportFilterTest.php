<?php

/**
 * Unit tests for ContactReportFilter.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\ContactReport
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/kcc-report-top-occupancy-forecast-export/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\ContactReport;

use OCA\Pipelinq\Service\ContactReport\ContactReportFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The report filter's reading of request values and its labels.
 */
#[CoversClass(ContactReportFilter::class)]
final class ContactReportFilterTest extends TestCase {
	/**
	 * Strings are trimmed; anything else does not filter.
	 *
	 * @return void
	 */
	public function testConstructorKeepsOnlyTrimmedStrings(): void {
		$filter = new ContactReportFilter(team: ' burgerzaken ', channel: ['telefoon'], employee: null);

		$this->assertSame('burgerzaken', $filter->team);
		$this->assertSame('', $filter->channel);
		$this->assertSame('', $filter->employee);
	}//end testConstructorKeepsOnlyTrimmedStrings()

	/**
	 * The labels read "all" for a filter that is not set.
	 *
	 * @return void
	 */
	public function testLabelsReadAllForAnUnsetFilter(): void {
		$this->assertSame(
			['Team' => 'all', 'Channel' => 'email', 'Employee' => 'all'],
			(new ContactReportFilter(channel: 'email'))->labels()
		);
	}//end testLabelsReadAllForAnUnsetFilter()
}//end class
