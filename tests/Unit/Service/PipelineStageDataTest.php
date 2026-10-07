<?php

/**
 * Unit tests for PipelineStageData.
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

use OCA\Pipelinq\Service\PipelineStageData;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PipelineStageData.
 */
class PipelineStageDataTest extends TestCase {
	/**
	 * The service under test.
	 *
	 * @var PipelineStageData
	 */
	private PipelineStageData $stageData;

	/**
	 * Set up the test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->stageData = new PipelineStageData();
	}//end setUp()

	/**
	 * Test sales pipeline has correct title and is default.
	 *
	 * @return void
	 */
	public function testSalesPipelineStructure(): void {
		$data = $this->stageData->getSalesPipelineData();

		$this->assertSame('Sales Pipeline', $data['title']);
		$this->assertTrue($data['isDefault']);
		$this->assertSame('EUR', $data['totalsLabel']);
		$this->assertArrayNotHasKey('viewId', $data);
		$this->assertIsArray($data['stages']);
		$this->assertIsArray($data['propertyMappings']);
	}//end testSalesPipelineStructure()

	/**
	 * Test sales pipeline has 7 stages in correct order.
	 *
	 * @return void
	 */
	public function testSalesPipelineHasSevenStages(): void {
		$data = $this->stageData->getSalesPipelineData();
		$stages = $data['stages'];

		$this->assertCount(7, $stages);
		$this->assertSame('New', $stages[0]['name']);
		$this->assertSame('Won', $stages[5]['name']);
		$this->assertSame('Lost', $stages[6]['name']);
	}//end testSalesPipelineHasSevenStages()

	/**
	 * Test sales pipeline stages have sequential order values.
	 *
	 * @return void
	 */
	public function testSalesPipelineStagesHaveSequentialOrder(): void {
		$data = $this->stageData->getSalesPipelineData();
		$stages = $data['stages'];

		foreach ($stages as $index => $stage) {
			$this->assertSame($index, $stage['order']);
		}
	}//end testSalesPipelineStagesHaveSequentialOrder()

	/**
	 * Test Won stage is marked as closed and won.
	 *
	 * @return void
	 */
	public function testWonStageIsClosedAndWon(): void {
		$data = $this->stageData->getSalesPipelineData();
		$stages = $data['stages'];

		$wonStage = null;
		foreach ($stages as $stage) {
			if ($stage['name'] === 'Won') {
				$wonStage = $stage;
				break;
			}
		}

		$this->assertNotNull($wonStage);
		$this->assertTrue($wonStage['isClosed']);
		$this->assertTrue($wonStage['isWon']);
		$this->assertSame(100, $wonStage['probability']);
	}//end testWonStageIsClosedAndWon()

	/**
	 * Test Lost stage is closed but not won.
	 *
	 * @return void
	 */
	public function testLostStageIsClosedNotWon(): void {
		$data = $this->stageData->getSalesPipelineData();
		$stages = $data['stages'];

		$lostStage = null;
		foreach ($stages as $stage) {
			if ($stage['name'] === 'Lost') {
				$lostStage = $stage;
				break;
			}
		}

		$this->assertNotNull($lostStage);
		$this->assertTrue($lostStage['isClosed']);
		$this->assertFalse($lostStage['isWon']);
		$this->assertSame(0, $lostStage['probability']);
	}//end testLostStageIsClosedNotWon()

	/**
	 * Test service requests pipeline structure.
	 *
	 * @return void
	 */
	public function testServiceRequestsPipelineStructure(): void {
		$data = $this->stageData->getServiceRequestsPipelineData();

		$this->assertSame('Service Requests', $data['title']);
		$this->assertFalse($data['isDefault']);
		$this->assertArrayNotHasKey('totalsLabel', $data);
		$this->assertIsArray($data['stages']);
	}//end testServiceRequestsPipelineStructure()

	/**
	 * Test service requests pipeline has 5 stages.
	 *
	 * @return void
	 */
	public function testServiceRequestsPipelineHasFiveStages(): void {
		$data = $this->stageData->getServiceRequestsPipelineData();
		$stages = $data['stages'];

		$this->assertCount(5, $stages);
		$this->assertSame('New', $stages[0]['name']);
		$this->assertSame('In Progress', $stages[1]['name']);
		$this->assertSame('Completed', $stages[2]['name']);
		$this->assertSame('Rejected', $stages[3]['name']);
		$this->assertSame('Converted to Case', $stages[4]['name']);
	}//end testServiceRequestsPipelineHasFiveStages()

	/**
	 * Test viewId is propagated when provided.
	 *
	 * @return void
	 */
	public function testViewIdIsPropagated(): void {
		$data = $this->stageData->getSalesPipelineData('view-123');
		$this->assertSame('view-123', $data['viewId']);

		$data2 = $this->stageData->getServiceRequestsPipelineData('view-456');
		$this->assertSame('view-456', $data2['viewId']);
	}//end testViewIdIsPropagated()

