<?php

/**
 * Pipelinq PdfDocument.
 *
 * Writes a titled report of headed sections as a PDF document.
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

/**
 * A minimal PDF writer for A4 reports: a bold title, bold section headings,
 * and rows laid out in columns, flowing onto as many pages as they need.
 * It uses the standard Helvetica fonts, so the document embeds no font.
 *
 * @spec openspec/changes/kcc-report-top-occupancy-forecast-export/specs/contactmomenten-rapportage/spec.md#requirement-the-report-exports-to-excel-and-pdf
 */
class PdfDocument {
	/**
	 * Page width in points (A4).
	 */
	private const PAGE_WIDTH = 595;

	/**
	 * Page height in points (A4).
	 */
	private const PAGE_HEIGHT = 842;

	/**
	 * Left and top margin in points.
	 */
	private const MARGIN = 50;

	/**
	 * Line height in points.
	 */
	private const LEADING = 14;

	/**
	 * Body font size in points.
	 */
	private const FONT_SIZE = 10;

	/**
	 * The document for a title and its sections.
	 *
	 * @param string                                             $title    The report's title.
	 * @param array<string, array<int, array<int, string|int|float>>> $sections Heading => rows of column values.
	 *
	 * @return string The PDF file's bytes.
	 *
	 * @spec openspec/changes/kcc-report-top-occupancy-forecast-export/specs/contactmomenten-rapportage/spec.md#requirement-the-report-exports-to-excel-and-pdf
	 */
	public function build(string $title, array $sections): string {
		return $this->assemble(pages: $this->paginate(lines: $this->lines(title: $title, sections: $sections)));
	}//end build()

	/**
	 * The report as lines: [bold, columns].
	 *
	 * @param string                                             $title    The title.
	 * @param array<string, array<int, array<int, string|int|float>>> $sections The sections.
	 *
	 * @return array<int, array{0: bool, 1: array<int, string>}> The lines.
	 */
	private function lines(string $title, array $sections): array {
		$lines = [[true, [$title]], [false, ['']]];
		foreach ($sections as $heading => $rows) {
			$lines[] = [true, [(string)$heading]];
			foreach ($rows as $row) {
				$lines[] = [false, array_map(static fn ($value): string => (string)$value, array_values($row))];
			}

			$lines[] = [false, ['']];
		}

		return $lines;
	}//end lines()

	/**
	 * Split the lines into pages of content streams.
	 *
	 * @param array<int, array{0: bool, 1: array<int, string>}> $lines The lines.
	 *
	 * @return array<int, string> One content stream per page.
	 */
	private function paginate(array $lines): array {
		$perPage = intdiv(self::PAGE_HEIGHT - (2 * self::MARGIN), self::LEADING);
		$pages = [];
		foreach (array_chunk($lines, $perPage) as $chunk) {
			$stream = '';
			foreach ($chunk as $index => [$bold, $columns]) {
				$baseline = self::PAGE_HEIGHT - self::MARGIN - ($index * self::LEADING);
				$stream .= $this->lineStream(bold: $bold, columns: $columns, baseline: $baseline);
			}

			$pages[] = $stream;
		}

		return $pages;
	}//end paginate()

	/**
	 * The content stream of one line, its columns spread over the page width.
	 *
	 * @param bool               $bold    Whether the line is a heading.
	 * @param array<int, string> $columns The column texts.
	 * @param int                $baseline The baseline.
	 *
	 * @return string The operators.
	 */
	private function lineStream(bool $bold, array $columns, int $baseline): string {
		$font = '/F1';
		if ($bold === true) {
			$font = '/F2';
		}

		$width = intdiv(self::PAGE_WIDTH - (2 * self::MARGIN), max(1, count($columns)));
		$stream = '';
		foreach ($columns as $index => $text) {
			if ($text === '') {
				continue;
			}

			$left = self::MARGIN + ($index * $width);
			$stream .= 'BT '.$font.' '.self::FONT_SIZE.' Tf '.$left.' '.$baseline.' Td ('.self::escape(text: $text).") Tj ET\n";
		}

		return $stream;
	}//end lineStream()

	/**
	 * Number the objects and write the file with its cross-reference table.
	 *
	 * @param array<int, string> $pages One content stream per page.
	 *
	 * @return string The PDF bytes.
	 */
	private function assemble(array $pages): string {
		$pageCount = count($pages);
		$kids = [];
		for ($i = 0; $i < $pageCount; $i++) {
			$kids[] = (5 + (2 * $i)).' 0 R';
		}

		$objects = [
			1 => '<< /Type /Catalog /Pages 2 0 R >>',
			2 => '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.$pageCount.' >>',
			3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
			4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
		];
		foreach ($pages as $i => $stream) {
			$pageId = 5 + (2 * $i);
			$objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.self::PAGE_WIDTH.' '.self::PAGE_HEIGHT.'] '
				.'/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents '.($pageId + 1).' 0 R >>';
			$objects[$pageId + 1] = '<< /Length '.strlen($stream)." >>\nstream\n".$stream."endstream";
		}

		return $this->serialise(objects: $objects);
	}//end assemble()

	/**
	 * Write numbered objects as a PDF file.
	 *
	 * @param array<int, string> $objects Object number => body, numbered from 1.
	 *
	 * @return string The PDF bytes.
	 */
	private function serialise(array $objects): string {
		ksort($objects);
		$pdf = "%PDF-1.4\n";
		$offsets = [];
		foreach ($objects as $number => $body) {
			$offsets[$number] = strlen($pdf);
			$pdf .= $number." 0 obj\n".$body."\nendobj\n";
		}

		$xref = strlen($pdf);
		$pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
		foreach ($offsets as $offset) {
			$pdf .= sprintf("%010d 00000 n \n", $offset);
		}

		return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
	}//end serialise()

	/**
	 * Text as a PDF string literal body in WinAnsi encoding.
	 *
	 * @param string $text The UTF-8 text.
	 *
	 * @return string The escaped text.
	 */
	private static function escape(string $text): string {
		$encoded = mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
		$clean = (string)preg_replace('/[\x00-\x1F]/', ' ', $encoded);
		return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $clean);
	}//end escape()
}//end class
