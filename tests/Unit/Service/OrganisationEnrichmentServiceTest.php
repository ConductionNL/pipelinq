<?php

/**
 * Unit tests for OrganisationEnrichmentService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\KvkApiClient;
use OCA\Pipelinq\Service\OrganisationEnrichmentService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Enrichment proposes KvK values for empty fields and writes the accepted ones.
 *
 * @spec openspec/changes/organisation-enrichment/specs/client-management/spec.md
 */
class OrganisationEnrichmentServiceTest extends TestCase {
	/**
	 * The objects saved, as [data, uuid].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $saved = [];

	/**
	 * A service over the given clients and KvK rows.
	 *
	 * @param array<string, array<string, mixed>> $clients Client id to data.
	 * @param array<int, array<string, mixed>>    $rows    What the KvK answers.
	 *
	 * @return OrganisationEnrichmentService The service.
	 */
	private function service(array $clients, array $rows): OrganisationEnrichmentService {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function (int|string $id) use ($clients) {
				if (isset($clients[$id]) === false) {
					return null;
				}

				return $this->createConfiguredMock(ObjectEntityInterface::class, ['jsonSerialize' => $clients[$id]]);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null) {
				$this->saved[] = [$object, $uuid];
				return $this->createMock(ObjectEntityInterface::class);
			}
		);
		$kvk = $this->createMock(KvkApiClient::class);
		$kvk->method('companyByNumber')->willReturn($rows);
		$kvk->method('searchByName')->willReturn($rows);

		return new OrganisationEnrichmentService(
			$kvk,
			$objects,
			$this->createConfiguredMock(IAppConfig::class, ['getValueString' => '5']),
			$this->createMock(LoggerInterface::class),
		);
	}//end service()

	/**
	 * Empty fields get a proposal, a different value is flagged, a person is skipped.
	 *
	 * @return void
	 */
	public function testProposesEmptyFieldsFlagsDifferencesAndSkipsPersons(): void {
		$service = $this->service(
			clients: [
				'c1' => ['name' => 'Bakkerij De Molen', 'type' => 'organization', 'place' => 'Dordrecht'],
				'c2' => ['name' => 'Sanne de Vries', 'type' => 'person'],
			],
			rows: [
				[
					'kvkNummer' => '00001008',
					'naam' => 'Bakkerij De Molen',
					'rechtsvorm' => 'Eenmanszaak',
					'adres' => ['binnenlandsAdres' => ['plaats' => 'Zuiddrecht']],
				],
			]
		);

		$result = $service->propose(clientIds: ['c1', 'c2']);

		$byField = array_column($result['proposals'], null, 'field');
		$this->assertSame(['kvkNumber', 'legalForm', 'place'], array_keys($byField));
		$this->assertSame('00001008', $byField['kvkNumber']['found']);
		$this->assertFalse($byField['kvkNumber']['differs']);
		$this->assertSame('eenmanszaak', $byField['legalForm']['found']);
		$this->assertTrue($byField['place']['differs']);
		$this->assertSame([], $result['notFound']);
	}//end testProposesEmptyFieldsFlagsDifferencesAndSkipsPersons()

	/**
	 * An organisation the KvK does not know by its exact name is named as not found.
	 *
	 * @return void
	 */
	public function testAnOrganisationWithoutAnExactMatchIsNotFound(): void {
		$service = $this->service(
			clients: ['c1' => ['name' => 'Scoutinggroep Zuiddrecht', 'type' => 'organization']],
			rows: [['kvkNummer' => '69599084', 'naam' => 'Conduction B.V.']]
		);

		$result = $service->propose(clientIds: ['c1']);

		$this->assertSame([], $result['proposals']);
		$this->assertSame(['Scoutinggroep Zuiddrecht'], $result['notFound']);
	}//end testAnOrganisationWithoutAnExactMatchIsNotFound()

	/**
	 * Applying writes only the known fields, onto organisations, without the metadata.
	 *
	 * @return void
	 */
	public function testApplyWritesOnlyKnownFieldsOntoOrganisations(): void {
		$service = $this->service(
			clients: [
				'c1' => ['name' => 'Bakkerij De Molen', 'type' => 'organization', '@self' => ['id' => 'c1']],
				'c2' => ['name' => 'Sanne de Vries', 'type' => 'person'],
			],
			rows: []
		);

		$result = $service->apply(
			changes: [
				['clientId' => 'c1', 'field' => 'kvkNumber', 'value' => '00001008'],
				['clientId' => 'c1', 'field' => 'email', 'value' => 'x@example.org'],
				['clientId' => 'c2', 'field' => 'place', 'value' => 'Zuiddrecht'],
			]
		);

		$this->assertSame(['updated' => 1], $result);
		$this->assertCount(1, $this->saved);
		[$data, $uuid] = $this->saved[0];
		$this->assertSame('c1', $uuid);
		$this->assertSame('00001008', $data['kvkNumber']);
		$this->assertArrayNotHasKey('email', $data);
		$this->assertArrayNotHasKey('@self', $data);
	}//end testApplyWritesOnlyKnownFieldsOntoOrganisations()
}//end class
