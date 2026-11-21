<?php

/**
 * Tests for ProductVariantGuard.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/specify-capability-rows/tasks.md#task-29.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Pipelinq\Kernel\Contracts\SchemaMapServiceInterface;
use OCA\Pipelinq\Listener\ProductVariantGuard;
use OCA\Pipelinq\Service\Product\VariantCombinations;
use OCP\EventDispatcher\Event;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * A product save that repeats a combination or a code is refused, naming it; nothing else is.
 *
 * @covers \OCA\Pipelinq\Listener\ProductVariantGuard
 * @uses   \OCA\Pipelinq\Service\Product\VariantCombinations
 *
 * @spec openspec/changes/specify-capability-rows/specs/product-catalog/spec.md#requirement-a-product-keeps-attributes-and-variants-with-their-own-code-and-price
 */
class ProductVariantGuardTest extends TestCase {
	/**
	 * The guard, with `s-product` as the product schema.
	 *
	 * @return ProductVariantGuard The guard.
	 */
	private function guard(): ProductVariantGuard {
		$schemaMap = $this->createMock(originalClassName: SchemaMapServiceInterface::class);
		$schemaMap->method('resolveEntityType')->willReturnCallback(
			static fn (?string $schemaId): ?string => ['s-product' => 'product', 's-lead' => 'lead'][(string)$schemaId] ?? null
		);
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $args = []): string => vsprintf($text, $args));

		return new ProductVariantGuard(schemaMap: $schemaMap, combinations: new VariantCombinations(), l10n: $l10n);
	}//end guard()

	/**
	 * An object entity.
	 *
	 * @param string $schema The schema id.
	 * @param array<string, mixed> $data The body.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(string $schema, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('p-1');
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * The shirt with S and M is stored as it is.
	 *
	 * @return void
	 */
	public function testTwoSizesAreStored(): void {
		$event = new ObjectCreatingEvent(
			$this->entity(
				schema: 's-product',
				data: [
					'name' => 'Werkshirt',
					'unitPrice' => 25,
					'variants' => [
						['sku' => 'WS-S', 'attributes' => ['Maat' => 'S']],
						['sku' => 'WS-M', 'attributes' => ['Maat' => 'M'], 'unitPrice' => 27.5],
					],
				]
			)
		);

		$this->guard()->handle(event: $event);

		$this->assertFalse(condition: $event->isPropagationStopped());
	}//end testTwoSizesAreStored()

	/**
	 * Adding a second S to the shirt is refused with "Maat S" named.
	 *
	 * @return void
	 */
	public function testASecondSizeSIsRefusedNamingIt(): void {
		$old = $this->entity(schema: 's-product', data: ['variants' => [['sku' => 'WS-S', 'attributes' => ['Maat' => 'S']]]]);
		$new = $this->entity(
			schema: 's-product',
			data: ['variants' => [['sku' => 'WS-S', 'attributes' => ['Maat' => 'S']], ['sku' => 'WS-S2', 'attributes' => ['Maat' => 'S']]]]
		);
		$event = new ObjectUpdatingEvent($new, $old);

		$this->guard()->handle(event: $event);

		$this->assertTrue(condition: $event->isPropagationStopped());
		$this->assertSame(expected: 'A variant for Maat S already exists on this product', actual: $event->getErrors()['message']);
	}//end testASecondSizeSIsRefusedNamingIt()

	/**
	 * Two variants with one code are refused with the code named.
	 *
	 * @return void
	 */
	public function testTheSameCodeTwiceIsRefused(): void {
		$event = new ObjectCreatingEvent(
			$this->entity(
				schema: 's-product',
				data: ['variants' => [['sku' => 'WS-S', 'attributes' => ['Maat' => 'S']], ['sku' => 'WS-S', 'attributes' => ['Maat' => 'M']]]]
			)
		);

		$this->guard()->handle(event: $event);

		$this->assertTrue(condition: $event->isPropagationStopped());
		$this->assertSame(expected: 'Two variants of this product have the code WS-S', actual: $event->getErrors()['message']);
	}//end testTheSameCodeTwiceIsRefused()

	/**
	 * A shirt that declares Maat (S, M) refuses a variant in L, and one without a size.
	 *
	 * @return void
	 */
	public function testAVariantOutsideTheDeclaredValuesIsRefused(): void {
		$declared = [['name' => 'Maat', 'values' => ['S', 'M']]];

		$large = new ObjectCreatingEvent(
			$this->entity(schema: 's-product', data: ['variantAttributes' => $declared, 'variants' => [['sku' => 'WS-L', 'attributes' => ['Maat' => 'L']]]])
		);
		$this->guard()->handle(event: $large);
		$this->assertTrue(condition: $large->isPropagationStopped());
		$this->assertSame(expected: 'L is not a value of Maat on this product', actual: $large->getErrors()['message']);

		$sizeless = new ObjectCreatingEvent(
			$this->entity(schema: 's-product', data: ['variantAttributes' => $declared, 'variants' => [['sku' => 'WS-X']]])
		);
		$this->guard()->handle(event: $sizeless);
		$this->assertSame(expected: 'Variant WS-X has no value for Maat', actual: $sizeless->getErrors()['message']);

		$coloured = new ObjectCreatingEvent(
			$this->entity(schema: 's-product', data: ['variantAttributes' => $declared, 'variants' => [['sku' => 'WS-S', 'attributes' => ['Maat' => 'S', 'Kleur' => 'Wit']]]])
		);
		$this->guard()->handle(event: $coloured);
		$this->assertSame(expected: 'Kleur is not an attribute of this product', actual: $coloured->getErrors()['message']);
	}//end testAVariantOutsideTheDeclaredValuesIsRefused()

	/**
	 * Another object type, a product without variants and an unrelated event all pass.
	 *
	 * @return void
	 */
	public function testAnythingElsePasses(): void {
		$twice = ['variants' => [['attributes' => ['Maat' => 'S']], ['attributes' => ['Maat' => 'S']]]];

		$lead = new ObjectCreatingEvent($this->entity(schema: 's-lead', data: $twice));
		$this->guard()->handle(event: $lead);
		$this->assertFalse(condition: $lead->isPropagationStopped());

		$plain = new ObjectCreatingEvent($this->entity(schema: 's-product', data: ['name' => 'Advies', 'variants' => 'none']));
		$this->guard()->handle(event: $plain);
		$this->assertFalse(condition: $plain->isPropagationStopped());

		$this->guard()->handle(event: new Event());
		$this->addToAssertionCount(count: 1);
	}//end testAnythingElsePasses()
}//end class
