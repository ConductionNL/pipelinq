<?php

/**
 * Pipelinq ProductCatalogService.
 *
 * Server-authoritative resolution of POS product catalogue figures: BTW class
 * to tax rate mapping, effective unit price resolution across quantity price
 * tiers and per-variant overrides, variant SKU uniqueness validation, and
 * barcode lookup scoped to this app's own product schema.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/pos-product-catalogue/specs/pos-product-catalogue/spec.md#REQ-PPC-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use RuntimeException;
use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for POS product catalogue resolution.
 *
 * Every monetary and tax figure a POS or invoicing consumer needs is derived
 * here from the persisted product, never trusted from the client: the BTW class
 * authoritatively governs the tax rate, the effective unit price is resolved
 * from the stored price tiers / variant overrides for a requested quantity, and
 * barcode lookups are constrained to this app's own register + product schema so
 * a caller cannot read arbitrary objects (no IDOR).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Wires the small set of
 *  collaborators (OR container, app config, logger) a catalogue resolver
 *  legitimately needs.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The class aggregates the
 *  whole catalogue-resolution concern (BTW mapping + tier resolution + variant
 *  resolution + barcode lookup + OR persistence helpers) as many small,
 *  single-purpose methods; the cohesion is intentional and splitting it would
 *  scatter one concern across several classes without reducing real complexity.
 *
 * @spec openspec/changes/pos-product-catalogue/specs/pos-product-catalogue/spec.md#REQ-PPC-003
 */
class ProductCatalogService
{
    /**
     * Canonical Dutch BTW class to tax-rate mapping.
     *
     * @var array<string, int>
     */
    public const BTW_CLASS_RATES = [
        'hoog'        => 21,
        'laag'        => 9,
        'nul'         => 0,
        'vrijgesteld' => 0,
    ];

    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container.
     * @param IAppConfig         $appConfig The app config.
     * @param LoggerInterface    $logger    The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Map a Dutch BTW class to its authoritative tax rate.
     *
     * The class is the source of truth for the rate on a POS receipt /
     * invoice; an unknown or empty class falls back to the standard 21% high
     * rate so a missing class can never silently drop tax to zero.
     *
     * @param string|null $btwClass The BTW class (hoog/laag/nul/vrijgesteld).
     *
     * @return int The tax rate percentage.
     *
     * @spec openspec/changes/pos-product-catalogue/specs/pos-product-catalogue/spec.md#REQ-PPC-004
     */
    public function btwClassToRate(?string $btwClass): int
    {
        if ($this->isValidBtwClass(btwClass: $btwClass) === false) {
            return self::BTW_CLASS_RATES['hoog'];
        }

        return self::BTW_CLASS_RATES[$btwClass];
    }//end btwClassToRate()

    /**
     * Whether a value is one of the four valid BTW classes.
     *
     * @param string|null $btwClass The BTW class candidate.
     *
     * @return bool Whether the class is valid.
     *
     * @spec openspec/changes/pos-product-catalogue/specs/pos-product-catalogue/spec.md#REQ-PPC-004
     */
    public function isValidBtwClass(?string $btwClass): bool
    {
        return $btwClass !== null && isset(self::BTW_CLASS_RATES[$btwClass]) === true;
    }//end isValidBtwClass()

    /**
     * Sort a product's price tiers ascending by minQuantity.
     *
     * Defensive: invalid tiers (missing / non-positive minQuantity) are dropped
     * so a malformed stored tier cannot corrupt resolution.
     *
     * @param array<int, array<string, mixed>> $tiers The raw price tiers.
     *
     * @return array<int, array<string, mixed>> The cleaned, sorted tiers.
     *
     * @spec openspec/changes/pos-product-catalogue/specs/pos-product-catalogue/spec.md#REQ-PPC-003
     */
    public function sortPriceTiers(array $tiers): array
    {
        $clean = [];
        foreach ($tiers as $tier) {
            if (is_array($tier) === false) {
                continue;
            }

            $minQuantity = (int) ($tier['minQuantity'] ?? 0);
            if ($minQuantity < 1) {
                continue;
            }

            $tier['minQuantity'] = $minQuantity;
            $clean[] = $tier;
        }

        usort(
            $clean,
            static fn (array $a, array $b): int => ($a['minQuantity'] <=> $b['minQuantity'])
        );

        return $clean;
    }//end sortPriceTiers()

