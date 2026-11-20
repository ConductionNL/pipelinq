<?php

/**
 * Unit tests for PdfDocument.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Document
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

namespace OCA\Pipelinq\Tests\Unit\Service\Document;

use OCA\Pipelinq\Service\Document\PdfDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The PDF writer's structure, text and pagination.
 */
#[CoversClass(PdfDocument::class)]
final class PdfDocumentTest extends TestCase {
	/**
	 * Every cross-reference offset points at its object, and the trailer at the table.
	 *
	 * @return void
	 */
	public function testCrossReferencesPointAtTheirObjects(): void {
		$pdf = (new PdfDocument())->build(title: 'Report', sections: ['Summary' => [['Contacts', 3]]]);

		$this->assertStringStartsWith('%PDF-1.4', $pdf);
		$this->assertStringEndsWith("%%EOF\n", $pdf);
		$this->assertSame(1, preg_match('/startxref\n(\d+)\n/', $pdf, $start));
		$xref = (int)$start[1];
		$this->assertSame('xref', substr($pdf, $xref, 4));
		$this->assertSame(6, preg_match_all('/^(\d{10}) 00000 n $/m', $pdf, $offsets));
		foreach ($offsets[1] as $index => $offset) {
			$this->assertSame(($index + 1).' 0 obj', substr($pdf, (int)$offset, strlen(($index + 1).' 0 obj')));
		}

		$this->assertCount(6, $offsets[1]);
	}//end testCrossReferencesPointAtTheirObjects()

	/**
	 * Headings are bold, row columns are spread out, and special characters are escaped.
	 *
	 * @return void
	 */
	public function testTextIsPlacedAndEscaped(): void {
		$pdf = (new PdfDocument())->build(title: 'Report (week)', sections: ['Team' => [['Ann\\e', 12]]]);

		$this->assertStringContainsString('BT /F2 10 Tf 50 792 Td (Report \\(week\\)) Tj ET', $pdf);
		$this->assertStringContainsString('BT /F2 10 Tf 50 764 Td (Team) Tj ET', $pdf);
		$this->assertStringContainsString('BT /F1 10 Tf 50 750 Td (Ann\\\\e) Tj ET', $pdf);
		$this->assertStringContainsString('BT /F1 10 Tf 297 750 Td (12) Tj ET', $pdf);
	}//end testTextIsPlacedAndEscaped()

	/**
	 * Accented text is written in WinAnsi encoding.
	 *
	 * @return void
	 */
	public function testAccentedTextIsWinAnsi(): void {
		$pdf = (new PdfDocument())->build(title: 'Café', sections: []);

		$this->assertStringContainsString("(Caf\xE9) Tj", $pdf);
	}//end testAccentedTextIsWinAnsi()

	/**
	 * Rows past a page flow onto a next page.
	 *
	 * @return void
	 */
	public function testLongReportsFlowOntoMorePages(): void {
		$rows = array_map(static fn (int $i): array => ['Row '.$i], range(1, 100));

		$pdf = (new PdfDocument())->build(title: 'Report', sections: ['Rows' => $rows]);

		$this->assertStringContainsString('/Count 2', $pdf);
		$this->assertSame(2, substr_count($pdf, '/Type /Page /Parent'));
		$this->assertStringContainsString('(Row 100) Tj', $pdf);
	}//end testLongReportsFlowOntoMorePages()
}//end class
