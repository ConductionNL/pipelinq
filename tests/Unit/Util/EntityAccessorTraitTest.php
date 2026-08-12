<?php

/**
 * Unit tests for EntityAccessorTrait.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Util
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

namespace OCA\Pipelinq\Tests\Unit\Util;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Util\EntityAccessorTrait;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the magic-accessor reader, and for the stub parity it depends on.
 *
 * @see lib/Util/EntityAccessorTrait.php
 */
class EntityAccessorTraitTest extends TestCase {

	/**
	 * Host exposing the trait's private reader.
	 *
	 * @var object
	 */
	private object $host;

	/**
	 * Build the trait host.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->host = new class {
			use EntityAccessorTrait;

			/**
			 * Expose the private reader for the test.
			 *
			 * @param object|null $entity The entity.
			 * @param string $getter The accessor name.
			 *
			 * @return string The value.
			 */
			public function read(?object $entity, string $getter): string {
				return $this->readEntityValue(entity: $entity, getter: $getter);
			}
		};
	}//end setUp()

	/**
	 * The parity control this whole fix rests on.
	 *
	 * `getObject()` and `jsonSerialize()` are DECLARED on the production entity,
	 * so `method_exists()` must be true for them — that is the positive control
	 * proving the probe itself works. `getSchema()` / `getUuid()` are served by
	 * `Entity::__call`, so `method_exists()` must be FALSE while the accessor
	 * still resolves. If this test ever goes red, the test double has drifted
	 * away from production and every listener guard below it is unmeasured.
	 *
	 * @return void
	 */
	public function testTheDoubleReproducesProductionsMagicAccessorShape(): void {
		$entity = new ObjectEntity();

		// Positive controls: really declared, so the probe is live.
		$this->assertTrue(method_exists($entity, 'getObject'));
		$this->assertTrue(method_exists($entity, 'jsonSerialize'));

		// The defect: declared only as @method, served through __call.
		$this->assertFalse(method_exists($entity, 'getSchema'));
		$this->assertFalse(method_exists($entity, 'getUuid'));

		// And why is_callable() is not the fix — it is true for a name the
		// entity has never heard of, so it cannot decide membership.
		$this->assertTrue(is_callable([$entity, 'getSchema']));
		$this->assertTrue(is_callable([$entity, 'getThisAccessorDoesNotExist']));

		// Property_exists() is what Entity::getter() itself consults, so the
		// double must BACK every magic accessor with a real property. A stub
		// that declares the method but omits the property inverts the second
		// probe as well — softwarecatalog shipped exactly that and applying the
		// fix turned four green tests red. Measured on the live class: uuid,
		// schema, register and object all exist.
		foreach (['uuid', 'schema', 'register', 'object'] as $property) {
			$this->assertTrue(property_exists($entity, $property), "missing backing property \${$property}");
		}

		$this->assertFalse(property_exists($entity, 'thisAccessorDoesNotExist'));
	}//end testTheDoubleReproducesProductionsMagicAccessorShape()

	/**
	 * A magic accessor's value is returned as a string.
	 *
	 * @return void
	 */
	public function testReadsAValueThroughAMagicAccessor(): void {
		$entity = new ObjectEntity();
		$entity->setSchema('schema-expense');
		$entity->setUuid('exp-1');

		$this->assertSame('schema-expense', $this->host->read($entity, 'getSchema'));
		$this->assertSame('exp-1', $this->host->read($entity, 'getUuid'));
	}//end testReadsAValueThroughAMagicAccessor()

	/**
	 * An accessor the entity does not back with a property yields '' rather
	 * than the BadFunctionCallException `Entity::getter()` raises.
	 *
	 * @return void
	 */
	public function testAnUnknownAccessorYieldsAnEmptyStringInsteadOfThrowing(): void {
		$this->assertSame('', $this->host->read(new ObjectEntity(), 'getThisAccessorDoesNotExist'));
	}//end testAnUnknownAccessorYieldsAnEmptyStringInsteadOfThrowing()

	/**
	 * An unset accessor and a null entity both read as absent.
	 *
	 * @return void
	 */
	public function testUnsetValuesAndANullEntityReadAsAbsent(): void {
		$this->assertSame('', $this->host->read(null, 'getSchema'));
		$this->assertSame('', $this->host->read(new ObjectEntity(), 'getSchema'));
	}//end testUnsetValuesAndANullEntityReadAsAbsent()

	/**
	 * A non-scalar value is treated as absent — a caller asking for a schema id
	 * must never receive an array it will silently stringify.
	 *
	 * @return void
	 */
	public function testANonScalarValueIsTreatedAsAbsent(): void {
		$entity = new ObjectEntity();
		$entity->setObject(['a' => 1]);

		$this->assertSame('', $this->host->read($entity, 'getObject'));
	}//end testANonScalarValueIsTreatedAsAbsent()

}//end class
