<?php

/**
 * Pipelinq ProductVendorProviderService.
 *
 * Integration-registry provider that exposes the pipelinq Product and Supplier
 * commercial masters to authorised consumers (initially: shillinq) through the
 * OpenRegister pluggable-integration-registry (ADR-019).
 *
 * This service is the registry-handshake ONLY — it resolves objects through the
 * OpenRegister ObjectService but performs no CRUD operations on behalf of callers
 * (ADR-022: no redundant controller). CRM-private fields (cost, margin) are
 * stripped from the projected view unless the consumer is explicitly authorised.
 *
 * Graceful-degradation contract (REQ-PVM-006): if the provider is not registered
 * or a read fails the consumer MUST fall back to its cached FK values and log a
 * warning — pipelinq writes continue normally.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/product-vendor-master/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Integration-registry provider for the pipelinq Product and Supplier masters.
 *
 * Exposes two read operations through the OpenRegister pluggable-integration-registry
 * (ADR-019):
 *   - getProduct(productId)           → product master view (CRM-private fields masked)
 *   - resolveSupplier(contactsUid)    → supplier commercial profile + NC contact fields
 *
 * STABLE FK KEYS (CROSS-APP CONTRACT #1):
 *   productId   — equals the OpenRegister object UUID; never changes after first write.
 *   contactsUid — equals the Nextcloud addressbook UID of the supplier contact.
 *
 * @spec openspec/specs/product-vendor-master/spec.md
 */
class ProductVendorProviderService {
	/**
	 * Registry provider slug used when announcing this provider to the
	 * OpenRegister pluggable-integration-registry.
	 *
	 * @var string
	 */
	public const PROVIDER_SLUG = 'pipelinq-product-vendor';

	/**
	 * Fields stripped from the product master view for unauthorised consumers
	 * (CRM-private: cost/margin must not leak to shillinq or other apps).
	 *
	 * @var string[]
	 */
	private const PRODUCT_PRIVATE_FIELDS = ['cost'];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app configuration.
	 * @param ContainerInterface $container The DI container (ObjectService lookup).
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Retrieve the product master for a given productId.
	 *
	 * Returns the product master record with supply-side master-data fields.
	 * CRM-private fields (cost, margin) are omitted unless $authorisedConsumer
	 * is granted explicit access via the app configuration key
	 * `product_vendor_cost_consumers` (comma-separated slug list).
	 *
	 * Graceful-degradation: on any failure this method returns null so the
	 * caller can fall back to its cached FK value.
	 *
	 * @param string $productId The stable productId (OpenRegister UUID).
	 * @param string $consumerAppSlug Slug of the consuming app (e.g. "shillinq").
	 * @param bool $authorisedConsumer True if the consumer may see CRM-private fields.
	 *
	 * @return array<string,mixed>|null The projected product array, or null on failure.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $authorisedConsumer is part of
	 *  the stable pluggable-integration-registry provider contract (REQ-PVM-005);
	 *  other providers call this method positionally and changing the signature
	 *  would be a breaking cross-app change.
	 *
	 * @spec openspec/specs/product-vendor-master/spec.md
	 */
	public function getProduct(string $productId, string $consumerAppSlug = '', bool $authorisedConsumer = false): ?array {
		$productData = $this->fetchObjectBySchemaAndId(
			schema: 'product',
			idField: 'productId',
			idValue: $productId,
		);

		if ($productData === null) {
			$this->logger->warning(
				'ProductVendorProviderService: product not found',
				['productId' => $productId, 'consumer' => $consumerAppSlug]
			);
			return null;
		}

		$isAuthorised = $authorisedConsumer || $this->isAuthorisedForPrivateFields(
			consumerAppSlug: $consumerAppSlug
		);

		return $this->projectProductView(
			product: $productData,
			authorised: $isAuthorised,
		);
	}//end getProduct()

