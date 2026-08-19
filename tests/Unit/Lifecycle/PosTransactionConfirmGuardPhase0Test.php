<?php

/**
 * Phase-0 regression tests for PosTransactionConfirmGuard's cart-lookup path.
 *
 * Locks the two server-side fixes that made the confirm guard usable on a
 * deployed box where the `posTransactionLine_schema` app-config key is blank:
 *
 *   1. slug-fallback — a blank `posTransactionLine_schema` is resolved to its
 *      numeric schema id via the SchemaMapper (the canonical 'posTransactionLine'
 *      slug). Previously a blank key made the guard fail closed and DENY every
 *      confirm on such a box.
 *   2. `@self` nesting — register / schema are nested under `@self` in the line
 *      findAll() filter (flat keys match nothing → guard sees every cart as empty
 *      → denies every confirm).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Lifecycle;

use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCA\Pipelinq\Lifecycle\PosTransactionConfirmGuard;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * ObjectService fake recording the findAll() config and returning fixed rows.
 */
class ConfirmGuardCapturingObjectService
{

    /**
     * Captured findAll() config arrays.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $findAllCalls = [];

    /**
     * Rows returned by findAll().
     *
     * @var array<int, array<string, mixed>>
     */
    public array $rows = [];

    /**
     * Record and answer a findAll() query.
     *
     * @param array<string, mixed> $config The query config.
     *
     * @return array<int, array<string, mixed>> The rows.
     */
    public function findAll(array $config): array
    {
        $this->findAllCalls[] = $config;
        return $this->rows;
    }//end findAll()
}//end class

/**
 * Fake schema entity with a magic getId().
 */
class ConfirmGuardFakeSchema
{
    /**
     * Constructor.
     *
     * @param int $id The numeric id.
     */
    public function __construct(private int $id)
    {
    }//end __construct()

    /**
     * The numeric schema id.
     *
     * @return int The id.
     */
    public function getId(): int
    {
        return $this->id;
    }//end getId()
}//end class

/**
 * Fake SchemaMapper resolving a slug to a numeric id.
 */
class ConfirmGuardFakeSchemaMapper
{
    /**
     * Constructor.
     *
     * @param array<string, int> $idsBySlug Slug => id map.
     */
    public function __construct(private array $idsBySlug=[])
    {
    }//end __construct()

    /**
     * Resolve a slug to a fake schema.
     *
     * @param string             $idOrSlug The slug.
     * @param array<int, string> $extend   Unused.
     * @param int|null           $register Unused.
     * @param bool               $rbac     Unused.
     * @param bool               $tenant   Unused.
     *
     * @return ConfirmGuardFakeSchema The resolved schema.
     *
     * @throws \RuntimeException When unknown.
     */
    public function find(
        string $idOrSlug,
        array $extend=[],
        ?int $register=null,
        bool $rbac=true,
        bool $tenant=true,
    ): ConfirmGuardFakeSchema {
        if (isset($this->idsBySlug[$idOrSlug]) === false) {
            throw new \RuntimeException('unknown slug '.$idOrSlug);
        }

        return new ConfirmGuardFakeSchema($this->idsBySlug[$idOrSlug]);
    }//end find()
}//end class

/**
 * Phase-0 regression tests for the confirm guard's cart lookup.
 */
class PosTransactionConfirmGuardPhase0Test extends TestCase
{
    /**
     * Build a confirm guard for the owning cashier with a blank line-schema
     * config (forcing the slug-fallback), wired to the given OR fakes.
     *
     * @param ConfirmGuardCapturingObjectService $objects The fake ObjectService.
     * @param ConfirmGuardFakeSchemaMapper       $mapper  The fake SchemaMapper.
     * @param array<string, string>              $config  The app-config map.
     *
     * @return PosTransactionConfirmGuard The guard.
     */
    private function guard(
        ConfirmGuardCapturingObjectService $objects,
        ConfirmGuardFakeSchemaMapper $mapper,
        array $config,
    ): PosTransactionConfirmGuard {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static fn (string $app, string $key, string $default=''): string => ($config[$key] ?? $default)
        );

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn(false);
        $groupManager->method('isInGroup')->willReturn(false);

