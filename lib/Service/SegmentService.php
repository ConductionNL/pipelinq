<?php

/**
 * Pipelinq SegmentService.
 *
 * Rule-tree validator and evaluator that makes a Segment a live query, not
 * a frozen list. Validates and evaluates AND/OR rule trees against the
 * configured entity schema (contact or customer), estimates membership
 * size with TTL caching, and projects the per-recipient send list used by
 * the blast engine (member 04).
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
 * @spec openspec/changes/marketing-segmentation-and-blast-02-segment-service/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * SegmentService — validates and evaluates Segment rule trees.
 *
 * @spec openspec/changes/marketing-segmentation-and-blast-02-segment-service/tasks.md#task-2.1
 */
class SegmentService
{
    /**
     * Default contact schema slug used when no `contact_schema` app
     * config value is set. Matches the seed register slug.
     */
    private const DEFAULT_CONTACT_SCHEMA_SLUG = 'contact';

    /**
     * Default customer schema slug used when no `customer_schema` /
     * `client_schema` app config value is set. Pipelinq historically
     * uses `client` as the customer slug.
     */
    private const DEFAULT_CUSTOMER_SCHEMA_SLUG = 'client';

    /**
     * Operator → field-type compatibility matrix. Each key is the leaf
     * predicate's operator; the value is the set of JSON-schema types
     * the operator may legally be applied to.
     *
     * The operator list is the union of the matrix documented in the
     * member-02 design (equals/gt/gte/lt/lte/contains/in/between) PLUS
     * the operators present in the seed Segment rule trees (notIn,
     * before, after, containsAny, greaterThan, lessThan). Treating these
     * as first-class avoids forcing slice 01's seed data through a
     * rename pass.
     *
     * @var array<string, array<int, string>>
     */
    private const OPERATOR_TYPE_MATRIX = [
        'equals'             => ['string', 'integer', 'number', 'boolean', 'array'],
        'notEquals'          => ['string', 'integer', 'number', 'boolean', 'array'],
        'gt'                 => ['integer', 'number', 'string'],
        'gte'                => ['integer', 'number', 'string'],
        'lt'                 => ['integer', 'number', 'string'],
        'lte'                => ['integer', 'number', 'string'],
        'greaterThan'        => ['integer', 'number', 'string'],
        'greaterThanOrEqual' => ['integer', 'number', 'string'],
        'lessThan'           => ['integer', 'number', 'string'],
        'lessThanOrEqual'    => ['integer', 'number', 'string'],
        'before'             => ['string'],
        'after'              => ['string'],
        'between'            => ['integer', 'number', 'string'],
        'contains'           => ['string', 'array'],
        'containsAny'        => ['string', 'array'],
        'in'                 => ['string', 'integer', 'number', 'boolean'],
        'notIn'              => ['string', 'integer', 'number', 'boolean'],
        'isNull'             => ['string', 'integer', 'number', 'boolean', 'array'],
        'isNotNull'          => ['string', 'integer', 'number', 'boolean', 'array'],
    ];

    /**
     * Constructor.
     *
     * @param ContainerInterface $container        DI container.
     * @param IAppConfig         $appConfig        Pipelinq app config.
     * @param SchemaMapService   $schemaMapService Schema-slug map.
     * @param ICacheFactory      $cacheFactory     NC cache factory.
     * @param LoggerInterface    $logger           Logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private SchemaMapService $schemaMapService,
        private ICacheFactory $cacheFactory,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Validate a rule tree against the schema for the given entity type.
     *
     * Walks the tree recursively. Composite nodes (AND/OR/NOT) must
     * declare a `children` array; each child is either another composite
     * or a leaf {field, operator, value}. Each leaf is validated against
     * the resolved schema's properties:
     *
     * - field must exist as a property on the schema
     * - operator must be one of OPERATOR_TYPE_MATRIX's keys
     * - operator must be compatible with the property's `type` (so
     *   `industry > 50` on a string field is rejected)
     * - value must be coercible to the field type (so
     *   `employees = "not-a-number"` is rejected)
     *
     * Returns NULL when the tree is structurally and semantically valid.
     * Returns a human-readable error string locating the first failure;
     * the caller surfaces this as a field-level save error.
     *
     * @param array<string, mixed> $rules      The rule tree (AND/OR node).
     * @param string               $entityType "contact" or "customer".
     *
     * @return string|null NULL on success, otherwise the first error.
     *
     * @spec openspec/changes/marketing-segmentation-and-blast-02-segment-service/tasks.md#task-2.2
     */
    public function validateRules(array $rules, string $entityType): ?string
    {
        $properties = $this->resolveSchemaProperties(entityType: $entityType);
        if ($properties === null) {
            return sprintf('Unknown entityType "%s" (no schema mapping configured).', $entityType);
        }

        return $this->validateNode(node: $rules, path: '$', properties: $properties);
    }//end validateRules()

