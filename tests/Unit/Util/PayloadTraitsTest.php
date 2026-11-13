<?php

/**
 * Behavioural tests for the payload-to-array traits.
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
use OCA\Pipelinq\Util\AnyPayloadToArray;
use OCA\Pipelinq\Util\CastingPayloadToArray;
use OCA\Pipelinq\Util\NullablePayloadToArray;
use OCA\Pipelinq\Util\OptionalPayloadToArray;
use OCA\Pipelinq\Util\PayloadToArray;
use OCA\Pipelinq\Util\SerialisedToArray;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Each trait's toArray() states its own contract: which shapes it accepts,
 * which one wins when an object offers several, and what a miss returns.
 *
 * @covers \OCA\Pipelinq\Util\AnyPayloadToArray
 * @covers \OCA\Pipelinq\Util\CastingPayloadToArray
 * @covers \OCA\Pipelinq\Util\NullablePayloadToArray
 * @covers \OCA\Pipelinq\Util\OptionalPayloadToArray
 * @covers \OCA\Pipelinq\Util\PayloadToArray
 * @covers \OCA\Pipelinq\Util\SerialisedToArray
 * @covers \OCA\Pipelinq\Util\SerialisedPayload
 * @covers \OCA\Pipelinq\Util\EntityPayload
 * @covers \OCA\Pipelinq\Util\ObjectToPlainArray
 */
class PayloadTraitsTest extends TestCase {

	/**
	 * A host exposing one trait's private toArray().
	 *
	 * @param string $trait The trait short name.
	 *
	 * @return callable(mixed): mixed
	 */
	private static function host(string $trait): callable {
		$object = match ($trait) {
			'any'        => new class {
				use AnyPayloadToArray;

				/**
				 * @param mixed $v The value.
				 * @return mixed
				 */
				public function run(mixed $v): mixed {
					return $this->toArray(object: $v);
				}
			},
			'casting'    => new class {
				use CastingPayloadToArray;

				/**
				 * @param mixed $v The value.
				 * @return mixed
				 */
				public function run(mixed $v): mixed {
					return $this->toArray(object: $v);
				}
			},
			'nullable'   => new class {
				use NullablePayloadToArray;

				/**
				 * @param mixed $v The value.
				 * @return mixed
				 */
				public function run(mixed $v): mixed {
					return $this->toArray(object: $v);
				}
			},
			'optional'   => new class {
				use OptionalPayloadToArray;

				/**
				 * @param mixed $v The value.
				 * @return mixed
				 */
				public function run(mixed $v): mixed {
					return $this->toArray(object: $v);
				}
			},
			'payload'    => new class {
				use PayloadToArray;

				/**
				 * @param mixed $v The value.
				 * @return mixed
				 */
				public function run(mixed $v): mixed {
					return $this->toArray(value: $v);
				}
			},
			'serialised' => new class {
				use SerialisedToArray;

				/**
				 * @param mixed $v The value.
				 * @return mixed
				 */
				public function run(mixed $v): mixed {
					return $this->toArray(value: $v);
				}
			},
		};

		return [$object, 'run'];
	}//end host()

	/**
	 * An object whose jsonSerialize() returns the given value.
	 *
	 * @param mixed $value The serialised value.
	 *
	 * @return JsonSerializable
	 */
	private static function serialisable(mixed $value): JsonSerializable {
		return new class ($value) implements JsonSerializable {
			/**
			 * @param mixed $value The value.
			 */
			public function __construct(private mixed $value) {
			}

			/**
			 * @return mixed
			 */
			public function jsonSerialize(): mixed {
				return $this->value;
			}
		};
	}//end serialisable()

	/**
	 * An object exposing getObject() (an OpenRegister entity shape).
	 *
	 * @param mixed $value The payload.
	 *
	 * @return object
	 */
	private static function entity(mixed $value): object {
		return new class ($value) {
			/**
			 * @param mixed $value The payload.
			 */
			public function __construct(private mixed $value) {
			}

			/**
			 * @return mixed
			 */
			public function getObject(): mixed {
				return $this->value;
			}
		};
	}//end entity()

	/**
	 * An object exposing toArray() and a public property.
	 *
	 * @param mixed $value The toArray() result.
	 *
	 * @return object
	 */
	private static function arrayable(mixed $value): object {
		return new class ($value) {
			/**
			 * @var string
			 */
			public string $prop = 'p';

			/**
			 * @param mixed $value The value.
			 */
			public function __construct(private mixed $value) {
			}

			/**
			 * @return mixed
			 */
			public function toArray(): mixed {
				return $this->value;
			}
		};
	}//end arrayable()

	/**
	 * A plain array passes through every trait unchanged.
	 *
	 * @return void
	 */
	public function testAnArrayPassesThroughEveryTrait(): void {
		foreach (['any', 'casting', 'nullable', 'optional', 'payload', 'serialised'] as $trait) {
			$this->assertSame(['a' => 1], self::host(trait: $trait)(['a' => 1]), $trait);
		}
	}//end testAnArrayPassesThroughEveryTrait()

