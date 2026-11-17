<?php

/**
 * Pipelinq XlsxWorkbook.
 *
 * Writes rows of plain values as an Office Open XML workbook (.xlsx).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Document
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/kcc-report-top-occupancy-forecast-export/specs/contactmomenten-rapportage/spec.md#requirement-the-report-exports-to-excel-and-pdf
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Document;

use RuntimeException;
use ZipArchive;

/**
 * A minimal .xlsx writer: one worksheet per named sheet, numbers as numbers
 * and everything else as inline text, so no cell is ever read as a formula.
 *
 * @spec openspec/changes/kcc-report-top-occupancy-forecast-export/specs/contactmomenten-rapportage/spec.md#requirement-the-report-exports-to-excel-and-pdf
 */
class XlsxWorkbook {
	/**
	 * The XML declaration every part starts with.
	 */
	private const XML_HEAD = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n";

	/**
	 * The workbook for the given sheets.
	 *
	 * @param array<string, array<int, array<int, mixed>>> $sheets Sheet name => rows of cell values, in order.
	 *
	 * @return string The .xlsx file's bytes.
	 *
	 * @throws RuntimeException When the archive cannot be written.
	 *
	 * @spec openspec/changes/kcc-report-top-occupancy-forecast-export/specs/contactmomenten-rapportage/spec.md#requirement-the-report-exports-to-excel-and-pdf
	 */
	public function build(array $sheets): string {
		$path = tempnam(sys_get_temp_dir(), 'plq-xlsx-');
		if ($path === false) {
			throw new RuntimeException('Cannot create a temporary file for the workbook');
		}

		try {
			$this->writeArchive(path: $path, parts: $this->parts(names: array_keys($sheets), sheets: array_values($sheets)));
			return (string)file_get_contents($path);
		} finally {
			unlink($path);
		}
	}//end build()

	/**
	 * Write the parts into a zip archive at the path.
	 *
	 * @param string                $path  The archive's path.
	 * @param array<string, string> $parts Part name => XML.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the archive cannot be written.
	 */
	private function writeArchive(string $path, array $parts): void {
		$zip = new ZipArchive();
		if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
			throw new RuntimeException('Cannot open the workbook archive');
		}

		foreach ($parts as $name => $xml) {
			$zip->addFromString($name, $xml);
		}

		$zip->close();
	}//end writeArchive()

	/**
	 * Every part of the package.
	 *
	 * @param array<int, string>                         $names  The sheet names.
	 * @param array<int, array<int, array<int, mixed>>> $sheets The sheets' rows.
	 *
	 * @return array<string, string> Part name => XML.
	 */
	private function parts(array $names, array $sheets): array {
		$parts = [
			'[Content_Types].xml' => $this->contentTypes(count: count($names)),
			'_rels/.rels' => self::XML_HEAD.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
				.'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
				.'</Relationships>',
			'xl/workbook.xml' => $this->workbook(names: $names),
			'xl/_rels/workbook.xml.rels' => $this->workbookRelations(count: count($names)),
		];
		foreach ($sheets as $index => $rows) {
			$parts['xl/worksheets/sheet'.($index + 1).'.xml'] = $this->worksheet(rows: $rows);
		}

		return $parts;
	}//end parts()

	/**
	 * The content types part.
	 *
	 * @param int $count The number of sheets.
	 *
	 * @return string The XML.
	 */
	private function contentTypes(int $count): string {
		$xml = self::XML_HEAD.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			.'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			.'<Default Extension="xml" ContentType="application/xml"/>'
			.'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
		for ($i = 1; $i <= $count; $i++) {
			$xml .= '<Override PartName="/xl/worksheets/sheet'.$i.'.xml"'
				.' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
		}

		return $xml.'</Types>';
	}//end contentTypes()

	/**
	 * The workbook part, naming the sheets.
	 *
	 * @param array<int, string> $names The sheet names.
	 *
	 * @return string The XML.
	 */
	private function workbook(array $names): string {
		$xml = self::XML_HEAD.'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
			.' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
		foreach ($names as $index => $name) {
			$number = $index + 1;
			$xml .= '<sheet name="'.self::escape(text: self::sheetName(name: (string)$name)).'" sheetId="'.$number.'" r:id="rId'.$number.'"/>';
		}

		return $xml.'</sheets></workbook>';
	}//end workbook()

	/**
	 * The workbook's relations to its sheets.
	 *
	 * @param int $count The number of sheets.
	 *
	 * @return string The XML.
	 */
	private function workbookRelations(int $count): string {
		$xml = self::XML_HEAD.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
		for ($i = 1; $i <= $count; $i++) {
			$xml .= '<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
				.' Target="worksheets/sheet'.$i.'.xml"/>';
		}

		return $xml.'</Relationships>';
	}//end workbookRelations()

	/**
	 * One worksheet.
	 *
	 * @param array<int, array<int, mixed>> $rows The rows of cell values.
	 *
	 * @return string The XML.
	 */
	private function worksheet(array $rows): string {
		$xml = self::XML_HEAD.'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
		foreach (array_values($rows) as $rowIndex => $row) {
			$xml .= '<row r="'.($rowIndex + 1).'">';
			foreach (array_values($row) as $columnIndex => $value) {
				$xml .= $this->cell(reference: self::column(index: $columnIndex).($rowIndex + 1), value: $value);
			}

			$xml .= '</row>';
		}

		return $xml.'</sheetData></worksheet>';
	}//end worksheet()

	/**
	 * One cell: a number as a number, anything else as inline text.
	 *
	 * @param string $reference The cell reference, e.g. B3.
	 * @param mixed  $value     The value.
	 *
	 * @return string The XML.
	 */
	private function cell(string $reference, mixed $value): string {
		if ((is_int($value) === true || is_float($value) === true) && is_finite((float)$value) === true) {
			return '<c r="'.$reference.'"><v>'.$value.'</v></c>';
		}

		$text = '';
		if (is_scalar($value) === true) {
			$text = (string)$value;
		}

		return '<c r="'.$reference.'" t="inlineStr"><is><t xml:space="preserve">'.self::escape(text: $text).'</t></is></c>';
	}//end cell()

	/**
	 * The column letters for a zero-based column index (0 = A, 26 = AA).
	 *
	 * @param int $index The column index.
	 *
	 * @return string The letters.
	 */
	private static function column(int $index): string {
		$letters = '';
		for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
			$letters = chr(65 + (($n - 1) % 26)).$letters;
		}

		return $letters;
	}//end column()

	/**
	 * A valid sheet name: at most 31 characters, none of []:*?/\.
	 *
	 * @param string $name The wanted name.
	 *
	 * @return string The name Excel accepts.
	 */
	private static function sheetName(string $name): string {
		$clean = trim(str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', $name));
		if ($clean === '') {
			$clean = 'Sheet';
		}

		return mb_substr($clean, 0, 31);
	}//end sheetName()

	/**
	 * Text escaped for XML, without the control characters XML forbids.
	 *
	 * @param string $text The text.
	 *
	 * @return string The escaped text.
	 */
	private static function escape(string $text): string {
		$clean = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
		return htmlspecialchars($clean, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
	}//end escape()
}//end class