    /**
     * Recursively validate a node.
     *
     * @param array<string, mixed> $node       Tree node.
     * @param string               $path       JSON-pointer-ish breadcrumb.
     * @param array<string, mixed> $properties Schema properties map.
     *
     * @return string|null Error string or null.
     */
    private function validateNode(array $node, string $path, array $properties): ?string
    {
        $type = $this->nodeType(node: $node);
        if ($type === 'AND' || $type === 'OR') {
            $children = ($node['children'] ?? null);
            if (is_array($children) === false || $children === []) {
                return sprintf('%s: composite "%s" node requires non-empty "children".', $path, $type);
            }
            foreach ($children as $index => $child) {
                if (is_array($child) === false) {
                    return sprintf('%s.children[%d]: child must be an object.', $path, $index);
                }
                $childError = $this->validateNode(
                    node: $child,
                    path: $path . '.children[' . $index . ']',
                    properties: $properties
                );
                if ($childError !== null) {
                    return $childError;
                }
            }
            return null;
        }
        if ($type === 'NOT') {
            $children = ($node['children'] ?? null);
            if (is_array($children) === false || count($children) !== 1) {
                return sprintf('%s: NOT node requires exactly one child.', $path);
            }
            $child = $children[0];
            if (is_array($child) === false) {
                return sprintf('%s.children[0]: child must be an object.', $path);
            }
            return $this->validateNode(node: $child, path: $path . '.children[0]', properties: $properties);
        }

        // Leaf predicate.
        $field = ($node['field'] ?? null);
        if (is_string($field) === false || $field === '') {
            return sprintf('%s: leaf predicate requires non-empty "field".', $path);
        }
        if (array_key_exists($field, $properties) === false) {
            return sprintf('%s: field "%s" is not declared on the entity schema.', $path, $field);
        }
        $operator = ($node['operator'] ?? null);
        if (is_string($operator) === false || isset(self::OPERATOR_TYPE_MATRIX[$operator]) === false) {
            return sprintf('%s: operator "%s" is not supported.', $path, (string) $operator);
        }
        $fieldType    = $this->propertyType(property: $properties[$field]);
        $allowedTypes = self::OPERATOR_TYPE_MATRIX[$operator];
        if (in_array($fieldType, $allowedTypes, true) === false) {
            return sprintf(
                '%s: operator "%s" is not valid for field "%s" of type "%s".',
                $path,
                $operator,
                $field,
                $fieldType
            );
        }
        if ($operator === 'isNull' || $operator === 'isNotNull') {
            return null;
        }
        if (array_key_exists('value', $node) === false) {
            return sprintf('%s: operator "%s" requires a "value".', $path, $operator);
        }
        if ($this->isValueCoercible(value: $node['value'], fieldType: $fieldType, operator: $operator) === false) {
            return sprintf(
                '%s: value for field "%s" is not coercible to type "%s".',
                $path,
                $field,
                $fieldType
            );
        }

        return null;
    }//end validateNode()

    /**
     * Determine whether a value is coercible to the field's declared type.
     *
     * @param mixed  $value     The raw rule value.
     * @param string $fieldType JSON-schema type.
     * @param string $operator  Operator (drives array-vs-scalar shape).
     *
     * @return bool True when coercion succeeds.
     */
    private function isValueCoercible(mixed $value, string $fieldType, string $operator): bool
    {
        if ($operator === 'in' || $operator === 'notIn' || $operator === 'containsAny' || $operator === 'between') {
            if (is_array($value) === false) {
                return false;
            }
            if ($operator === 'between' && count($value) !== 2) {
                return false;
            }
            foreach ($value as $element) {
                if ($this->isScalarCoercible(value: $element, fieldType: $fieldType) === false) {
                    return false;
                }
            }
            return true;
        }

        return $this->isScalarCoercible(value: $value, fieldType: $fieldType);
    }//end isValueCoercible()

    /**
     * Determine whether one scalar value coerces to the field type.
     *
     * @param mixed  $value     The raw value.
     * @param string $fieldType JSON-schema type.
     *
     * @return bool True when coercion succeeds.
     */
    private function isScalarCoercible(mixed $value, string $fieldType): bool
    {
        if ($value === null) {
            return true;
        }
        switch ($fieldType) {
            case 'integer':
                if (is_int($value) === true) {
                    return true;
                }
                if (is_string($value) === true && preg_match('/^-?\d+$/', $value) === 1) {
                    return true;
                }
                return false;
            case 'number':
                if (is_int($value) === true || is_float($value) === true) {
                    return true;
                }
                if (is_string($value) === true && is_numeric($value) === true) {
                    return true;
                }
                return false;
            case 'boolean':
                if (is_bool($value) === true) {
                    return true;
                }
                if (is_string($value) === true && in_array(strtolower($value), ['true', 'false', '0', '1'], true) === true) {
                    return true;
                }
                if (is_int($value) === true && ($value === 0 || $value === 1)) {
                    return true;
                }
                return false;
            case 'array':
                return is_array($value);
            case 'string':
            default:
                return (is_scalar($value) === true);
        }
    }//end isScalarCoercible()

