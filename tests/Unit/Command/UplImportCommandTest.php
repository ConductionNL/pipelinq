<?php
/**
 * UplImportCommand: importing the UPL list from occ.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Command
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

namespace OCA\Pipelinq\Tests\Unit\Command;

use OCA\Pipelinq\Command\UplImportCommand;
use OCA\Pipelinq\Service\Upl\UplListImporter;
use OCA\Pipelinq\Service\Upl\UplListParser;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Imports from a file or a download, prints the counts, and fails on any failed write.
 *
 * @covers \OCA\Pipelinq\Command\UplImportCommand
 * @uses   \OCA\Pipelinq\Service\Upl\UplListImporter
 * @uses   \OCA\Pipelinq\Service\Upl\UplListParser
 */
class UplImportCommandTest extends TestCase {

	private const CSV = "UniformeProductnaam,URI\npaspoort,http://upl.example.org/paspoort\nrijbewijs,http://upl.example.org/rijbewijs\n";

	/**
	 * The URLs the HTTP client was asked for.
	 *
	 * @var array<int, string>
	 */
	private array $downloads = [];

	/**
	 * A command over a real importer, an HTTP client serving the CSV and a fake object service.
	 *
	 * @param string|null $failUri A URI whose save throws.
	 *
	 * @return CommandTester
	 */
	private function tester(?string $failUri = null): CommandTester {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn(self::CSV);
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $url) use ($response): IResponse {
				$this->downloads[] = $url;
				return $response;
			}
		);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		$objectService = new class ($failUri) {
			public function __construct(private ?string $failUri) {
			}

			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return [];
			}

			public function saveObject(array $object, mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				if ($object['uri'] === $this->failUri) {
					throw new RuntimeException('refused');
				}

				return $object;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);

		$importer = new UplListImporter(new UplListParser(), $clients, $appConfig, $container, $this->createMock(LoggerInterface::class));

		return new CommandTester(new UplImportCommand($importer));
	}//end tester()

	/**
	 * Without options the national list is downloaded and imported.
	 *
	 * @return void
	 */
	public function testTheNationalListIsDownloadedAndImported(): void {
		$tester = $this->tester();

		$this->assertSame(Command::SUCCESS, $tester->execute([]));
		$this->assertSame([UplListImporter::DEFAULT_URL], $this->downloads);
		$this->assertSame(
			'Downloading the UPL list from ' . UplListImporter::DEFAULT_URL . PHP_EOL
			. 'Read 2 UPL product(s): 2 created, 0 updated, 0 unchanged, 0 failed.' . PHP_EOL,
			$tester->getDisplay()
		);
	}//end testTheNationalListIsDownloadedAndImported()

	/**
	 * --url downloads from elsewhere; a failed write makes the run fail.
	 *
	 * @return void
	 */
	public function testAnotherUrlAndAFailedWrite(): void {
		$tester = $this->tester(failUri: 'http://upl.example.org/rijbewijs');

		$this->assertSame(Command::FAILURE, $tester->execute(['--url' => 'https://mirror.example.org/upl.csv']));
		$this->assertSame(['https://mirror.example.org/upl.csv'], $this->downloads);
		$this->assertStringContainsString('1 created, 0 updated, 0 unchanged, 1 failed.', $tester->getDisplay());
	}//end testAnotherUrlAndAFailedWrite()

	/**
	 * --file imports a local copy without downloading; an unreadable file fails with the path.
	 *
	 * @return void
	 */
	public function testALocalFileIsImportedWithoutDownloading(): void {
		$file = tempnam(sys_get_temp_dir(), 'upl');
		file_put_contents($file, self::CSV);
		$tester = $this->tester();

		try {
			$this->assertSame(Command::SUCCESS, $tester->execute(['--file' => $file]));
		} finally {
			unlink($file);
		}

		$this->assertSame([], $this->downloads);
		$this->assertSame('Read 2 UPL product(s): 2 created, 0 updated, 0 unchanged, 0 failed.' . PHP_EOL, $tester->getDisplay());

		$this->assertSame(Command::FAILURE, $tester->execute(['--file' => $file]));
		$this->assertSame('UPL import failed: The file ' . $file . ' cannot be read.' . PHP_EOL, $tester->getDisplay());
	}//end testALocalFileIsImportedWithoutDownloading()
}//end class