    /**
     * Resolve the price tier that applies for a given ordered quantity.
     *
     * Selects the tier with the highest minQuantity that is at most the
     * quantity. Returns null when no tier qualifies (caller falls back to the
     * base unitPrice).
     *
     * @param array<int, array<string, mixed>> $tiers    The price tiers.
     * @param float                            $quantity The ordered quantity.
     *
     * @return array<string, mixed>|null The applicable tier, or null.
     *
     * @spec openspec/changes/pos-product-catalogue/specs/pos-product-catalogue/spec.md#REQ-PPC-003
     */
    public function resolveTier(array $tiers, float $quantity): ?array
    {
        $sorted   = $this->sortPriceTiers(tiers: $tiers);
        $selected = null;

        foreach ($sorted as $tier) {
            if ($quantity >= (float) $tier['minQuantity']) {
                $selected = $tier;
                continue;
            }

            break;
        }

        return $selected;
    }//end resolveTier()

    /**
     * Resolve the server-authoritative effective unit price for a product.
     *
     * Resolution order:
     *   1. If a variant SKU is supplied and matches an active variant, its
     *      unitPrice override (when set) is the base.
     *   2. Otherwise the product's base unitPrice is the base.
     *   3. The applicable price tier (if any) overrides the base for the
     *      requested quantity.
     *
     * The returned figure is rounded to cents and never derived from
     * client-supplied price data.
     *
     * @param array<string, mixed> $product    The product object.
     * @param float                $quantity   The ordered quantity (clamped to >= 0).
     * @param string|null          $variantSku Optional variant SKU.
     *
     * @return array<string, mixed> The resolution: unitPrice, source
     *                              (base|tier|variant), tierLabel, btwClass,
     *                              taxRate.
     *
     * @spec openspec/changes/pos-product-catalogue/specs/pos-product-catalogue/spec.md#REQ-PPC-003
     */
    public function resolveEffectivePrice(array $product, float $quantity, ?string $variantSku=null): array
    {
        $quantity  = max(0.0, $quantity);
        $basePrice = max(0.0, (float) ($product['unitPrice'] ?? 0));
        $source    = 'base';
        $tierLabel = '';

        // Variant override (validates + may replace the base price).
        if ($variantSku !== null && $variantSku !== '') {
            [$basePrice, $source] = $this->resolveVariantPrice(
                product: $product,
                sku: $variantSku,
                basePrice: $basePrice
            );
        }

        // Quantity tier override (tiers price the line, not the variant).
        $tiers = (array) ($product['priceTiers'] ?? []);
        if (count($tiers) > 0 && $variantSku === null) {
            $tier = $this->resolveTier(tiers: $tiers, quantity: $quantity);
            if ($tier !== null) {
                $basePrice = max(0.0, (float) ($tier['unitPrice'] ?? $basePrice));
                $source    = 'tier';
                $tierLabel = (string) ($tier['label'] ?? '');
            }
        }

        $btwClass       = (string) ($product['btwClass'] ?? '');
        $btwClassOrNull = $btwClass;
        if ($btwClass === '') {
            $btwClassOrNull = null;
        }

        return [
            'unitPrice' => round($basePrice, 2),
            'source'    => $source,
            'tierLabel' => $tierLabel,
            'quantity'  => $quantity,
            'btwClass'  => $btwClass,
            'taxRate'   => $this->btwClassToRate(btwClass: $btwClassOrNull),
        ];
    }//end resolveEffectivePrice()

    /**
     * Resolve the base price + source for a requested variant.
     *
     * Validates that the variant exists and is active, then returns its price
     * override when set (otherwise the product base price is kept).
     *
     * @param array<string, mixed> $product   The product object.
     * @param string               $sku       The requested variant SKU.
     * @param float                $basePrice The product base price (fallback).
     *
     * @return array{0: float, 1: string} The [basePrice, source] tuple.
     *
     * @throws OCSNotFoundException   If the variant does not exist.
     * @throws OCSBadRequestException If the variant is inactive.
     *
     * @spec openspec/changes/pos-product-catalogue/specs/pos-product-catalogue/spec.md#REQ-PPC-001
     */
    private function resolveVariantPrice(array $product, string $sku, float $basePrice): array
    {
        $variant = $this->findVariant(product: $product, sku: $sku);
        if ($variant === null) {
            throw new OCSNotFoundException('Variant niet gevonden.');
        }

        if (($variant['status'] ?? 'active') === 'inactive') {
            throw new OCSBadRequestException('Variant is niet beschikbaar.');
        }

        if (isset($variant['unitPrice']) === true) {
            return [max(0.0, (float) $variant['unitPrice']), 'variant'];
        }

        return [$basePrice, 'base'];
    }//end resolveVariantPrice()

    /**
     * Find a variant on a product by its SKU.
     *
     * @param array<string, mixed> $product The product object.
     * @param string               $sku     The variant SKU.
     *
     * @return array<string, mixed>|null The variant, or null when not present.
     *
     * @spec openspec/changes/pos-product-catalogue/specs/pos-product-catalogue/spec.md#REQ-PPC-001
     */
    public function findVariant(array $product, string $sku): ?array
    {
        foreach (((array) ($product['variants'] ?? [])) as $variant) {
            if (is_array($variant) === true && (string) ($variant['sku'] ?? '') === $sku) {
                return $variant;
            }
        }

        return null;
    }//end findVariant()

