<?php

/**
 * Pipelinq LeadLineVariantGuard.
 *
 * Refuses a lead line that names a variant code its product does not have.
 *
 * @category Listener
 * @package  OCA\Pipelinq\Listener
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/specify-capability-rows/tasks.md#29.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\CallerObjectReaderInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use OCP\IL10N;

/**
 * The server-side variant check of a lead line.
 *
 * A lead line may name one of its product's variants by code (`variantSku`),
 * which the price resolver then prices. A code the product does not carry
 * would silently price the product itself, so the write is refused and
 * nothing is stored. A line without a code, a line of another schema and a
 * line whose product the caller cannot read pass untouched.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/specify-capability-rows/specs/product-catalog/spec.md#requirement-a-product-keeps-attributes-and-variants-with-their-own-code-and-price
 */
class LeadLineVariantGuard implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param CallerObjectReaderInterface $reader    Reads the product with the caller's rights.
	 * @param IAppConfig                  $appConfig Holds the lead line schema id.
	 * @param IL10N                       $l10n      Words the refusal in the user's language.
	 *
	 * @spec openspec/changes/specify-capability-rows/tasks.md#29.1
	 */
	public function __construct(
		private readonly CallerObjectReaderInterface $reader,
		private readonly IAppConfig $appConfig,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Check a lead line create or update and refuse an unknown variant code.
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
	 * Refuse the write when it is a lead line whose variant code its product lacks.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event  The event to stop.
	 * @param ObjectEntity                            $entity The line as it will be saved.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/specify-capability-rows/tasks.md#29.1
	 */
	private function judge(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $entity): void {
		$schema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'leadProduct_schema', '');
		if ($schema === '' || (string)$entity->getSchema() !== $schema) {
			return;
		}

		$line = array_merge((array)$entity->getObject(), (array)$event->getModifiedData());
		$code = self::text(value: $line['variantSku'] ?? null);
		$product = self::text(value: $line['product'] ?? null);
		if ($code === '' || $product === '') {
			return;
		}

		$stored = $this->reader->read(id: $product, schemaKey: 'product_schema');
		if ($stored === null || self::hasVariant(product: $stored, code: $code) === true) {
			return;
		}

		$event->setErrors(['message' => $this->l10n->t('This product has no variant with the code %s', [$code])]);
		$event->stopPropagation();
	}//end judge()

	/**
	 * Whether the product carries a variant with this code.
	 *
	 * @param array<string, mixed> $product The product.
	 * @param string               $code    The variant code.
	 *
	 * @return bool True when one of its variants has the code.
	 *
	 * @spec openspec/changes/specify-capability-rows/tasks.md#29.1
	 */
	private static function hasVariant(array $product, string $code): bool {
		$variants = $product['variants'] ?? [];
		if (is_array($variants) === false) {
			return false;
		}

		foreach ($variants as $variant) {
			if (is_array($variant) === true && self::text(value: $variant['sku'] ?? null) === $code) {
				return true;
			}
		}

		return false;
	}//end hasVariant()

	/**
	 * A scalar as trimmed text, '' for anything else.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The text.
	 *
	 * @spec openspec/changes/specify-capability-rows/tasks.md#29.1
	 */
	private static function text(mixed $value): string {
		if (is_scalar($value) === false) {
			return '';
		}

		return trim((string)$value);
	}//end text()
}//end class