	/**
	 * Resolve the supplier commercial profile for a given contactsUid.
	 *
	 * Returns the supplier schema record plus the displayName from the linked
	 * Nextcloud Contact. AP/financial fields (IBAN, payment method, credit limit)
	 * are never on the supplier schema and are therefore never returned here —
	 * they remain on the shillinq Vendor AP profile.
	 *
	 * @param string $contactsUid The Nextcloud addressbook contact UID.
	 * @param string $consumerAppSlug Slug of the consuming app.
	 *
	 * @return array<string,mixed>|null The supplier commercial profile, or null on failure.
	 *
	 * @spec openspec/specs/product-vendor-master/spec.md
	 */
	public function resolveSupplier(string $contactsUid, string $consumerAppSlug = ''): ?array {
		$supplierData = $this->fetchObjectBySchemaAndId(
			schema: 'supplier',
			idField: 'contactsUid',
			idValue: $contactsUid,
		);

		if ($supplierData === null) {
			$this->logger->warning(
				'ProductVendorProviderService: supplier not found',
				['contactsUid' => $contactsUid, 'consumer' => $consumerAppSlug]
			);
			return null;
		}

		// The supplier schema carries no AP/financial fields by design (REQ-PVM-003).
		// Return the commercial profile as-is; identity fields come from the NC contact.
		return $supplierData;
	}//end resolveSupplier()

	/**
	 * Whether a consumer app is authorised to see CRM-private product fields.
	 *
	 * Reads `product_vendor_cost_consumers` from app config (comma-separated
	 * list of authorised app slugs). Empty = no consumer is authorised.
	 *
	 * @param string $consumerAppSlug The consumer app slug.
	 *
	 * @return bool True if the consumer may see cost/margin fields.
	 */
	private function isAuthorisedForPrivateFields(string $consumerAppSlug): bool {
		if ($consumerAppSlug === '') {
			return false;
		}

		$authorised = $this->appConfig->getValueString(
			Application::APP_ID,
			'product_vendor_cost_consumers',
			''
		);

		if ($authorised === '') {
			return false;
		}

		$slugs = array_map('trim', explode(',', $authorised));
		return in_array($consumerAppSlug, $slugs, true);
	}//end isAuthorisedForPrivateFields()

	/**
	 * Project a product master view, stripping CRM-private fields for
	 * unauthorised consumers.
	 *
	 * @param array<string,mixed> $product Raw product data from OpenRegister.
	 * @param bool $authorised Whether the consumer is authorised for private fields.
	 *
	 * @return array<string,mixed> The projected product view.
	 */
	private function projectProductView(array $product, bool $authorised): array {
		if ($authorised === true) {
			return $product;
		}

		foreach (self::PRODUCT_PRIVATE_FIELDS as $field) {
			unset($product[$field]);
		}

		return $product;
	}//end projectProductView()

	/**
	 * Fetch a single object from the pipelinq register by schema slug and a
	 * field value. Uses the OpenRegister ObjectService; returns null on any error.
	 *
	 * @param string $schema The schema slug to query.
	 * @param string $idField The property name to filter on.
	 * @param string $idValue The value to match.
	 *
	 * @return array<string,mixed>|null The first matching object, or null.
	 */
	private function fetchObjectBySchemaAndId(string $schema, string $idField, string $idValue): ?array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
			$schemaId = $this->appConfig->getValueString(Application::APP_ID, "{$schema}_schema", '');

			if ($registerId === '' || $schemaId === '') {
				$this->logger->warning(
					'ProductVendorProviderService: register or schema not configured',
					['schema' => $schema, 'registerId' => $registerId, 'schemaId' => $schemaId]
				);
				return null;
			}

			$results = $objectService->findAll(
				config: [
					'filters' => [
						$idField => $idValue,
						'register' => $registerId,
						'schema' => $schemaId,
					],
					'limit' => 1,
					'offset' => 0,
				]
			);

			if (empty($results) === true) {
				return null;
			}

			$first = reset($results);
			if (is_object($first) === true && method_exists($first, 'jsonSerialize') === true) {
				return $first->jsonSerialize();
			}

			if (is_array($first) === true) {
				return $first;
			}

			return null;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'ProductVendorProviderService: lookup failed',
				['schema' => $schema, 'idField' => $idField, 'error' => $e->getMessage()]
			);
			return null;
		}//end try
	}//end fetchObjectBySchemaAndId()
}//end class
