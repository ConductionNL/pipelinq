<?php

/**
 * Unit tests for KassakoppelingAuditService.
 *
 * Covers the append-only create path (server-recomputed signature + chain
 * hash, client-supplied signature / hash fields are stripped), the per-
 * register chain linkage (a new entry's previousHash equals the prior
 * entry's currentHash on the SAME register), the filtered list endpoint,
 * single-entry fetch, signature + chain re-verification, and the date-range
 * Belastingdienst export pack with `exportedAt` stamping. A FakeObjectService
 * stands in for OR's ObjectService (keyed by schema + uuid in-memory), the
 * REAL KassakoppelingSignatureService is used so cryptography stays end-to-
 * end, and BelastingdienstExportService is the real formatter.
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

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\BelastingdienstExportService;
use OCA\Pipelinq\Service\KassakoppelingAuditService;
use OCA\Pipelinq\Service\KassakoppelingSignatureService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * In-memory ObjectService double for the audit-service tests. Saves are
 * captured for assertions; finds and findAll are filtered by schema slug
 * and (for findAll) the configured filter map.
 */
class FakeAuditObjectService {

	/**
	 * Keyed [schema][uuid] => array.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	public array $store = [];

	/**
	 * Captured saveObject calls.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $saves = [];

	/**
	 * Auto-incrementing uuid generator (matches OR contract — opaque string).
	 *
	 * @var int
	 */
	private int $cursor = 0;

	/**
	 * @return array<string, mixed>|null
	 */
	public function find(string $id, string $register = '', string $schema = ''): ?array {
		return $this->store[$schema][$id] ?? null;
	}//end find()

	/**
	 * @param array<string, mixed> $config
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function findAll(array $config = []): array {
		$filters = ($config['filters'] ?? []);
		$schema = (string)($filters['schema'] ?? '');

		return array_values($this->store[$schema] ?? []);
	}//end findAll()

	/**
	 * @param array<string, mixed>|object $object
	 *
	 * @return array<string, mixed>
	 */
	public function saveObject(
		array|object $object,
		?array $extend = [],
		string|int|null $register = null,
		string|int|null $schema = null,
		?string $uuid = null,
	): array {
		$arrayObject = is_array($object) === true ? $object : (array)$object;
		$schemaKey = (string)$schema;
		$resolvedUuid = $uuid;
		if ($resolvedUuid === null || $resolvedUuid === '') {
			$this->cursor++;
			$resolvedUuid = 'aud-' . $this->cursor;
		}

		$arrayObject['id'] = $resolvedUuid;
		$arrayObject['uuid'] = $resolvedUuid;
		$this->store[$schemaKey][$resolvedUuid] = $arrayObject;
		$this->saves[] = [
			'schema' => $schemaKey,
			'uuid' => $resolvedUuid,
			'object' => $arrayObject,
		];

		return $arrayObject;
	}//end saveObject()

}//end class

/**
 * Tests for KassakoppelingAuditService.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Wires the fakes the audit
 *  lifecycle legitimately exercises (container, app config, real signature,
 *  real exporter, in-memory OR fake).
 */
class KassakoppelingAuditServiceTest extends TestCase {

	/**
	 * The service under test.
	 *
	 * @var KassakoppelingAuditService
	 */
	private KassakoppelingAuditService $service;

	/**
	 * In-memory OR double.
	 *
	 * @var FakeAuditObjectService
	 */
	private FakeAuditObjectService $objects;

	/**
	 * Build the audit service with real signature + exporter and an in-memory
	 * OR fake. The app config returns a constant register / schema slug.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new FakeAuditObjectService();

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($this->objects);

		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')
			->willReturnCallback(
				static function (string $app, string $key, string $default = '') {
					$values = [
						'register' => 'pipelinq',
						'kassakoppelingAuditLog_schema' => 'kassakoppelingAuditLog',
						'kassakoppeling.secret' => 'unit-test-secret',
					];

					return $values[$key] ?? $default;
				}
			);

		$config = $this->createMock(originalClassName: IConfig::class);

		$signature = new KassakoppelingSignatureService(appConfig: $appConfig, config: $config);
		$exporter = new BelastingdienstExportService(signature: $signature);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);

		$this->service = new KassakoppelingAuditService(
			container: $container,
			appConfig: $appConfig,
			signature: $signature,
			exporter: $exporter,
			logger: $logger,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

	}//end setUp()

	/**
	 * A reusable sale-entry payload.
	 *
	 * @param string $register The register slug.
	 *
	 * @return array<string, mixed>
	 */
	private function saleInput(string $register = 'REG-001'): array {
		return [
			'operatorId' => 'user_john',
			'registerNumber' => $register,
			'action' => 'sale',
			'amount' => 4950,
			'itemCount' => 3,
			'taxAmount' => 870,
			'timestamp' => '2026-05-20T08:15:30+00:00',
			'transactionUuid' => 'uuid-txn-001',
			'description' => 'Regular sale',
		];

	}//end saleInput()

