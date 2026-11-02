<?php

/**
 * Golden test for the payload-to-array helpers shared through traits.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Util
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Util;

use JsonSerializable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use stdClass;
use Throwable;

/**
 * Runs each class's private toArray() on the same representative inputs and
 * compares the result with PayloadToArrayGolden.json. Set
 * PAYLOAD_TO_ARRAY_GOLDEN_RECORD=1 to rewrite the golden file.
 *
 * @covers \OCA\Pipelinq\Util\PayloadToArray
 * @covers \OCA\Pipelinq\Util\SerialisedToArray
 * @covers \OCA\Pipelinq\Util\SerialisedPayload
 * @covers \OCA\Pipelinq\Util\NullablePayloadToArray
 */
class PayloadToArrayGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/PayloadToArrayGolden.json';

	private const CLASSES = [
		\OCA\Pipelinq\BackgroundJob\BlastSendJob::class,
		\OCA\Pipelinq\BackgroundJob\WebhookProcessorJob::class,
		\OCA\Pipelinq\Service\AttributionService::class,
		\OCA\Pipelinq\Service\CampaignPerformanceService::class,
		\OCA\Pipelinq\Service\ChannelProviderRepository::class,
		\OCA\Pipelinq\Service\Marketing\ListObjectStore::class,
		\OCA\Pipelinq\Service\SegmentService::class,
		\OCA\Pipelinq\Service\TemplateApprovalSyncService::class,
		\OCA\Pipelinq\Service\TrackingLinkService::class,
		\OCA\Pipelinq\Service\Egress\ConnectorEgress::class,
		\OCA\Pipelinq\Service\SearchConsole\SearchQueryReportService::class,
		\OCA\Pipelinq\Service\ShillinqStockReader::class,
		\OCA\Pipelinq\Service\AnalyticsService::class,
		\OCA\Pipelinq\Service\ForecastExportService::class,
		\OCA\Pipelinq\Service\ForecastOverrideService::class,
		\OCA\Pipelinq\Service\ForecastService::class,
		\OCA\Pipelinq\Service\QuotaService::class,
		\OCA\Pipelinq\Service\RapportageService::class,
		\OCA\Pipelinq\Service\WorklistService::class,
		\OCA\Pipelinq\Service\FirstTicketService::class,
		\OCA\Pipelinq\BackgroundJob\ReminderDispatchJob::class,
		\OCA\Pipelinq\Service\AvailabilityService::class,
		\OCA\Pipelinq\Service\WalkInQueueService::class,
	];

	/**
	 * The representative inputs, by name.
	 *
	 * @return array<string, mixed>
	 */
	private static function inputs(): array {
		$props    = new stdClass();
		$props->a = 1;
		$props->b = ['c' => 'd'];

		return [
			'null'                          => null,
			'int'                           => 42,
			'string'                        => 'abc',
			'false'                         => false,
			'empty array'                   => [],
			'list'                          => [1, 2],
			'nested array with object'      => ['a' => ['b' => 1], 'o' => new stdClass()],
			'stdClass with properties'      => $props,
			'jsonSerialize array'           => self::serialisable(value: ['id' => 'x', 'n' => ['k' => 1], 'o' => new stdClass()]),
			'jsonSerialize string'          => self::serialisable(value: 'text'),
			'jsonSerialize null'            => self::serialisable(value: null),
			'getObject array'               => self::withGetObject(value: ['g' => 1]),
			'getObject string'              => self::withGetObject(value: 'g'),
			'jsonSerialize string, getObject array' => self::withBoth(serialised: 'text', payload: ['g' => 2]),
			'jsonSerialize array, getObject array'  => self::withBoth(serialised: ['j' => 1], payload: ['g' => 3]),
			'toArray method array'          => self::withToArray(value: ['t' => 1]),
			'toArray method string'         => self::withToArray(value: 't'),
		];
	}//end inputs()

	/**
	 * An object whose jsonSerialize() returns the given value.
	 *
	 * @param mixed $value The serialised value.
	 *
	 * @return object
	 */
	private static function serialisable(mixed $value): object {
		return new class ($value) implements JsonSerializable {
			/**
			 * @param mixed $value The serialised value.
			 */
			public function __construct(private mixed $value) {
			}//end __construct()

			/**
			 * The serialised value.
			 *
			 * @return mixed
			 */
			public function jsonSerialize(): mixed {
				return $this->value;
			}//end jsonSerialize()
		};
	}//end serialisable()

	/**
	 * An object whose getObject() returns the given value.
	 *
	 * @param mixed $value The payload.
	 *
	 * @return object
	 */
	private static function withGetObject(mixed $value): object {
		return new class ($value) {
			/**
			 * @param mixed $value The payload.
			 */
			public function __construct(private mixed $value) {
			}//end __construct()

			/**
			 * The payload.
			 *
			 * @return mixed
			 */
			public function getObject(): mixed {
				return $this->value;
			}//end getObject()
		};
	}//end withGetObject()

	/**
	 * An object with both jsonSerialize() and getObject().
	 *
	 * @param mixed $serialised The jsonSerialize() value.
	 * @param mixed $payload    The getObject() value.
	 *
	 * @return object
	 */
	private static function withBoth(mixed $serialised, mixed $payload): object {
		return new class ($serialised, $payload) implements JsonSerializable {
			/**
			 * @param mixed $serialised The jsonSerialize() value.
			 * @param mixed $payload    The getObject() value.
			 */
			public function __construct(private mixed $serialised, private mixed $payload) {
			}//end __construct()

			/**
			 * The serialised value.
			 *
			 * @return mixed
			 */
			public function jsonSerialize(): mixed {
				return $this->serialised;
			}//end jsonSerialize()

			/**
			 * The payload.
			 *
			 * @return mixed
			 */
			public function getObject(): mixed {
				return $this->payload;
			}//end getObject()
		};
	}//end withBoth()

	/**
	 * An object whose toArray() returns the given value.
	 *
	 * @param mixed $value The toArray() value.
	 *
	 * @return object
	 */
	private static function withToArray(mixed $value): object {
		return new class ($value) {
			/**
			 * @param mixed $value The toArray() value.
			 */
			public function __construct(private mixed $value) {
			}//end __construct()

			/**
			 * The array form.
			 *
			 * @return mixed
			 */
			public function toArray(): mixed {
				return $this->value;
			}//end toArray()
		};
	}//end withToArray()

	/**
	 * Every class crossed with every input name.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function cases(): array {
		$cases = [];
		foreach (self::CLASSES as $class) {
			foreach (array_keys(self::inputs()) as $input) {
				$cases[$class . ' / ' . $input] = [$class, $input];
			}
		}

		return $cases;
	}//end cases()

	/**
	 * A result in a JSON-safe, type-preserving form.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private static function encode(mixed $value): mixed {
		if (is_array($value) === true) {
			$out = [];
			foreach ($value as $key => $item) {
				$out[] = ['key' => $key, 'value' => self::encode(value: $item)];
			}

			return ['array' => $out];
		}

		if ($value instanceof stdClass) {
			return ['object' => 'stdClass'];
		}

		if (is_object($value) === true) {
			return ['object' => 'other'];
		}

		return [gettype($value) => $value];
	}//end encode()

	/**
	 * Run one class's toArray() on one input.
	 *
	 * @param string $class The class.
	 * @param string $input The input name.
	 *
	 * @return mixed
	 */
	private static function invokeToArray(string $class, string $input): mixed {
		$subject = (new ReflectionClass($class))->newInstanceWithoutConstructor();
		$method  = new ReflectionMethod($class, 'toArray');
		try {
			return self::encode(value: $method->invoke($subject, self::inputs()[$input]));
		} catch (Throwable $e) {
			return ['throws' => get_class($e)];
		}
	}//end invokeToArray()

	/**
	 * The helper answers as recorded.
	 *
	 * @param string $class The class.
	 * @param string $input The input name.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testToArrayMatchesTheGolden(string $class, string $input): void {
		$actual = self::invokeToArray(class: $class, input: $input);
		$key    = $class . ' / ' . $input;

		if (getenv('PAYLOAD_TO_ARRAY_GOLDEN_RECORD') !== false) {
			$golden = [];
			if (file_exists(self::GOLDEN) === true) {
				$golden = json_decode((string) file_get_contents(self::GOLDEN), true);
			}

			$golden[$key] = $actual;
			ksort($golden);
			file_put_contents(
				self::GOLDEN,
				json_encode($golden, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n"
			);
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true);
		$this->assertArrayHasKey(key: $key, array: $golden);
		$this->assertSame(expected: $golden[$key], actual: $actual);
	}//end testToArrayMatchesTheGolden()
}//end class
