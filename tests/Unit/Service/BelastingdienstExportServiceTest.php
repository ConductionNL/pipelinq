<?php

/**
 * Unit tests for BelastingdienstExportService.
 *
 * Asserts the XML and JSON renderings include the manifest metadata and every
 * entry, that the register list and date range are derived correctly, and that
 * a broken hash chain is reported as chainIntegrity=invalid while still
 * exporting all entries.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\BelastingdienstExportService;
use OCA\Pipelinq\Service\KassakoppelingSignatureService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Test suite for the Belastingdienst export rendering.
 */
class BelastingdienstExportServiceTest extends TestCase
{
    /**
     * The signing secret.
     *
     * @var string
     */
    private const SECRET = 'export-secret';

    /**
     * The signature service (real, so the chain is genuinely computed).
     *
     * @var KassakoppelingSignatureService
     */
    private KassakoppelingSignatureService $signatureService;

    /**
     * The service under test.
     *
     * @var BelastingdienstExportService
     */
    private BelastingdienstExportService $service;

    /**
     * Wire a real signature service and the export service.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn(self::SECRET);

        $this->signatureService = new KassakoppelingSignatureService($appConfig);
        $this->service          = new BelastingdienstExportService($this->signatureService);
    }//end setUp()

    /**
     * Build a valid, correctly-chained set of entries across two registers.
     *
     * @return array<int, array<string, mixed>> The entries.
     */
    private function validEntries(): array
    {
        $first = [
            'operatorId'      => 'user_john',
            'registerNumber'  => 'REG-001',
            'action'          => 'sale',
            'amount'          => 4950,
            'itemCount'       => 3,
            'taxAmount'       => 870,
            'timestamp'       => '2026-05-20T08:15:30+00:00',
            'transactionUuid' => 'uuid-1',
            'signature'       => 'sig-1',
            'previousHash'    => '0',
            'verified'        => true,
        ];
        $first['currentHash'] = $this->signatureService->generateHash($first, '0');

        $second = [
            'operatorId'      => 'user_maria',
            'registerNumber'  => 'REG-002',
            'action'          => 'refund',
            'amount'          => 2500,
            'itemCount'       => 1,
            'taxAmount'       => 438,
            'timestamp'       => '2026-05-21T09:45:20+00:00',
            'transactionUuid' => 'uuid-2',
            'signature'       => 'sig-2',
            'previousHash'    => $first['currentHash'],
            'verified'        => true,
        ];
        $second['currentHash'] = $this->signatureService->generateHash($second, $first['currentHash']);

        return [$first, $second];
    }//end validEntries()

    /**
     * The manifest reflects count, registers, date range and a valid chain.
     *
     * @return void
     */
    public function testBuildManifestValidChain(): void
    {
        $manifest = $this->service->buildManifest($this->validEntries());

        $this->assertSame(2, $manifest['entryCount']);
        $this->assertSame('valid', $manifest['chainIntegrity']);
        $this->assertSame('HMAC-SHA256', $manifest['signatureAlgorithm']);
        $this->assertSame(['REG-001', 'REG-002'], $manifest['registerList']);
        $this->assertSame('2026-05-20T08:15:30+00:00', $manifest['dateRange']['from']);
        $this->assertSame('2026-05-21T09:45:20+00:00', $manifest['dateRange']['to']);
    }//end testBuildManifestValidChain()

    /**
     * A broken chain is reported invalid with the offending entry index.
     *
     * @return void
     */
    public function testBuildManifestBrokenChain(): void
    {
        $entries = $this->validEntries();
        $entries[1]['amount'] = 1;

        $manifest = $this->service->buildManifest($entries);

        $this->assertSame('invalid', $manifest['chainIntegrity']);
        $this->assertStringContainsString('Broken at entry 2', $manifest['chainStatus']);
        $this->assertSame(2, $manifest['entryCount']);
    }//end testBuildManifestBrokenChain()

    /**
     * The JSON export is well-formed and includes metadata and all entries.
     *
     * @return void
     */
    public function testExportAsJson(): void
    {
        $json    = $this->service->exportAsJson($this->validEntries());
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertSame('valid', $decoded['exportMetadata']['chainIntegrity']);
        $this->assertCount(2, $decoded['entries']);
        $this->assertSame('user_john', $decoded['entries'][0]['operatorId']);
        $this->assertSame(4950, $decoded['entries'][0]['amount']);
        // The signature/secret must never leak into the export body.
        $this->assertStringNotContainsString(self::SECRET, $json);
    }//end testExportAsJson()

    /**
     * The XML export parses and carries the metadata and entry nodes.
     *
     * @return void
     */
    public function testExportAsXml(): void
    {
        $xml = $this->service->exportAsXml($this->validEntries());

        $dom = simplexml_load_string($xml);
        $this->assertNotFalse($dom);
        $this->assertSame('2', (string) $dom->Metadata->EntryCount);
        $this->assertSame('valid', (string) $dom->Metadata->ChainIntegrity);
        $this->assertSame('HMAC-SHA256', (string) $dom->Metadata->SignatureAlgorithm);
        $this->assertCount(2, $dom->Entries->Entry);
        $this->assertSame('user_john', (string) $dom->Entries->Entry[0]->OperatorId);
    }//end testExportAsXml()

    /**
     * An empty entry set still produces a valid, empty manifest.
     *
     * @return void
     */
    public function testExportEmpty(): void
    {
        $json    = $this->service->exportAsJson([]);
        $decoded = json_decode($json, true);

        $this->assertSame(0, $decoded['exportMetadata']['entryCount']);
        $this->assertSame([], $decoded['entries']);
    }//end testExportEmpty()
}//end class
