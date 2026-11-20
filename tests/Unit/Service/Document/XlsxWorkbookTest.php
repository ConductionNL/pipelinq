<?php

/**
 * Unit tests for XlsxWorkbook.
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

use OCA\Pipelinq\Service\Document\XlsxWorkbook;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * The workbook writer's package, cells and sheet names.
 */
#[CoversClass(XlsxWorkbook::class)]
final class XlsxWorkbookTest extends TestCase {
	/**
	 * The parts of a written workbook.
	 *
	 * @param array<string, array<int, array<int, mixed>>> $sheets The sheets.
	 *
	 * @return array<string, string> Part name => contents.
	 */
	private static function parts(array $sheets): array {
		$path = (string)tempnam(sys_get_temp_dir(), 'plq-test-');
		file_put_contents($path, (new XlsxWorkbook())->build(sheets: $sheets));
		$zip = new ZipArchive();
		self::assertTrue($zip->open($path));
		$parts = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = (string)$zip->getNameIndex($i);
			$parts[$name] = (string)$zip->getFromName($name);
		}

		$zip->close();
		unlink($path);
		return $parts;
	}//end parts()

	/**
	 * Every sheet gets its worksheet part, content type and relation.
	 *
	 * @return void
	 */
	public function testEverySheetIsAPartOfThePackage(): void {
		$parts = self::parts(sheets: ['One' => [['a']], 'Two' => [['b']]]);

		$this->assertSame(
			['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/worksheets/sheet1.xml', 'xl/worksheets/sheet2.xml'],
			array_keys($parts)
		);
		$this->assertStringContainsString('<sheet name="Two" sheetId="2" r:id="rId2"/>', $parts['xl/workbook.xml']);
		$this->assertStringContainsString('Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"', $parts['xl/_rels/workbook.xml.rels']);
		$this->assertStringContainsString('<Override PartName="/xl/worksheets/sheet2.xml"', $parts['[Content_Types].xml']);
		foreach ($parts as $name => $xml) {
			$this->assertNotFalse(simplexml_load_string($xml), $name);
		}
	}//end testEverySheetIsAPartOfThePackage()

	/**
	 * Numbers are numbers; text, including formula-like text, is inline text, escaped.
	 *
	 * @return void
	 */
	public function testCellsKeepTheirTypeAndAreNeverFormulas(): void {
		$sheet = self::parts(sheets: ['S' => [[7, 2.5, '=SUM(A1)', 'a < b & "c"', null, true]]])['xl/worksheets/sheet1.xml'];

		$this->assertStringContainsString('<c r="A1"><v>7</v></c><c r="B1"><v>2.5</v></c>', $sheet);
		$this->assertStringContainsString('<c r="C1" t="inlineStr"><is><t xml:space="preserve">=SUM(A1)</t></is></c>', $sheet);
		$this->assertStringContainsString('<t xml:space="preserve">a &lt; b &amp; &quot;c&quot;</t>', $sheet);
		$this->assertStringContainsString('<c r="E1" t="inlineStr"><is><t xml:space="preserve"></t></is></c>', $sheet);
		$this->assertStringContainsString('<c r="F1" t="inlineStr"><is><t xml:space="preserve">1</t></is></c>', $sheet);
		$this->assertStringNotContainsString('<f>', $sheet);
	}//end testCellsKeepTheirTypeAndAreNeverFormulas()

	/**
	 * Columns past Z continue as AA, AB.
	 *
	 * @return void
	 */
	public function testColumnsPastZContinueAsDoubleLetters(): void {
		$sheet = self::parts(sheets: ['S' => [range(1, 28)]])['xl/worksheets/sheet1.xml'];

		$this->assertStringContainsString('<c r="Z1"><v>26</v></c><c r="AA1"><v>27</v></c><c r="AB1"><v>28</v></c>', $sheet);
	}//end testColumnsPastZContinueAsDoubleLetters()

	/**
	 * A sheet name loses the characters Excel refuses and is cut at 31 characters.
	 *
	 * @return void
	 */
	public function testSheetNamesAreMadeValid(): void {
		$workbook = self::parts(sheets: ['a/b:c' => [], str_repeat('x', 40) => [], '[]' => []])['xl/workbook.xml'];

		$this->assertStringContainsString('name="a b c"', $workbook);
		$this->assertStringContainsString('name="'.str_repeat('x', 31).'"', $workbook);
		$this->assertStringContainsString('name="Sheet"', $workbook);
	}//end testSheetNamesAreMadeValid()
}//end class
