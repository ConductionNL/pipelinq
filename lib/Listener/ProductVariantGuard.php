<?php

/**
 * Pipelinq ProductVariantGuard.
 *
 * Refuses a product save that would give two variants the same combination
 * of attribute values or the same code, whatever screen the write came from.
 *
 * @category Listener
 * @package  OCA\Pipelinq\Listener
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/specify-capability-rows/tasks.md#task-29.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Pipelinq\Kernel\Contracts\SchemaMapServiceInterface;
use OCA\Pipelinq\Service\Product\VariantCombinations;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IL10N;

/**
 * The server-side variant check of a product.
 *
 * Runs on every create and update of a `product`; any other object passes
 * untouched. The refusal names the combination or the code, and nothing is
 * stored.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/specify-capability-rows/specs/product-catalog/spec.md#requirement-a-product-keeps-attributes-and-variants-with-their-own-code-and-price
 */
class ProductVariantGuard implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param SchemaMapServiceInterface $schemaMap Resolves a schema id to its object type.
	 * @param VariantCombinations $combinations Finds a combination or code two variants share.
	 * @param IL10N $l10n Words the refusal in the user's language.
	 */
	public function __construct(
		private SchemaMapServiceInterface $schemaMap,
		private VariantCombinations $combinations,
		private IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Check a product create or update and refuse it when two variants clash.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/specify-capability-rows/specs/product-catalog/spec.md#requirement-a-product-keeps-attributes-and-variants-with-their-own-code-and-price
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent) {
			$this->judge(event: $event, entity: $event->getObject());
			return;
		}

		if ($event instanceof ObjectUpdatingEvent) {
			$this->judge(event: $event, entity: $event->getNewObject());
		}
	}//end handle()

	/**
	 * Refuse the write when it is a product whose variants clash.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event  The event to stop.
	 * @param \OCA\OpenRegister\Db\ObjectEntity       $entity The object as it will be saved.
	 *
	 * @return void
	 */
	private function judge(ObjectCreatingEvent|ObjectUpdatingEvent $event, \OCA\OpenRegister\Db\ObjectEntity $entity): void {
		if ($this->schemaMap->resolveEntityType(schemaId: (string)$entity->getSchema()) !== 'product') {
			return;
		}

		$data = array_merge((array)$entity->getObject(), (array)$event->getModifiedData());
		$refusal = $this->refusal(variants: ($data['variants'] ?? []), declared: ($data['variantAttributes'] ?? []));
		if ($refusal !== null) {
			$event->setErrors(['message' => $refusal]);
			$event->stopPropagation();
		}
	}//end judge()

	/**
	 * Why the variants cannot be stored, or null when they can.
	 *
	 * @param mixed $variants The product's variants as they will be saved.
	 * @param mixed $declared The product's attributes and their allowed values.
	 *
	 * @return string|null The refusal.
	 */
	private function refusal(mixed $variants, mixed $declared): ?string {
		if (is_array($variants) === false) {
			return null;
		}

		$variants = array_values($variants);
		$combination = $this->combinations->duplicateCombination(variants: $variants);
		if ($combination !== null) {
			return $this->l10n->t('A variant for %s already exists on this product', [$combination]);
		}

		$sku = $this->combinations->duplicateSku(variants: $variants);
		if ($sku !== null) {
			return $this->l10n->t('Two variants of this product have the code %s', [$sku]);
		}

		return $this->attributeRefusal(mismatch: $this->combinations->attributeMismatch(declared: $declared, variants: $variants));
	}//end refusal()

	/**
	 * The refusal for a variant that does not fit the product's attributes, or null.
	 *
	 * @param array{0: string, 1: array<int, string>}|null $mismatch The kind and its words.
	 *
	 * @return string|null The refusal.
	 */
	private function attributeRefusal(?array $mismatch): ?string {
		if ($mismatch === null) {
			return null;
		}

		[$kind, $words] = $mismatch;
		if ($kind === 'missing') {
			return $this->l10n->t('Variant %1$s has no value for %2$s', $words);
		}

		if ($kind === 'value') {
			return $this->l10n->t('%1$s is not a value of %2$s on this product', $words);
		}

		return $this->l10n->t('%s is not an attribute of this product', $words);
	}//end attributeRefusal()
}//end class
