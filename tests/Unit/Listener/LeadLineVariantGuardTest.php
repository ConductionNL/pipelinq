<?php

/**
 * Tests for LeadLineVariantGuard.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/specify-capability-rows/tasks.md#29.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Pipelinq\Kernel\Contracts\CallerObjectReaderInterface;
use OCA\Pipelinq\Listener\LeadLineVariantGuard;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * A lead line that names a variant code its product lacks is refused; a known
 * code, no code, another schema and an unreadable product pass.
 *
 * @covers \OCA\Pipelinq\Listener\LeadLineVariantGuard
 *
 * @spec openspec/changes/specify-capability-rows/specs/product-catalog/spec.md#requirement-a-product-keeps-attributes-and-variants-with-their-own-code-and-price
 */
class LeadLineVariantGuardTest extends TestCase {
	/**
	 * The shirt in S and M.
	 */
	private const SHIRT = [
		'id'        => 'p-shirt',
		'name'      => 'Werkshirt',
		'unitPrice' => 25,
		'variants'  => [
			['sku' => 'WS-S', 'attributes' => ['Maat' => 'S']],
			['sku' => 'WS-M', 'attributes' => ['Maat' => 'M'], 'unitPrice' => 27.5],
		],
	];

	/**
	 * The products the caller may read, by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $products = ['p-shirt' => self::SHIRT, 'p-plain' => ['id' => 'p-plain', 'name' => 'Koffie']];

	/**
	 * The guard, with `lp` as the lead line schema.
	 *
	 * @return LeadLineVariantGuard The guard.
	 */
	private function guard(): LeadLineVariantGuard {
		$reader = $this->createMock(originalClassName: CallerObjectReaderInterface::class);
		$reader->method('read')->willReturnCallback(
			fn (string $id, string $schemaKey): ?array => $schemaKey === 'product_schema' ? ($this->products[$id] ?? null) : null
		);
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key): string => $key === 'leadProduct_schema' ? 'lp' : ''
		);
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $args = []): string => vsprintf($text, $args));

		return new LeadLineVariantGuard(reader: $reader, appConfig: $appConfig, l10n: $l10n);
	}//end guard()

	/**
	 * An object entity.
	 *
	 * @param array<string, mixed> $data   The body.
	 * @param string               $schema The schema id.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function line(array $data, string $schema = 'lp'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end line()

	/**
	 * A line for the shirt in M is stored.
	 *
	 * @return void
	 */
	public function testAKnownVariantCodeIsStored(): void {
		$event = new ObjectCreatingEvent($this->line(data: ['lead' => 'l-1', 'product' => 'p-shirt', 'quantity' => 2, 'variantSku' => 'WS-M']));

		$this->guard()->handle(event: $event);

		$this->assertFalse(condition: $event->isPropagationStopped());
		$this->assertSame(expected: [], actual: $event->getErrors());
	}//end testAKnownVariantCodeIsStored()

	/**
	 * A line for the shirt in L is refused with the code named, before it is priced.
	 *
	 * @return void
	 */
	public function testAnUnknownVariantCodeIsRefusedNamingIt(): void {
		$event = new ObjectCreatingEvent($this->line(data: ['lead' => 'l-1', 'product' => 'p-shirt', 'variantSku' => 'WS-L']));

		$this->guard()->handle(event: $event);

		$this->assertTrue(condition: $event->isPropagationStopped());
		$this->assertSame(expected: 'This product has no variant with the code WS-L', actual: $event->getErrors()['message']);
	}//end testAnUnknownVariantCodeIsRefusedNamingIt()

	/**
	 * An edit that changes the code to one the product lacks is refused; the
	 * code comes from the modified data, the product from the stored line.
	 *
	 * @return void
	 */
	public function testAnEditToAnUnknownCodeIsRefused(): void {
		$old   = $this->line(data: ['lead' => 'l-1', 'product' => 'p-shirt', 'variantSku' => 'WS-S']);
		$new   = $this->line(data: ['lead' => 'l-1', 'product' => 'p-shirt', 'variantSku' => 'WS-S']);
		$event = new ObjectUpdatingEvent($new, $old);
		$event->setModifiedData(['variantSku' => ' WS-XL ']);

		$this->guard()->handle(event: $event);

		$this->assertTrue(condition: $event->isPropagationStopped());
		$this->assertSame(expected: 'This product has no variant with the code WS-XL', actual: $event->getErrors()['message']);
	}//end testAnEditToAnUnknownCodeIsRefused()

	/**
	 * A code on a product without variants is refused too.
	 *
	 * @return void
	 */
	public function testACodeOnAProductWithoutVariantsIsRefused(): void {
		$event = new ObjectCreatingEvent($this->line(data: ['product' => 'p-plain', 'variantSku' => 'K-1']));

		$this->guard()->handle(event: $event);

		$this->assertTrue(condition: $event->isPropagationStopped());
	}//end testACodeOnAProductWithoutVariantsIsRefused()

	/**
	 * A line without a code, an empty code, a line of another schema, a
	 * product the caller cannot read and an event after the write all pass.
	 *
	 * @return void
	 */
	public function testEverythingElsePasses(): void {
		$events = [
			new ObjectCreatingEvent($this->line(data: ['product' => 'p-shirt', 'quantity' => 1])),
			new ObjectCreatingEvent($this->line(data: ['product' => 'p-shirt', 'variantSku' => '  '])),
			new ObjectCreatingEvent($this->line(data: ['product' => 'p-shirt', 'variantSku' => 'WS-L'], schema: 'salesQuoteLine')),
			new ObjectCreatingEvent($this->line(data: ['product' => 'p-hidden', 'variantSku' => 'WS-L'])),
			new ObjectCreatingEvent($this->line(data: ['variantSku' => 'WS-L'])),
			new ObjectCreatedEvent($this->line(data: ['product' => 'p-shirt', 'variantSku' => 'WS-L'])),
		];

		foreach ($events as $index => $event) {
			$this->guard()->handle(event: $event);
			$this->assertFalse(condition: $event->isPropagationStopped(), message: 'event '.$index);
		}
	}//end testEverythingElsePasses()
}//end class