	/**
	 * createEntry() persists with a server-recomputed signature, the genesis
	 * previousHash and a recomputed currentHash; verified starts as null.
	 *
	 * @return void
	 */
	public function testCreateEntryPersistsWithSignaturesAndGenesisHash(): void {
		$entry = $this->service->createEntry(data: $this->saleInput());

		$this->assertNotEmpty($entry['id']);
		$this->assertSame('0', $entry['previousHash']);
		$this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $entry['signature']);
		$this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $entry['currentHash']);
		$this->assertNull($entry['verified']);
		$this->assertNull($entry['exportedAt']);

	}//end testCreateEntryPersistsWithSignaturesAndGenesisHash()

	/**
	 * createEntry() strips client-supplied signature / hash / verified /
	 * exportedAt fields and recomputes server-side. An attacker pre-signing
	 * a tampered amount can NOT influence the stored signature.
	 *
	 * @return void
	 */
	public function testCreateEntryIgnoresClientSuppliedSignatureAndHashes(): void {
		$data = $this->saleInput();
		$data['signature'] = str_repeat('a', 64);
		$data['previousHash'] = str_repeat('b', 64);
		$data['currentHash'] = str_repeat('c', 64);
		$data['verified'] = true;
		$data['exportedAt'] = '2026-01-01T00:00:00+00:00';

		$entry = $this->service->createEntry(data: $data);

		$this->assertNotSame(str_repeat('a', 64), $entry['signature']);
		$this->assertSame('0', $entry['previousHash']);
		$this->assertNotSame(str_repeat('c', 64), $entry['currentHash']);
		$this->assertNull($entry['verified']);
		$this->assertNull($entry['exportedAt']);

	}//end testCreateEntryIgnoresClientSuppliedSignatureAndHashes()

	/**
	 * Two consecutive entries on the SAME register are chained: the second
	 * entry's previousHash equals the first entry's currentHash.
	 *
	 * @return void
	 */
	public function testCreateEntryChainsPerRegister(): void {
		$first = $this->service->createEntry(data: $this->saleInput());
		$second = $this->service->createEntry(
			data: array_merge(
				$this->saleInput(),
				[
					'action' => 'void',
					'timestamp' => '2026-05-20T08:18:15+00:00',
				]
			)
		);

		$this->assertSame($first['currentHash'], $second['previousHash']);
		$this->assertNotSame($first['currentHash'], $second['currentHash']);

	}//end testCreateEntryChainsPerRegister()

	/**
	 * Two registers maintain independent chains — an entry on REG-002 starts
	 * from the genesis sentinel even if entries exist on REG-001.
	 *
	 * @return void
	 */
	public function testCreateEntryChainsAreIndependentPerRegister(): void {
		$this->service->createEntry(data: $this->saleInput(register: 'REG-001'));
		$second = $this->service->createEntry(data: $this->saleInput(register: 'REG-002'));

		$this->assertSame('0', $second['previousHash']);

	}//end testCreateEntryChainsAreIndependentPerRegister()

	/**
	 * validateInput rejects an entry with an empty registerNumber.
	 *
	 * @return void
	 */
	public function testCreateEntryRejectsMissingRegister(): void {
		$data = $this->saleInput();
		$data['registerNumber'] = '';

		$this->expectException(exception: OCSBadRequestException::class);
		$this->service->createEntry(data: $data);

	}//end testCreateEntryRejectsMissingRegister()

	/**
	 * validateInput rejects an unknown action enum value.
	 *
	 * @return void
	 */
	public function testCreateEntryRejectsUnknownAction(): void {
		$data = $this->saleInput();
		$data['action'] = 'pirate';

		$this->expectException(exception: OCSBadRequestException::class);
		$this->service->createEntry(data: $data);

	}//end testCreateEntryRejectsUnknownAction()

	/**
	 * listEntries returns entries sorted ascending by timestamp.
	 *
	 * @return void
	 */
	public function testListEntriesReturnsAllSortedByTimestamp(): void {
		$this->service->createEntry(
			data: array_merge($this->saleInput(), ['timestamp' => '2026-05-20T10:00:00+00:00'])
		);
		$this->service->createEntry(
			data: array_merge($this->saleInput(), ['timestamp' => '2026-05-20T08:00:00+00:00', 'action' => 'void'])
		);

		$entries = $this->service->listEntries();
		$this->assertCount(2, $entries);
		$this->assertSame('2026-05-20T08:00:00+00:00', $entries[0]['timestamp']);
		$this->assertSame('2026-05-20T10:00:00+00:00', $entries[1]['timestamp']);

	}//end testListEntriesReturnsAllSortedByTimestamp()

	/**
	 * listEntries filters by registerNumber.
	 *
	 * @return void
	 */
	public function testListEntriesFiltersByRegister(): void {
		$this->service->createEntry(data: $this->saleInput(register: 'REG-001'));
		$this->service->createEntry(data: $this->saleInput(register: 'REG-002'));

		$entries = $this->service->listEntries(filters: ['registerNumber' => 'REG-002']);
		$this->assertCount(1, $entries);
		$this->assertSame('REG-002', $entries[0]['registerNumber']);

	}//end testListEntriesFiltersByRegister()

	/**
	 * listEntries filters by action.
	 *
	 * @return void
	 */
	public function testListEntriesFiltersByAction(): void {
		$this->service->createEntry(data: $this->saleInput());
		$this->service->createEntry(
			data: array_merge($this->saleInput(), ['action' => 'void', 'timestamp' => '2026-05-20T08:18:15+00:00'])
		);

		$entries = $this->service->listEntries(filters: ['action' => 'void']);
		$this->assertCount(1, $entries);
		$this->assertSame('void', $entries[0]['action']);

	}//end testListEntriesFiltersByAction()

	/**
	 * getEntry throws OCSNotFoundException when the entry is missing.
	 *
	 * @return void
	 */
	public function testGetEntryThrowsForMissingId(): void {
		$this->expectException(exception: OCSNotFoundException::class);
		$this->service->getEntry(id: 'does-not-exist');

	}//end testGetEntryThrowsForMissingId()

	/**
	 * verifyEntry flips verified to true on a clean entry and updates the
	 * stored object.
	 *
	 * @return void
	 */
	public function testVerifyEntryMarksValidEntryAsVerified(): void {
		$created = $this->service->createEntry(data: $this->saleInput());
		$result = $this->service->verifyEntry(id: (string)$created['id']);

		$this->assertTrue($result['verified']);
		$this->assertTrue($result['signatureValid']);
		$this->assertTrue($result['hashValid']);
		$this->assertTrue($result['entry']['verified']);

	}//end testVerifyEntryMarksValidEntryAsVerified()

	/**
	 * verifyEntry returns verified=false on a tampered entry — we manually
	 * mutate the stored amount and confirm both signature and hash flags fall.
	 *
	 * @return void
	 */
	public function testVerifyEntryDetectsTampering(): void {
		$created = $this->service->createEntry(data: $this->saleInput());
		$id = (string)$created['id'];

		// Tamper directly in the in-memory store.
		$this->objects->store['kassakoppelingAuditLog'][$id]['amount'] = 9999;

		$result = $this->service->verifyEntry(id: $id);
		$this->assertFalse($result['verified']);
		$this->assertFalse($result['signatureValid']);
		$this->assertFalse($result['hashValid']);

	}//end testVerifyEntryDetectsTampering()

	/**
	 * exportForBelastingdienst returns an XML payload with all entries in the
	 * range, the chainIntegrity manifest field set to valid and stamps the
	 * exportedAt timestamp on every included entry.
	 *
	 * @return void
	 */
	public function testExportForBelastingdienstStampsExportedAt(): void {
		$this->service->createEntry(data: $this->saleInput());
		$this->service->createEntry(
			data: array_merge(
				$this->saleInput(),
				['action' => 'void', 'timestamp' => '2026-05-20T08:18:15+00:00']
			)
		);

		$export = $this->service->exportForBelastingdienst(
			fromDate: '2026-05-20',
			toDate: '2026-05-20',
			format: 'xml'
		);

		$this->assertSame(2, $export['entryCount']);
		$this->assertSame('application/xml', $export['contentType']);
		$this->assertStringContainsString('<KassakoppelingExport>', $export['body']);
		$this->assertStringContainsString('<ChainIntegrity>valid</ChainIntegrity>', $export['body']);

		$stored = array_values($this->objects->store['kassakoppelingAuditLog']);
		foreach ($stored as $entry) {
			$this->assertNotEmpty($entry['exportedAt']);
		}

	}//end testExportForBelastingdienstStampsExportedAt()

	/**
	 * exportForBelastingdienst returns JSON when format=json.
	 *
	 * @return void
	 */
	public function testExportForBelastingdienstAsJson(): void {
		$this->service->createEntry(data: $this->saleInput());

		$export = $this->service->exportForBelastingdienst(
			fromDate: '2026-05-20',
			toDate: '2026-05-20',
			format: 'json'
		);

		$this->assertSame('application/json', $export['contentType']);
		$payload = json_decode($export['body'], true);
		$this->assertIsArray($payload);
		$this->assertArrayHasKey('exportMetadata', $payload);
		$this->assertArrayHasKey('entries', $payload);
		$this->assertSame(1, count($payload['entries']));

	}//end testExportForBelastingdienstAsJson()

	/**
	 * exportForBelastingdienst rejects an inverted date range with an
	 * OCSBadRequestException.
	 *
	 * @return void
	 */
	public function testExportForBelastingdienstRejectsInvertedRange(): void {
		$this->expectException(exception: OCSBadRequestException::class);
		$this->service->exportForBelastingdienst(
			fromDate: '2026-05-21',
			toDate: '2026-05-20',
			format: 'xml'
		);

	}//end testExportForBelastingdienstRejectsInvertedRange()

}//end class
