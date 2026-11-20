<?php

/**
 * Golden test for the Belastingdienst export pack renderings and manifest.
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
 *
 * @spec openspec/specs/pos-kassakoppeling-audit/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\BelastingdienstExportService;
use OCA\Pipelinq\Service\KassakoppelingSignatureService;
use PHPUnit\Framework\TestCase;

/**
 * Pins the XML and JSON bodies byte for byte and every manifest field.
 */
class BelastingdienstExportServiceGoldenTest extends TestCase {
	/**
	 * Entries over three registers, out of timestamp order, with every value type.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function entries(): array {
		return [
			['id' => 'e3', 'timestamp' => '2026-01-02T10:00:00Z', 'registerNumber' => 'R2', 'amount' => '150', 'verified' => true, 'description' => 'A & <b>'],
			['uuid' => 'u1', 'timestamp' => '2026-01-01T09:00:00Z', 'registerNumber' => 'R1', 'verified' => false, 'taxAmount' => 21],
			['id' => 'e2', 'timestamp' => '2026-01-01T08:00:00Z', 'registerNumber' => 'R2', 'itemCount' => 3],
			['id' => 'e4', 'timestamp' => '2026-01-03T08:00:00Z', 'registerNumber' => 'R3'],
			['id' => 'e5', 'timestamp' => '2026-01-01T07:00:00Z', 'registerNumber' => '', 'operatorId' => 'op'],
			['id' => 'e6', 'timestamp' => '2026-01-01T06:00:00Z', 'registerNumber' => 'R1'],
		];
	}//end entries()

	/**
	 * Exporter whose chain check breaks on R2 and R3 and records what it was asked.
	 *
	 * @param list<array{string, list<string>}> $asked Collected register / id order pairs.
	 *
	 * @return BelastingdienstExportService
	 */
	private function exporter(array &$asked): BelastingdienstExportService {
		$signature = $this->createMock(KassakoppelingSignatureService::class);
		$signature->method('verifyHashChain')->willReturnCallback(
			static function (array $entries) use (&$asked): array {
				$register = (string)$entries[0]['registerNumber'];
				$asked[] = [$register, array_map(static fn (array $e) => (string)($e['id'] ?? $e['uuid']), $entries)];
				if ($register === 'R1') {
					return ['chainValid' => true, 'brokenAt' => null, 'checked' => count($entries)];
				}

				return ['chainValid' => false, 'brokenAt' => ($register === 'R2' ? 7 : 9), 'checked' => count($entries)];
			}
		);
		return new BelastingdienstExportService($signature);
	}//end exporter()

	/**
	 * The manifest: sorted registers, per-register chain asks in timestamp order, first break wins.
	 *
	 * @return void
	 */
	public function testManifest(): void {
		$asked = [];
		$manifest = $this->exporter($asked)->buildManifest(self::entries(), '2026-01-01', '2026-01-31');

		self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', $manifest['exportDate']);
		unset($manifest['exportDate']);
		self::assertSame(
			[
				'entryCount' => 6,
				'dateRange' => ['from' => '2026-01-01', 'to' => '2026-01-31'],
				'registerList' => ['R1', 'R2', 'R3'],
				'chainIntegrity' => 'invalid',
				'chainStatus' => 'Broken at entry 7 on register R2',
				'perRegisterStatus' => [
					'R1' => ['chainValid' => true, 'brokenAt' => null, 'checked' => 2],
					'R2' => ['chainValid' => false, 'brokenAt' => 7, 'checked' => 2],
					'R3' => ['chainValid' => false, 'brokenAt' => 9, 'checked' => 1],
				],
				'signatureAlgorithm' => 'HMAC-SHA256',
			],
			$manifest
		);
		self::assertSame([['R1', ['e6', 'u1']], ['R2', ['e2', 'e3']], ['R3', ['e4']]], $asked);
	}//end testManifest()

	/**
	 * A manifest without entries is valid and empty.
	 *
	 * @return void
	 */
	public function testEmptyManifest(): void {
		$asked = [];
		$manifest = $this->exporter($asked)->buildManifest([], '', '');
		unset($manifest['exportDate']);
		self::assertSame(
			['entryCount' => 0, 'dateRange' => ['from' => '', 'to' => ''], 'registerList' => [], 'chainIntegrity' => 'valid', 'chainStatus' => '', 'perRegisterStatus' => [], 'signatureAlgorithm' => 'HMAC-SHA256'],
			$manifest
		);
		self::assertSame([], $asked);
	}//end testEmptyManifest()

	/**
	 * A fixed manifest for the renderings.
	 *
	 * @param string $chainStatus Chain status text.
	 *
	 * @return array<string, mixed>
	 */
	private static function manifest(string $chainStatus): array {
		return [
			'exportDate' => '2026-02-01T00:00:00+00:00',
			'entryCount' => 6,
			'dateRange' => ['from' => '2026-01-01', 'to' => '2026-01-31'],
			'registerList' => ['R1', 'R2'],
			'chainIntegrity' => ($chainStatus === '' ? 'valid' : 'invalid'),
			'chainStatus' => $chainStatus,
		];
	}//end manifest()

	/**
	 * XML and JSON bodies with and without a chain status match the fixtures byte for byte.
	 *
	 * @return void
	 */
	public function testRenderings(): void {
		$asked = [];
		$exporter = $this->exporter($asked);
		$bodies = [
			'xml-broken' => $exporter->exportAsXml(self::entries(), self::manifest('Broken at entry 7 on register R2')),
			'xml-valid' => $exporter->exportAsXml(array_slice(self::entries(), 0, 2), self::manifest('')),
			'json-broken' => $exporter->exportAsJson(self::entries(), self::manifest('Broken at entry 7 on register R2')),
			'json-valid' => $exporter->exportAsJson([], self::manifest('')),
		];
		foreach ($bodies as $name => $body) {
			$path = __DIR__.'/Fixtures/belastingdienst-export-'.$name.'.txt';
			self::assertSame(file_get_contents($path), $body, $name);
		}
	}//end testRenderings()

	/**
	 * Without a manifest the renderings compute one with an empty date range.
	 *
	 * @return void
	 */
	public function testRenderingsComputeTheManifest(): void {
		$asked = [];
		$xml = $this->exporter($asked)->exportAsXml(self::entries());
		self::assertStringContainsString('<DateRange from="" to=""/>', $xml);
		self::assertStringContainsString('<ChainStatus>Broken at entry 7 on register R2</ChainStatus>', $xml);
		$json = json_decode($this->exporter($asked)->exportAsJson(self::entries()), true);
		self::assertSame(['from' => '', 'to' => ''], $json['exportMetadata']['dateRange']);
		self::assertSame('invalid', $json['exportMetadata']['chainIntegrity']);
		self::assertCount(6, $asked);
	}//end testRenderingsComputeTheManifest()
}//end class
