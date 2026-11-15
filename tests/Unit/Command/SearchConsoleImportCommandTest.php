<?php

/**
 * Pins the Search Console import command's output and exit code for every outcome.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-console-queries-are-imported-with-a-service-account
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Command;

use OCA\Pipelinq\Command\SearchConsoleImportCommand;
use OCA\Pipelinq\Service\SearchConsole\SearchConsoleImportService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Exit code, every output line and the days asked of the importer.
 */
class SearchConsoleImportCommandTest extends TestCase {

	/**
	 * The importer's state, the --days option, and the expected exit code, lines and import calls.
	 *
	 * @return array<string, array{array<string, mixed>, array<string, string>, int, array<int, string>, array<int, int>}>
	 */
	public static function cases(): array {
		$two = ['sc-domain:a.nl', 'sc-domain:b.nl'];
		return [
			'no key'          => [['key' => false, 'properties' => $two], [], 1, ['No service account key is configured (search.gsc.service_account_key).'], []],
			'no properties'   => [['key' => true, 'properties' => []], [], 1, ['No properties are configured (search.gsc.properties).'], []],
			'default days'    => [
				['key' => true, 'properties' => $two, 'result' => ['rows' => 40, 'properties' => 2, 'errors' => []]],
				[],
				0,
				['Importing the last 3 day(s) for 2 properties as bot@sa.example', 'Imported 40 row(s) across 2 properties.'],
				[3],
			],
			'one property'    => [
				['key' => true, 'properties' => ['sc-domain:a.nl'], 'result' => ['rows' => 1, 'properties' => 1, 'errors' => []]],
				['--days' => '10'],
				0,
				['Importing the last 10 day(s) for 1 property as bot@sa.example', 'Imported 1 row(s) across 1 property.'],
				[10],
			],
			'negative days'   => [
				['key' => true, 'properties' => $two, 'result' => ['rows' => 0, 'properties' => 1, 'errors' => ['sc-domain:b.nl' => 'forbidden']]],
				['--days' => '-4'],
				0,
				['Importing the last 0 day(s) for 2 properties as bot@sa.example', 'sc-domain:b.nl: forbidden', 'Imported 0 row(s) across 1 property.'],
				[0],
			],
			'none imported'   => [
				['key' => true, 'properties' => $two, 'result' => ['rows' => 0, 'properties' => 0, 'errors' => ['sc-domain:a.nl' => 'x', 'sc-domain:b.nl' => 'y']]],
				['--days' => 'abc'],
				1,
				['Importing the last 0 day(s) for 2 properties as bot@sa.example', 'sc-domain:a.nl: x', 'sc-domain:b.nl: y', 'Imported 0 row(s) across 0 properties.'],
				[0],
			],
			'import throws'   => [
				['key' => true, 'properties' => $two, 'throws' => true],
				[],
				1,
				['Importing the last 3 day(s) for 2 properties as bot@sa.example', 'Search Console import failed: token refused'],
				[3],
			],
		];
	}//end cases()

	/**
	 * Each outcome prints and exits exactly as pinned.
	 *
	 * @param array<string, mixed>  $state   The importer's state.
	 * @param array<string, string> $options The command options.
	 * @param int                   $code    The exit code.
	 * @param array<int, string>    $lines   The output lines, tags stripped.
	 * @param array<int, int>       $imports The days passed to importRecent, in order.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testOutcome(array $state, array $options, int $code, array $lines, array $imports): void {
		$asked    = [];
		$importer = $this->createMock(SearchConsoleImportService::class);
		$importer->method('hasKey')->willReturn($state['key']);
		$importer->method('properties')->willReturn($state['properties']);
		$importer->method('serviceAccountEmail')->willReturn('bot@sa.example');
		$importer->method('importRecent')->willReturnCallback(
			function (int $days) use (&$asked, $state): array {
				$asked[] = $days;
				if (($state['throws'] ?? false) === true) {
					throw new RuntimeException('token refused');
				}

				return $state['result'];
			}
		);

		$tester = new CommandTester(new SearchConsoleImportCommand($importer));
		$this->assertSame($code, $tester->execute($options));
		$this->assertSame($lines, array_values(array_filter(explode("\n", $tester->getDisplay()), static fn (string $l): bool => $l !== '')));
		$this->assertSame($imports, $asked);
	}//end testOutcome()
}//end class
