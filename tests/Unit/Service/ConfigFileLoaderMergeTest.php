<?php

/**
 * Unit tests for ConfigFileLoaderService fragment-merge semantics (ADR-037).
 *
 * Verifies the additive-union behaviour added for the OpenRegister membership /
 * seed lists: a fragment that contributes a `schemas[]` membership entry or a
 * `components.objects[]` seed must be UNIONED onto the monolith (not replace it),
 * deduped by slug / value for idempotent re-import, while every other list key
 * still follows the original replace semantics. Exercised through the private
 * static deepMergeConfig via reflection (no disk / OR needed).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
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

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ConfigFileLoaderService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests for the ADR-037 additive fragment merge.
 */
class ConfigFileLoaderMergeTest extends TestCase
{
    /**
     * Invoke the private static deepMergeConfig.
     *
     * @param array<string, mixed> $base     The base config.
     * @param array<string, mixed> $override The fragment.
     *
     * @return array<string, mixed> The merged result.
     */
    private function merge(array $base, array $override): array
    {
        $method = new ReflectionMethod(ConfigFileLoaderService::class, 'deepMergeConfig');
        $method->setAccessible(true);

        return $method->invoke(null, $base, $override);
    }

    /**
     * A fragment's register schema-membership is UNIONED onto the monolith's.
     *
     * @return void
     */
    public function testRegisterSchemasUnioned(): void
    {
        $base = ['components' => ['registers' => ['pipelinq' => ['schemas' => ['client', 'lead']]]]];
        $frag = ['components' => ['registers' => ['pipelinq' => ['schemas' => ['paymentProvider']]]]];

        $merged = $this->merge($base, $frag);
        $schemas = $merged['components']['registers']['pipelinq']['schemas'];

        $this->assertSame(['client', 'lead', 'paymentProvider'], $schemas);
    }

    /**
     * Re-applying the same membership entry is idempotent (no duplicate).
     *
     * @return void
     */
    public function testRegisterSchemasUnionIdempotent(): void
    {
        $base = ['components' => ['registers' => ['pipelinq' => ['schemas' => ['client', 'paymentProvider']]]]];
        $frag = ['components' => ['registers' => ['pipelinq' => ['schemas' => ['paymentProvider']]]]];

        $schemas = $this->merge($base, $frag)['components']['registers']['pipelinq']['schemas'];
        $this->assertSame(['client', 'paymentProvider'], $schemas);
    }

    /**
     * Seed objects are appended, not replaced, and deduped by @self.slug.
     *
     * @return void
     */
    public function testObjectsUnionedAndDedupedBySlug(): void
    {
        $base = ['components' => ['objects' => [
            ['@self' => ['slug' => 'txn-1'], 'reference' => 'A'],
        ]]];
        $frag = ['components' => ['objects' => [
            ['@self' => ['slug' => 'provider-mollie'], 'name' => 'mollie'],
            ['@self' => ['slug' => 'txn-1'], 'reference' => 'A'],
        ]]];

        $objects = $this->merge($base, $frag)['components']['objects'];
        $this->assertCount(2, $objects);
        $slugs = array_map(static fn (array $o): string => $o['@self']['slug'], $objects);
        $this->assertSame(['txn-1', 'provider-mollie'], $slugs);
    }

    /**
     * A non-membership list key still REPLACES (original fleet semantics).
     *
     * @return void
     */
    public function testOrdinaryListReplaced(): void
    {
        $base = ['components' => ['schemas' => ['posTransaction' => ['required' => ['cashier', 'status']]]]];
        $frag = ['components' => ['schemas' => ['posTransaction' => ['required' => ['cashier']]]]];

        $required = $this->merge($base, $frag)['components']['schemas']['posTransaction']['required'];
        // 'required' is not a union key → replaced wholesale.
        $this->assertSame(['cashier'], $required);
    }

    /**
     * A fragment's new schema definition is merged in alongside existing schemas.
     *
     * @return void
     */
    public function testNewSchemaDefinitionMerged(): void
    {
        $base = ['components' => ['schemas' => ['client' => ['title' => 'Client']]]];
        $frag = ['components' => ['schemas' => ['paymentProvider' => ['title' => 'Payment Provider']]]];

        $schemas = $this->merge($base, $frag)['components']['schemas'];
        $this->assertArrayHasKey('client', $schemas);
        $this->assertArrayHasKey('paymentProvider', $schemas);
        $this->assertSame('Payment Provider', $schemas['paymentProvider']['title']);
    }

    /**
     * A fragment extends an existing schema's properties without dropping them.
     *
     * @return void
     */
    public function testSchemaPropertiesExtended(): void
    {
        $base = ['components' => ['schemas' => ['posTransaction' => ['properties' => [
            'reference' => ['type' => 'string'],
        ]]]]];
        $frag = ['components' => ['schemas' => ['posTransaction' => ['properties' => [
            'paymentStatus' => ['type' => 'string'],
        ]]]]];

        $props = $this->merge($base, $frag)['components']['schemas']['posTransaction']['properties'];
        $this->assertArrayHasKey('reference', $props);
        $this->assertArrayHasKey('paymentStatus', $props);
    }
}