        $policy = new PosAccessPolicy(appConfig: $appConfig, groupManager: $groupManager);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static function (string $id) use ($objects, $mapper) {
                if ($id === 'OCA\OpenRegister\Service\ObjectService') {
                    return $objects;
                }

                if ($id === 'OCA\OpenRegister\Db\SchemaMapper') {
                    return $mapper;
                }

                throw new \RuntimeException('unknown service '.$id);
            }
        );

        return new PosTransactionConfirmGuard(
            policy: $policy,
            container: $container,
            appConfig: $appConfig,
            logger: $this->createMock(LoggerInterface::class),
        );
    }//end guard()

    /**
     * With a BLANK posTransactionLine_schema config, the guard resolves the slug
     * to a numeric id, scopes the lookup under `@self`, finds the cart line, and
     * ALLOWS the owning cashier — instead of failing closed.
     *
     * @return void
     */
    public function testSlugFallbackAllowsConfirmWhenCartHasLine(): void
    {
        $objects       = new ConfirmGuardCapturingObjectService();
        $objects->rows = [['id' => 'line-1']];

        $mapper = new ConfirmGuardFakeSchemaMapper(['posTransactionLine' => 42]);

        // Register set, posTransactionLine_schema deliberately BLANK.
        $guard = $this->guard(
            objects: $objects,
            mapper: $mapper,
            config: ['register' => '16'],
        );

        $result = $guard->check(['id' => 'txn-1', 'cashier' => 'alice'], 'confirm', 'alice');

        $this->assertTrue($result->isAllowed());

        // The findAll() filter scoped register/schema under @self with the
        // slug-resolved numeric schema id (42).
        $this->assertNotEmpty($objects->findAllCalls);
        $filters = $objects->findAllCalls[0]['filters'];
        $this->assertArrayHasKey('@self', $filters);
        $this->assertSame('16', $filters['@self']['register']);
        $this->assertSame('42', $filters['@self']['schema']);
        $this->assertSame('txn-1', $filters['transaction']);
        $this->assertArrayNotHasKey('schema', $filters);
    }//end testSlugFallbackAllowsConfirmWhenCartHasLine()

    /**
     * The guard still denies a genuinely empty cart even when the slug-fallback
     * successfully resolves the schema (the fallback must not mask the real
     * non-empty-cart precondition).
     *
     * @return void
     */
    public function testSlugFallbackStillDeniesEmptyCart(): void
    {
        $objects       = new ConfirmGuardCapturingObjectService();
        $objects->rows = [];

        $mapper = new ConfirmGuardFakeSchemaMapper(['posTransactionLine' => 42]);

        $guard = $this->guard(
            objects: $objects,
            mapper: $mapper,
            config: ['register' => '16'],
        );

        $result = $guard->check(['id' => 'txn-1', 'cashier' => 'alice'], 'confirm', 'alice');

        $this->assertFalse($result->isAllowed());
        $this->assertStringContainsString('artikel', (string) $result->getMessage());
    }//end testSlugFallbackStillDeniesEmptyCart()

    /**
     * When the schema slug cannot be resolved (mapper throws) and the config key
     * is blank, the guard fails closed and DENIES — the safe default.
     *
     * @return void
     */
    public function testUnresolvableSchemaFailsClosed(): void
    {
        $objects       = new ConfirmGuardCapturingObjectService();
        $objects->rows = [['id' => 'line-1']];

        // Mapper knows nothing → find() throws → resolveSchemaIdBySlug returns ''.
        $mapper = new ConfirmGuardFakeSchemaMapper([]);

        $guard = $this->guard(
            objects: $objects,
            mapper: $mapper,
            config: ['register' => '16'],
        );

        $result = $guard->check(['id' => 'txn-1', 'cashier' => 'alice'], 'confirm', 'alice');

        $this->assertFalse($result->isAllowed());
        // No findAll() was attempted because the schema never resolved.
        $this->assertEmpty($objects->findAllCalls);
    }//end testUnresolvableSchemaFailsClosed()
}//end class