	/**
	 * Test all stages have required keys.
	 *
	 * @return void
	 */
	public function testAllStagesHaveRequiredKeys(): void {
		$allStages = array_merge($this->stageData->getSalesPipelineData()['stages'],
			$this->stageData->getServiceRequestsPipelineData()['stages']
		);

		foreach ($allStages as $stage) {
			$this->assertArrayHasKey('name', $stage);
			$this->assertArrayHasKey('order', $stage);
			$this->assertArrayHasKey('color', $stage);
			$this->assertArrayHasKey('isClosed', $stage);
			$this->assertArrayHasKey('isWon', $stage);
		}
	}//end testAllStagesHaveRequiredKeys()

	/**
	 * Test all stage colors are valid hex colors.
	 *
	 * @return void
	 */
	public function testStageColorsAreValidHex(): void {
		$allStages = array_merge($this->stageData->getSalesPipelineData()['stages'],
			$this->stageData->getServiceRequestsPipelineData()['stages']
		);

		foreach ($allStages as $stage) {
			$this->assertMatchesRegularExpression('/^#[0-9a-fA-F]{6}$/', $stage['color']);
		}
	}//end testStageColorsAreValidHex()

	/**
	 * Test property mappings for sales pipeline.
	 *
	 * @return void
	 */
	public function testSalesPipelinePropertyMappings(): void {
		$data = $this->stageData->getSalesPipelineData();
		$mappings = $data['propertyMappings'];

		$this->assertCount(2, $mappings);
		$this->assertSame('lead', $mappings[0]['schemaSlug']);
		$this->assertSame('stage', $mappings[0]['columnProperty']);
		$this->assertSame('value', $mappings[0]['totalsProperty']);
	}//end testSalesPipelinePropertyMappings()

	/**
	 * The totals label is the reporting currency handed in.
	 *
	 * @return void
	 */
	public function testSalesPipelineTotalsLabelIsTheReportingCurrency(): void {
		$data = $this->stageData->getSalesPipelineData(viewId: null, currency: 'USD');

		$this->assertSame('USD', $data['totalsLabel']);
	}//end testSalesPipelineTotalsLabelIsTheReportingCurrency()

	/**
	 * Both default pipelines satisfy the real pipeline schema fragment.
	 *
	 * Pipelinq review R5: OpenRegister refused the default pipeline with
	 * "propertyMappings.1.totalsProperty null", because the request mapping
	 * sent null for a string property. This walks the payload against the
	 * schema in lib/Settings/pipelinq_register.json, so a null (or any value
	 * of the wrong type) under a typed property fails here first.
	 *
	 * @return void
	 */
	public function testDefaultPipelinesSatisfyThePipelineSchema(): void {
		$register = json_decode(
			(string)file_get_contents(__DIR__.'/../../../lib/Settings/pipelinq_register.json'),
			true
		);
		$schema   = $register['components']['schemas']['pipeline'];

		foreach ([$this->stageData->getSalesPipelineData(), $this->stageData->getServiceRequestsPipelineData()] as $data) {
			$errors = $this->typeErrors(value: $data, schema: $schema, path: '');
			$this->assertSame([], $errors, $data['title'].' violates the pipeline schema');
		}
	}//end testDefaultPipelinesSatisfyThePipelineSchema()

	/**
	 * Collect type violations of a value against a JSON-schema fragment.
	 *
	 * Checks `type` (a null is only allowed when the type lists "null"),
	 * `required`, object `properties` and array `items`.
	 *
	 * @param mixed  $value  The value.
	 * @param array  $schema The schema fragment.
	 * @param string $path   The dotted path, for messages.
	 *
	 * @return array<int, string> The violations.
	 */
	private function typeErrors(mixed $value, array $schema, string $path): array {
		$types  = (array)($schema['type'] ?? []);
		$errors = [];
		if ($types !== [] && $this->matchesType(value: $value, types: $types) === false) {
			return [$path.' '.json_encode($value).' is not '.implode('|', $types)];
		}

		if (is_array($value) === true && array_is_list($value) === true && isset($schema['items']) === true) {
			foreach ($value as $index => $item) {
				$errors = array_merge($errors, $this->typeErrors(value: $item, schema: $schema['items'], path: $path.'.'.$index));
			}

			return $errors;
		}

		if (is_array($value) === true) {
			foreach (($schema['required'] ?? []) as $required) {
				if (array_key_exists($required, $value) === false) {
					$errors[] = $path.'.'.$required.' is missing';
				}
			}

			foreach (($schema['properties'] ?? []) as $name => $property) {
				if (array_key_exists($name, $value) === true) {
					$errors = array_merge($errors, $this->typeErrors(value: $value[$name], schema: $property, path: $path.'.'.$name));
				}
			}
		}

		return $errors;
	}//end typeErrors()

	/**
	 * Whether a value matches one of the JSON-schema types.
	 *
	 * @param mixed              $value The value.
	 * @param array<int, string> $types The allowed types.
	 *
	 * @return bool True when one type matches.
	 */
	private function matchesType(mixed $value, array $types): bool {
		foreach ($types as $type) {
			$matches = match ($type) {
				'null'    => $value === null,
				'string'  => is_string($value),
				'boolean' => is_bool($value),
				'integer' => is_int($value),
				'number'  => is_int($value) || is_float($value),
				'array'   => is_array($value) && array_is_list($value),
				'object'  => is_array($value) && ($value === [] || array_is_list($value) === false),
				default   => true,
			};
			if ($matches === true) {
				return true;
			}
		}

		return false;
	}//end matchesType()
}//end class