    /**
     * Validate that all variant SKUs on a product are unique and non-empty.
     *
     * @param array<int, array<string, mixed>> $variants The variants to check.
     *
     * @return bool Whether every variant SKU is present and unique.
     *
     * @spec openspec/changes/pos-product-catalogue/specs/pos-product-catalogue/spec.md#REQ-PPC-001
     */
    public function variantSkusUnique(array $variants): bool
    {
        $seen = [];
        foreach ($variants as $variant) {
            $sku = trim((string) (($variant['sku'] ?? '')));
            if ($sku === '' || isset($seen[$sku]) === true) {
                return false;
            }

            $seen[$sku] = true;
        }

        return true;
    }//end variantSkusUnique()

    /**
     * Look up a product by barcode within this app's product schema.
     *
     * The barcode is matched against the product's own barcode and against each
     * variant barcode. The query is constrained to this app's configured
     * register + product schema, so a caller can never reach objects outside
     * the catalogue (no IDOR). Returns null when no product matches.
     *
     * @param string $barcode The scanned barcode.
     *
     * @return array<string, mixed>|null The matching product (with a resolved
     *                                   matchedVariantSku when a variant matched),
     *                                   or null.
     *
     * @throws OCSBadRequestException If the barcode is empty.
     *
     * @spec openspec/changes/pos-product-catalogue/specs/pos-product-catalogue/spec.md#REQ-PPC-005
     */
    public function lookupByBarcode(string $barcode): ?array
    {
        $barcode = trim($barcode);
        if ($barcode === '') {
            throw new OCSBadRequestException('Geef een barcode op.');
        }

        [$register, $schema] = $this->config();

        $products = $this->fetchProducts(register: $register, schema: $schema);

        foreach ($products as $product) {
            if ((string) ($product['barcode'] ?? '') === $barcode) {
                return $product;
            }

            foreach (((array) ($product['variants'] ?? [])) as $variant) {
                if (is_array($variant) === true && (string) ($variant['barcode'] ?? '') === $barcode) {
                    $product['matchedVariantSku'] = (string) ($variant['sku'] ?? '');
                    return $product;
                }
            }
        }

        return null;
    }//end lookupByBarcode()

    /**
     * Fetch all products from this app's register + product schema.
     *
     * @param string $register The register id.
     * @param string $schema   The product schema id.
     *
     * @return array<int, array<string, mixed>> The product objects.
     *
     * @spec openspec/changes/pos-product-catalogue/specs/pos-product-catalogue/spec.md#REQ-PPC-005
     */
    private function fetchProducts(string $register, string $schema): array
    {
        try {
            $results = $this->getObjectService()->findAll(
                config: [
                    'filters' => [
                        'register' => $register,
                        'schema'   => $schema,
                    ],
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Pipelinq: failed to fetch products for barcode lookup', ['exception' => $e->getMessage()]);
            return [];
        }

        $products = [];
        foreach (($results ?? []) as $result) {
            $products[] = $this->toArray(object: $result);
        }

        return $products;
    }//end fetchProducts()

    /**
     * Resolve the register + product schema into their stored IDs.
     *
     * @return array{0: string, 1: string} The [register, schema] IDs.
     *
     * @throws OCSNotFoundException If the register or schema is not configured.
     *
     * @spec openspec/changes/pos-product-catalogue/specs/pos-product-catalogue/spec.md#REQ-PPC-005
     */
    private function config(): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'product_schema', '');

        if ($register === '' || $schema === '') {
            throw new OCSNotFoundException('Productregister of -schema is niet geconfigureerd.');
        }

        return [$register, $schema];
    }//end config()

    /**
     * Get the OpenRegister ObjectService.
     *
     * @return object The object service.
     *
     * @throws RuntimeException If OpenRegister is not available.
     *
     * @spec openspec/changes/pos-product-catalogue/specs/pos-product-catalogue/spec.md#REQ-PPC-005
     */
    private function getObjectService(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            throw new RuntimeException('OpenRegister service is not available.');
        }
    }//end getObjectService()

    /**
     * Normalise an OR object (entity or array) into a plain array.
     *
     * @param mixed $object The OR object.
     *
     * @return array<string, mixed> The object as an array.
     */
    private function toArray(mixed $object): array
    {
        if (is_array($object) === true) {
            return $object;
        }

        if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
            $serialized = $object->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }
        }

        if (is_object($object) === true && method_exists($object, 'getObject') === true) {
            $data = $object->getObject();
            if (is_array($data) === true) {
                return $data;
            }
        }

        return (array) $object;
    }//end toArray()
}//end class
