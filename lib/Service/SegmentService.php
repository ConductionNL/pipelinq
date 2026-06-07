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