	/**
	 * jsonSerialize() wins over getObject() when it yields an array.
	 *
	 * @return void
	 */
	public function testJsonSerializeArrayWinsOverGetObject(): void {
		$both = new class implements JsonSerializable {
			/**
			 * @return array<string, int>
			 */
			public function jsonSerialize(): array {
				return ['j' => 1];
			}

			/**
			 * @return array<string, int>
			 */
			public function getObject(): array {
				return ['g' => 2];
			}
		};

		foreach (['any', 'casting', 'optional', 'payload', 'nullable', 'serialised'] as $trait) {
			$this->assertSame(['j' => 1], self::host(trait: $trait)($both), $trait);
		}
	}//end testJsonSerializeArrayWinsOverGetObject()

	/**
	 * A non-array jsonSerialize() falls back to getObject() in the entity traits.
	 *
	 * @return void
	 */
	public function testANonArraySerialisationFallsBackToGetObject(): void {
		$both = new class implements JsonSerializable {
			/**
			 * @return string
			 */
			public function jsonSerialize(): string {
				return 'text';
			}

			/**
			 * @return array<string, int>
			 */
			public function getObject(): array {
				return ['g' => 3];
			}
		};

		$this->assertSame(['g' => 3], self::host(trait: 'any')($both));
		$this->assertSame(['g' => 3], self::host(trait: 'casting')($both));
		$this->assertSame(['g' => 3], self::host(trait: 'optional')($both));
		$this->assertSame(['g' => 3], self::host(trait: 'payload')($both));
		// The serialised-only traits never look at getObject().
		$this->assertSame([], self::host(trait: 'serialised')($both));
	}//end testANonArraySerialisationFallsBackToGetObject()

	/**
	 * getObject() returning a non-array is a miss, reported per trait.
	 *
	 * @return void
	 */
	public function testANonArrayGetObjectIsAMiss(): void {
		$entity = self::entity(value: 'scalar');

		$this->assertNull(self::host(trait: 'optional')($entity));
		$this->assertSame([], self::host(trait: 'payload')($entity));
		// Casting and Any fall back to an (array) cast of the object itself.
		$this->assertSame((array) $entity, self::host(trait: 'casting')($entity));
		$this->assertSame((array) $entity, self::host(trait: 'any')($entity));
		$this->assertCount(1, self::host(trait: 'any')($entity));
	}//end testANonArrayGetObjectIsAMiss()

	/**
	 * A scalar is a miss: null for the nullable traits, [] or a cast for the others.
	 *
	 * @return void
	 */
	public function testAScalarIsAMiss(): void {
		$this->assertNull(self::host(trait: 'any')(42));
		$this->assertNull(self::host(trait: 'nullable')(42));
		$this->assertNull(self::host(trait: 'optional')(42));
		$this->assertSame([], self::host(trait: 'payload')(42));
		$this->assertSame([], self::host(trait: 'serialised')(42));
		$this->assertSame([42], self::host(trait: 'casting')(42));
		$this->assertSame([], self::host(trait: 'casting')(null));
	}//end testAScalarIsAMiss()

	/**
	 * A plain object becomes its public properties in the object-casting traits.
	 *
	 * @return void
	 */
	public function testAPlainObjectCastsToItsProperties(): void {
		$plain    = new stdClass();
		$plain->a = 1;

		$this->assertSame(['a' => 1], self::host(trait: 'any')($plain));
		$this->assertSame(['a' => 1], self::host(trait: 'nullable')($plain));
		$this->assertSame(['a' => 1], self::host(trait: 'casting')($plain));
		$this->assertNull(self::host(trait: 'optional')($plain));
		$this->assertSame([], self::host(trait: 'payload')($plain));
		$this->assertSame([], self::host(trait: 'serialised')($plain));
	}//end testAPlainObjectCastsToItsProperties()

	/**
	 * toArray() is preferred to a property cast, unless it returns a non-array.
	 *
	 * @return void
	 */
	public function testToArrayIsPreferredWhenItYieldsAnArray(): void {
		$this->assertSame(['t' => 1], self::host(trait: 'any')(self::arrayable(value: ['t' => 1])));
		$this->assertSame(['t' => 1], self::host(trait: 'nullable')(self::arrayable(value: ['t' => 1])));
		$refusing = self::arrayable(value: 'nope');
		$this->assertSame((array) $refusing, self::host(trait: 'any')($refusing));
		$this->assertSame((array) $refusing, self::host(trait: 'nullable')($refusing));
		$this->assertSame('p', self::host(trait: 'any')($refusing)['prop']);
	}//end testToArrayIsPreferredWhenItYieldsAnArray()

	/**
	 * A jsonSerialize() returning null is a miss, not an empty payload.
	 *
	 * @return void
	 */
	public function testANullSerialisationIsAMiss(): void {
		$value = self::serialisable(value: null);

		$this->assertSame([], self::host(trait: 'serialised')($value));
		$this->assertNull(self::host(trait: 'optional')($value));
		// Nullable treats it as a plain object and casts it instead.
		$this->assertSame((array) $value, self::host(trait: 'nullable')($value));
	}//end testANullSerialisationIsAMiss()
}//end class
