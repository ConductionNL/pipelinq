<?php

/**
 * Unit tests for the churnRisk property on the client schema, read as the
 * import composes the register, and validated against what ClientRiskScore
 * writes.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Settings
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use DateTimeImmutable;
use OCA\Pipelinq\Service\ClientRiskScore;
use OCA\Pipelinq\Service\ClientRiskService;
use OCA\Pipelinq\Service\ConfigFileLoaderService;
use OCP\App\IAppManager;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * The client schema carries churnRisk in the shape the risk service writes.
 *
 * @spec openspec/changes/clients-history-summary-and-risk/specs/client-insight/spec.md#requirement-every-client-carries-a-risk-level-with-its-reasons-req-cin-001
 */
class ChurnRiskSchemaTest extends TestCase {

	/**
	 * The churnRisk property as the import composes it.
	 *
	 * @return array<string, mixed>
	 */
	private function property(): array {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn(dirname(__DIR__, 3));

		$register = (new ConfigFileLoaderService($appManager))->loadConfigurationFile();
		$client = ($register['components']['schemas']['client'] ?? []);
		$this->assertSame('client', $client['slug'] ?? null, 'The client schema keeps its slug');
		$this->assertNotContains('churnRisk', (array)($client['required'] ?? []));

		$property = ($client['properties']['churnRisk'] ?? null);
		$this->assertIsArray($property, 'The client schema declares churnRisk');

		return $property;
	}//end property()

	/**
	 * churnRisk is an object with the three levels, and it is not readOnly,
	 * because OpenRegister refuses an update that changes a readOnly value.
	 *
	 * @return void
	 */
	public function testClientDeclaresAWritableChurnRisk(): void {
		$property = $this->property();

		$this->assertSame('object', $property['type']);
		$this->assertSame(['low', 'medium', 'high'], $property['properties']['level']['enum']);
		$this->assertArrayNotHasKey('readOnly', $property);
	}//end testClientDeclaresAWritableChurnRisk()

	/**
	 * What the score writes validates against the declared property, every signal included.
	 *
	 * @return void
	 */
	public function testWhatTheScoreWritesIsValid(): void {
		$assessed = (new ClientRiskScore())->evaluate(
			[
				'contracts' => [['id' => 'c-1', 'status' => 'churned', 'endDate' => '2026-10-29'], ['id' => 'c-2', 'status' => 'expiring', 'endDate' => '2026-12-31']],
				'detractors' => [['id' => 'r-1', 'submittedAt' => '2026-10-15T00:00:00+00:00']],
				'openComplaints' => 2,
				'slaBreached' => 1,
				'lastActivityAt' => '2026-01-01T00:00:00+00:00',
			],
			ClientRiskService::DEFAULT_WEIGHTS,
			new DateTimeImmutable('2026-10-30T12:00:00+00:00')
		);
		$this->assertCount(6, $assessed['reasons']);

		$schema = json_decode((string)json_encode(['type' => 'object', 'properties' => ['churnRisk' => $this->property()]]));
		$valid = (new Validator())->validate(json_decode((string)json_encode(['churnRisk' => $assessed])), $schema);
		$this->assertTrue($valid->isValid());

		$bad = $assessed;
		$bad['level'] = 'severe';
		$this->assertFalse((new Validator())->validate(json_decode((string)json_encode(['churnRisk' => $bad])), $schema)->isValid(), 'Control: an unknown level is refused');
	}//end testWhatTheScoreWritesIsValid()
}//end class
