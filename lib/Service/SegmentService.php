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
     * Evaluate a validated rule tree against one entity payload.
     *
     * AND nodes return true when ALL children return true.
     * OR nodes return true when ANY child returns true.
     * NOT nodes (single child) invert the child's truth value.
     * Leaf predicates compare the entity's field against the rule value
     * with type-aware coercion (date strings → unix-timestamp for
     * before/after, scalars → float for numeric comparisons, scalars →
     * lower-cased strings for equals/contains).
     *
     * Missing fields evaluate to false (predicate fails) rather than
     * throwing — a Contact without a `lastContactMoment` is simply not
     * a match for `lastContactMoment < 90 days`.
     *
     * @param array<string, mixed> $rules  Rule tree node.
     * @param array<string, mixed> $entity Entity payload (key-value).
     *
     * @return bool True when the entity matches the tree.
     *
     * @spec openspec/changes/marketing-segmentation-and-blast-02-segment-service/tasks.md#task-2.5
     */
    public function evaluateRules(array $rules, array $entity): bool
    {
        return $this->evaluateNode(node: $rules, entity: $entity);
    }//end evaluateRules()

    /**
     * Recursively evaluate a node against the entity.
     *
     * @param array<string, mixed> $node   Tree node.
     * @param array<string, mixed> $entity Entity payload.
     *
     * @return bool Truth value of this node.
     */
    private function evaluateNode(array $node, array $entity): bool
    {
        $type = $this->nodeType(node: $node);
        if ($type === 'AND') {
            $children = ($node['children'] ?? []);
            if (is_array($children) === false || $children === []) {
                return false;
            }
            foreach ($children as $child) {
                if (is_array($child) === false) {
                    return false;
                }
                if ($this->evaluateNode(node: $child, entity: $entity) === false) {
                    return false;
                }
            }
            return true;
        }
        if ($type === 'OR') {
            $children = ($node['children'] ?? []);
            if (is_array($children) === false || $children === []) {
                return false;
            }
            foreach ($children as $child) {
                if (is_array($child) === false) {
                    continue;
                }
                if ($this->evaluateNode(node: $child, entity: $entity) === true) {
                    return true;
                }
            }
            return false;
        }
        if ($type === 'NOT') {
            $children = ($node['children'] ?? []);
            if (is_array($children) === false || isset($children[0]) === false || is_array($children[0]) === false) {
                return false;
            }
            return ($this->evaluateNode(node: $children[0], entity: $entity) === false);
        }

        return $this->evaluateLeaf(leaf: $node, entity: $entity);
    }//end evaluateNode()

    /**
     * Evaluate one leaf predicate against the entity.
     *
     * @param array<string, mixed> $leaf   Leaf predicate.
     * @param array<string, mixed> $entity Entity payload.
     *
     * @return bool Truth value.
     */
    private function evaluateLeaf(array $leaf, array $entity): bool
    {
        $field = ($leaf['field'] ?? null);
        if (is_string($field) === false || $field === '') {
            return false;
        }
        $operator = (string) ($leaf['operator'] ?? 'equals');
        $value    = ($leaf['value'] ?? null);
        $actual   = ($entity[$field] ?? null);

        switch ($operator) {
            case 'equals':
                return $this->looseEquals(left: $actual, right: $value);
            case 'notEquals':
                return ($this->looseEquals(left: $actual, right: $value) === false);
            case 'gt':
            case 'greaterThan':
                return $this->compareNumeric(left: $actual, right: $value) === 1;
            case 'gte':
            case 'greaterThanOrEqual':
                return $this->compareNumeric(left: $actual, right: $value) >= 0;
            case 'lt':
            case 'lessThan':
                return $this->compareNumeric(left: $actual, right: $value) === -1;
            case 'lte':
            case 'lessThanOrEqual':
                $cmp = $this->compareNumeric(left: $actual, right: $value);
                return ($cmp === -1 || $cmp === 0);
            case 'before':
                return $this->compareDates(left: $actual, right: $value) === -1;
            case 'after':
                return $this->compareDates(left: $actual, right: $value) === 1;
            case 'between':
                if (is_array($value) === false || count($value) !== 2) {
                    return false;
                }
                $low  = $this->compareNumeric(left: $actual, right: $value[0]);
                $high = $this->compareNumeric(left: $actual, right: $value[1]);
                return ($low >= 0 && $high <= 0);
            case 'contains':
                return $this->valueContains(haystack: $actual, needle: $value);
            case 'containsAny':
                if (is_array($value) === false) {
                    return false;
                }
                foreach ($value as $candidate) {
                    if ($this->valueContains(haystack: $actual, needle: $candidate) === true) {
                        return true;
                    }
                }
                return false;
            case 'in':
                if (is_array($value) === false) {
                    return false;
                }
                foreach ($value as $candidate) {
                    if ($this->looseEquals(left: $actual, right: $candidate) === true) {
                        return true;
                    }
                }
                return false;
            case 'notIn':
                if (is_array($value) === false) {
                    return true;
                }
                foreach ($value as $candidate) {
                    if ($this->looseEquals(left: $actual, right: $candidate) === true) {
                        return false;
                    }
                }
                return true;
            case 'isNull':
                return ($actual === null);
            case 'isNotNull':
                return ($actual !== null);
            default:
                return false;
        }
    }//end evaluateLeaf()

    /**
     * Loose equality with case-insensitive string compare.
     *
     * @param mixed $left  Left value.
     * @param mixed $right Right value.
     *
     * @return bool Whether the two values match.
     */
    private function looseEquals(mixed $left, mixed $right): bool
    {
        if ($left === null && $right === null) {
            return true;
        }
        if (is_bool($left) === true || is_bool($right) === true) {
            return ((bool) $left === (bool) $right);
        }
        if (is_numeric($left) === true && is_numeric($right) === true) {
            return ((float) $left === (float) $right);
        }
        if (is_scalar($left) === true && is_scalar($right) === true) {
            return (strcasecmp((string) $left, (string) $right) === 0);
        }
        return ($left === $right);
    }//end looseEquals()

    /**
     * Numeric comparison returning -1, 0, or 1. Returns 0 when either
     * side is non-numeric so callers fail closed.
     *
     * @param mixed $left  Left value.
     * @param mixed $right Right value.
     *
     * @return int -1, 0, or 1.
     */
    private function compareNumeric(mixed $left, mixed $right): int
    {
        if (is_numeric($left) === false || is_numeric($right) === false) {
            if (is_string($left) === true && is_string($right) === true) {
                return $left <=> $right;
            }
            return 0;
        }
        $lf = (float) $left;
        $rf = (float) $right;
        if ($lf < $rf) {
            return -1;
        }
        if ($lf > $rf) {
            return 1;
        }
        return 0;
    }//end compareNumeric()

    /**
     * Parse two date strings and compare them. Returns 0 on parse
     * failure so callers fail closed.
     *
     * @param mixed $left  Left date-like value.
     * @param mixed $right Right date-like value.
     *
     * @return int -1, 0, or 1.
     */
    private function compareDates(mixed $left, mixed $right): int
    {
        $lt = $this->toTimestamp(value: $left);
        $rt = $this->toTimestamp(value: $right);
        if ($lt === null || $rt === null) {
            return 0;
        }
        if ($lt < $rt) {
            return -1;
        }
        if ($lt > $rt) {
            return 1;
        }
        return 0;
    }//end compareDates()

    /**
     * Coerce a value to a unix timestamp. Accepts ISO date strings, a
     * "N days" / "N days ago" relative expression, or an integer.
     *
     * @param mixed $value Date-like value.
     *
     * @return int|null Timestamp seconds or null on parse failure.
     */
    private function toTimestamp(mixed $value): ?int
    {
        if (is_int($value) === true) {
            return $value;
        }
        if (is_string($value) === false || $value === '') {
            return null;
        }
        $match = [];
        if (preg_match('/^(\d+)\s+days?(\s+ago)?$/i', $value, $match) === 1) {
            $days  = (int) $match[1];
            $stamp = (time() - ($days * 86400));
            return $stamp;
        }
        $parsed = strtotime($value);
        if ($parsed === false) {
            return null;
        }
        return $parsed;
    }//end toTimestamp()

    /**
     * Substring-or-array-membership check used by `contains` / `containsAny`.
     *
     * @param mixed $haystack The actual entity value.
     * @param mixed $needle   The rule value.
     *
     * @return bool Whether the haystack contains the needle.
     */
    private function valueContains(mixed $haystack, mixed $needle): bool
    {
        if ($haystack === null) {
            return false;
        }
        if (is_array($haystack) === true) {
            foreach ($haystack as $element) {
                if ($this->looseEquals(left: $element, right: $needle) === true) {
                    return true;
                }
            }
            return false;
        }
        if (is_scalar($haystack) === false || is_scalar($needle) === false) {
            return false;
        }
        $haystackStr = strtolower((string) $haystack);
        $needleStr   = strtolower((string) $needle);
        if ($needleStr === '') {
            return false;
        }
        return (str_contains($haystackStr, $needleStr) === true);
    }//end valueContains()

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
