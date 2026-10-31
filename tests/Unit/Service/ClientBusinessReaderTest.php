<?php

/**
 * Tests for ClientBusinessReader: the client page's projects, invoicing,
 * leads and contracts card (boards PqKlant and PqOrganisatie).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
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

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ClientBusinessReader;
use OCA\Pipelinq\Service\ShillinqInvoiceReader;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The client's business, as sentences for the side card.
 */
class ClientBusinessReaderTest extends TestCase {

	/**
	 * Build the reader over a fake object store.
	 *
	 * @param array<string, mixed>              $client   The client record.
	 * @param array<string, array<int, mixed>>  $rows     The rows per schema.
	 * @param array<int, array<string, mixed>>  $invoices The client's invoices in shillinq.
	 * @param bool                              $shillinq Whether shillinq is installed.
	 *
	 * @return ClientBusinessReader
	 */
	private function reader(array $client, array $rows, array $invoices = [], bool $shillinq = true): ClientBusinessReader {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$keys   = ['register' => 'pipelinq', 'client_schema' => 'client', 'lead_schema' => 'lead', 'contract_schema' => 'salesContract'];
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $keys[$key] ?? $default
		);
		$store = new class ($client, $rows) {
			/**
			 * The fake store.
			 *
			 * @param array<string, mixed>             $client The client.
			 * @param array<string, array<int, mixed>> $rows   The rows per schema.
			 */
			public function __construct(private array $client, private array $rows) {
			}

			/**
			 * Find the client.
			 *
			 * @param mixed ...$args The query, unused.
			 *
			 * @return array<string, mixed>
			 */
			public function find(mixed ...$args): array {
				return $this->client;
			}

			/**
			 * List rows of a schema that match the filters.
			 *
			 * @param array<string, mixed> $config  The query.
			 * @param mixed                ...$args The rest, unused.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $config = [], mixed ...$args): array {
				$filters = $config['filters'];
				return array_values(
					array_filter(
						$this->rows[$filters['schema']] ?? [],
						function (array $row) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if (in_array($key, ['register', 'schema'], true) === false && ($row[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}
		};
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn($store);
		$shillinqReader = $this->createMock(originalClassName: ShillinqInvoiceReader::class);
		$shillinqReader->method('isAvailable')->willReturn($shillinq);
		$shillinqReader->method('invoicesFor')->willReturn($invoices);
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text, array $params = []): string => vsprintf($text, $params));
		$l10n->method('n')->willReturnCallback(
			function (string $one, string $many, int $count, array $params = []): string {
				$text = $many;
				if ($count === 1) {
					$text = $one;
				}

				return vsprintf(str_replace('%n', (string)$count, $text), $params);
			}
		);
		$l10n->method('l')->willReturn('1 Jan 2027');
		$urls = $this->createMock(originalClassName: IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(fn (string $path): string => 'https://cloud.example'.$path);

		return new ClientBusinessReader(
			appConfig: $config,
			container: $container,
			invoices: $shillinqReader,
			l10n: $l10n,
			urlGenerator: $urls,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

	}//end reader()

	/**
	 * A resident with one planned project and no invoices reads as the board.
	 *
	 * @return void
	 */
	public function testAResidentReadsProjectsAndInvoices(): void {
		$reader = $this->reader(
			client: ['type' => 'person', 'shillinqOrganisationRef' => 'deb-1'],
			rows: ['project' => [['client' => 'c1', 'title' => 'straatfeest Parkstraat 2026', 'status' => 'planned']]]
		);

		$answer = $reader->businessOf(clientId: 'c1');

		$this->assertSame(expected: 200, actual: $answer['status']);
		$this->assertSame(expected: '1 project: straatfeest Parkstraat 2026, planned.', actual: $answer['projectsLine']);
		$this->assertSame(expected: 'No invoices for this resident.', actual: $answer['invoicesLine']);
		$this->assertSame(expected: 'https://cloud.example/index.php/apps/shillinq/', actual: $answer['shillinqUrl']);

	}//end testAResidentReadsProjectsAndInvoices()

	/**
	 * An organisation reads its open leads, contracts and projects.
	 *
	 * @return void
	 */
	public function testAnOrganisationReadsLeadsContractsAndProjects(): void {
		$reader = $this->reader(
			client: ['type' => 'organization', 'shillinqOrganisationRef' => 'deb-2'],
			rows: [
				'lead'          => [
					['client' => 'c2', 'status' => 'open', 'title' => 'evenementenpakket', 'value' => 12500],
					['client' => 'c2', 'status' => 'won', 'title' => 'oud', 'value' => 1],
				],
				'salesContract' => [
					['clientRef' => 'c2', 'status' => 'active', 'title' => 'Huurovereenkomst zaal', 'autoRenew' => true, 'endDate' => '2027-01-01'],
				],
				'project'       => [['client' => 'c2', 'title' => 'speeldagen Oosterhof 2026', 'status' => 'active']],
			],
			invoices: [['lifecycleState' => 'paid'], ['lifecycleState' => 'sent']]
		);

		$answer = $reader->businessOf(clientId: 'c2');

		$this->assertMatchesRegularExpression(pattern: '/^1 open lead: evenementenpakket, .*12\.?500/', string: $answer['leadsLine']);
		$this->assertSame(expected: 'Huurovereenkomst zaal, renews 1 Jan 2027', actual: $answer['contractsLine']);
		$this->assertSame(expected: '1 project: speeldagen Oosterhof 2026, active.', actual: $answer['projectsLine']);
		$this->assertSame(expected: '2 invoices, 1 open.', actual: $answer['invoicesLine']);

	}//end testAnOrganisationReadsLeadsContractsAndProjects()

	/**
	 * Without shillinq there is no invoices line and no link.
	 *
	 * @return void
	 */
	public function testWithoutShillinqThereIsNoInvoicing(): void {
		$reader = $this->reader(client: ['type' => 'person'], rows: [], shillinq: false);

		$answer = $reader->businessOf(clientId: 'c1');

		$this->assertSame(expected: 'No projects.', actual: $answer['projectsLine']);
		$this->assertSame(expected: '', actual: $answer['invoicesLine']);
		$this->assertSame(expected: '', actual: $answer['shillinqUrl']);

	}//end testWithoutShillinqThereIsNoInvoicing()
}//end class
