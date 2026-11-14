<?php
/**
 * UplListParser: the published UPL CSV into product rows.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Upl
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Upl;

use InvalidArgumentException;
use OCA\Pipelinq\Service\Upl\UplListParser;
use PHPUnit\Framework\TestCase;

/**
 * Columns are found by header, each URI is kept once, and rows without a usable URI are skipped.
 *
 * @covers \OCA\Pipelinq\Service\Upl\UplListParser
 */
class UplListParserTest extends TestCase {

	/**
	 * The published shape: a BOM, a header with extra columns, quoted names and X flags.
	 *
	 * @return void
	 */
	public function testThePublishedListIsReadByItsHeader(): void {
		$csv = "\xEF\xBB\xBFUniformeProductnaam,URI,Rijk,Gemeente,Burger,Bedrijf\r\n"
			. "paspoort,http://standaarden.overheid.nl/owms/terms/paspoort,,X,X,\r\n"
			. "\"evenementenvergunning, groot\",http://standaarden.overheid.nl/owms/terms/evenementenvergunning,,X,X,X\r\n"
			. "\r\n"
			. "zonder uri,,,X,X,\r\n"
			. ",http://standaarden.overheid.nl/owms/terms/naamloos,,X,,\r\n"
			. "bouwvergunning,urn:not-a-web-uri,,X,X,\r\n"
			. "paspoort dubbel,http://standaarden.overheid.nl/owms/terms/paspoort,,,,X\r\n";

		$rows = (new UplListParser())->parse(csv: $csv);

		$this->assertSame(
			[
				['name' => 'paspoort', 'uri' => 'http://standaarden.overheid.nl/owms/terms/paspoort', 'audience' => ['resident']],
				[
					'name' => 'evenementenvergunning, groot',
					'uri' => 'http://standaarden.overheid.nl/owms/terms/evenementenvergunning',
					'audience' => ['resident', 'business'],
				],
			],
			$rows
		);
	}//end testThePublishedListIsReadByItsHeader()

	/**
	 * A semicolon list with columns in another order and other flag spellings reads the same.
	 *
	 * @return void
	 */
	public function testASemicolonListWithOtherColumnOrderIsRead(): void {
		$csv = "Bedrijf;URI;Naam\nja;https://example.org/upl/a;Afvalcontainer\nnee;https://example.org/upl/b;Bijstand\n";

		$rows = (new UplListParser())->parse(csv: $csv);

		$this->assertSame(
			[
				['name' => 'Afvalcontainer', 'uri' => 'https://example.org/upl/a', 'audience' => ['business']],
				['name' => 'Bijstand', 'uri' => 'https://example.org/upl/b', 'audience' => []],
			],
			$rows
		);
	}//end testASemicolonListWithOtherColumnOrderIsRead()

	/**
	 * A header without a URI column is refused rather than read wrongly.
	 *
	 * @return void
	 */
	public function testAListWithoutAUriColumnIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('no product name or URI column');

		(new UplListParser())->parse(csv: "Naam,Gemeente\npaspoort,X\n");
	}//end testAListWithoutAUriColumnIsRefused()

	/**
	 * An empty download is refused.
	 *
	 * @return void
	 */
	public function testAnEmptyListIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The UPL list is empty.');

		(new UplListParser())->parse(csv: "\n  \n");
	}//end testAnEmptyListIsRefused()
}//end class