    /**
     * Return the canonical node type — `AND`, `OR`, `NOT`, or `LEAF`.
     *
     * @param array<string, mixed> $node Tree node.
     *
     * @return string The node type.
     */
    private function nodeType(array $node): string
    {
        $declared = ($node['type'] ?? null);
        if (is_string($declared) === true) {
            $upper = strtoupper($declared);
            if (in_array($upper, ['AND', 'OR', 'NOT'], true) === true) {
                return $upper;
            }
        }
        return 'LEAF';
    }//end nodeType()

    /**
     * Resolve a schema property's JSON-schema type, defaulting to string.
     *
     * @param mixed $property The property definition.
     *
     * @return string Type string.
     */
    private function propertyType(mixed $property): string
    {
        if (is_array($property) === true && isset($property['type']) === true && is_string($property['type']) === true) {
            return $property['type'];
        }
        return 'string';
    }//end propertyType()

    /**
     * Resolve the entityType's schema properties via OpenRegister.
     *
     * Looks up the schema slug for the requested entityType, then fetches
     * the full Schema entity through OpenRegister's SchemaMapper so the
     * rule validator can read each property's declared `type`. Returns
     * null when the entityType is unknown or OpenRegister is unreachable
     * — callers translate that into a validation error rather than
     * raising.
     *
     * @param string $entityType "contact" or "customer".
     *
     * @return array<string, mixed>|null Properties map, or null when the
     *                                   schema is not resolvable.
     *
     * @spec openspec/changes/marketing-segmentation-and-blast-02-segment-service/tasks.md#task-2.3
     */
    protected function resolveSchemaProperties(string $entityType): ?array
    {
        $schemaSlug = $this->resolveSchemaSlug(entityType: $entityType);
        if ($schemaSlug === '') {
            return null;
        }

        $schemaMapper = $this->getSchemaMapper();
        if ($schemaMapper === null) {
            return null;
        }

        try {
            $schema = $schemaMapper->find(
                id: $schemaSlug,
                published: null,
                _rbac: false,
                _multitenancy: false,
            );
        } catch (Throwable $e) {
            $this->logger->info(
                'SegmentService.resolveSchemaProperties: schema lookup failed',
                ['entityType' => $entityType, 'slug' => $schemaSlug, 'exception' => $e->getMessage()]
            );
            return null;
        }

        if (is_object($schema) === false || method_exists($schema, 'getProperties') === false) {
            return null;
        }
        $properties = $schema->getProperties();
        if (is_array($properties) === false) {
            return null;
        }
        return $properties;
    }//end resolveSchemaProperties()

    /**
     * Resolve the schema slug for the given entityType.
     *
     * Resolution order:
     * 1. The Pipelinq app config key (`contact_schema` /
     *    `customer_schema` / `client_schema`) if it is set.
     * 2. A sensible default — `contact` for contacts, `client` for
     *    customers — matching the pipelinq register declarations.
     *
     * @param string $entityType "contact" or "customer".
     *
     * @return string The resolved schema slug, or empty when unknown.
     *
     * @spec openspec/changes/marketing-segmentation-and-blast-02-segment-service/tasks.md#task-2.3
     */
    protected function resolveSchemaSlug(string $entityType): string
    {
        $entityType    = strtolower($entityType);
        $candidateKeys = [];
        if ($entityType === 'contact') {
            $candidateKeys = ['contact_schema'];
        } elseif ($entityType === 'customer' || $entityType === 'client') {
            $candidateKeys = ['customer_schema', 'client_schema'];
        }

        foreach ($candidateKeys as $key) {
            $slug = $this->appConfig->getValueString(Application::APP_ID, $key, '');
            if ($slug !== '') {
                return $slug;
            }
        }

        if ($entityType === 'contact') {
            return self::DEFAULT_CONTACT_SCHEMA_SLUG;
        }
        if ($entityType === 'customer' || $entityType === 'client') {
            return self::DEFAULT_CUSTOMER_SCHEMA_SLUG;
        }
        return '';
    }//end resolveSchemaSlug()

    /**
     * Resolve the OpenRegister SchemaMapper lazily.
     *
     * @return object|null SchemaMapper, or null when OpenRegister is not
     *                     loaded.
     */
    private function getSchemaMapper(): ?object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Db\SchemaMapper');
        } catch (Throwable $e) {
            $this->logger->info(
                'SegmentService.getSchemaMapper: SchemaMapper unavailable',
                ['exception' => $e->getMessage()]
            );
            return null;
        }
    }//end getSchemaMapper()

    /**
     * Reference field retained so the schema-map cache hint stays
     * touched in case a sub-class wants to override the mapping.
     *
     * @return SchemaMapService Schema-map helper.
     */
    protected function schemaMapService(): SchemaMapService
    {
        return $this->schemaMapService;
    }//end schemaMapService()
}//end class
