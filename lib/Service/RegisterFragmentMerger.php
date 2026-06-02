<?php

/**
 * Pipelinq RegisterFragmentMerger.
 *
 * Deep-merges modular OpenRegister register fragments (ADR-037) onto the
 * monolith register configuration, additively unioning seed objects and
 * register schema-membership lists.
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
 * @spec openspec/changes/lead-product-link/tasks.md#task-6.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use RuntimeException;

/**
 * Merges register fragments onto a base register configuration (ADR-037).
 *
 * @spec openspec/changes/lead-product-link/tasks.md#task-6.3
 */
class RegisterFragmentMerger
{
    /**
     * Merge a list of decoded fragment configurations onto a base configuration.
     *
     * Each fragment is deep-merged in order; seed objects and register schema
     * membership are additively unioned. A short hash of the concatenated raw
     * fragment content is folded into `info.version` so OpenRegister's
     * version-gated import re-runs whenever any fragment changes.
     *
     * @param array  $base         The base monolith configuration.
     * @param array  $fragments    The decoded fragment configurations, in order.
     * @param string $fragmentBlob The concatenated raw fragment content.
     *
     * @return array The merged configuration data.
     *
     * @spec openspec/changes/lead-product-link/tasks.md#task-6.3
     */
    public function merge(array $base, array $fragments, string $fragmentBlob): array
    {
        $data = $base;
        foreach ($fragments as $fragment) {
            if (is_array($fragment) === false) {
                continue;
            }

            $data = self::mergeRegisterConfig(base: $data, override: $fragment);
        }

        return self::stampFragmentVersion(data: $data, fragmentBlob: $fragmentBlob);
    }//end merge()

    /**
     * Decode a register fragment's JSON, throwing on malformed JSON.
     *
     * @param string $fragmentFile    The absolute fragment file path (for errors).
     * @param string $fragmentContent The raw fragment file content.
     *
     * @return mixed The decoded fragment data.
     *
     * @throws RuntimeException If the fragment JSON is invalid.
     *
     * @spec openspec/changes/lead-product-link/tasks.md#task-6.3
     */
    public function decode(string $fragmentFile, string $fragmentContent)
    {
        $fragmentData = json_decode($fragmentContent, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException(
                "Invalid JSON in register fragment {$fragmentFile}: ".json_last_error_msg()
            );
        }

        return $fragmentData;
    }//end decode()

    /**
     * Fold a short hash of all fragment content into `info.version`.
     *
     * @param array  $data         The merged configuration data.
     * @param string $fragmentBlob The concatenated raw content of all fragments.
     *
     * @return array The configuration data with a fragment-stamped version.
     */
    private static function stampFragmentVersion(array $data, string $fragmentBlob): array
    {
        if ($fragmentBlob === '') {
            return $data;
        }

        $baseVersion  = ($data['info']['version'] ?? '0.0.0');
        $fragmentHash = substr(hash('sha256', $fragmentBlob), 0, 8);
        if (isset($data['info']) === false || is_array($data['info']) === false) {
            $data['info'] = [];
        }

        $data['info']['version'] = $baseVersion.'+frag.'.$fragmentHash;

        return $data;
    }//end stampFragmentVersion()

    /**
     * Merge a fragment register configuration onto the base configuration.
     *
     * This is the fleet-standard ADR-037 register merge. It deep-merges the
     * fragment onto the base for every key EXCEPT two list keys that must be
     * additively unioned rather than replaced:
     *
     * - `components.objects[]` — seed objects. A fragment that contributes its
     *   own seed objects MUST add to (not replace) the monolith's seed objects.
     *   Objects are de-duplicated by their `@self.register` + `@self.schema` +
     *   `@self.slug` identity so re-merging is idempotent.
     * - `components.registers.<slug>.schemas[]` — a register's schema membership
     *   list. A fragment that adds a schema to an existing register MUST extend
     *   the membership list, not overwrite it.
     *
     * Without this rule the generic list-replace semantics would let a single
     * small fragment wipe out the entire monolith seed set or schema list.
     *
     * @param array $base     The base configuration array.
     * @param array $override The fragment to merge on top of the base.
     *
     * @return array The merged configuration data.
     *
     * @spec openspec/changes/lead-product-link/tasks.md#task-6.3
     */
    public static function mergeRegisterConfig(array $base, array $override): array
    {
        $baseObjects     = self::asArray(value: ($base['components']['objects'] ?? []));
        $overrideObjects = self::asArray(value: ($override['components']['objects'] ?? []));

        $baseRegisters     = ($base['components']['registers'] ?? null);
        $overrideRegisters = ($override['components']['registers'] ?? null);

        $merged = self::deepMergeConfig(base: $base, override: $override);

        if (empty($baseObjects) === false || empty($overrideObjects) === false) {
            $merged['components']['objects'] = self::unionObjects(base: $baseObjects, override: $overrideObjects);
        }

        if (is_array($baseRegisters) === true && is_array($overrideRegisters) === true) {
            $merged['components']['registers'] = self::unionRegisterSchemas(
                base: $baseRegisters,
                override: $overrideRegisters,
                merged: self::asArray(value: ($merged['components']['registers'] ?? []))
            );
        }

        return $merged;
    }//end mergeRegisterConfig()

    /**
     * Coerce a value to an array, returning an empty array for non-arrays.
     *
     * @param mixed $value The value to coerce.
     *
     * @return array The value as an array.
     */
    private static function asArray($value): array
    {
        if (is_array($value) === false) {
            return [];
        }

        return $value;
    }//end asArray()

    /**
     * Additively union two lists of `@self`-enveloped seed objects.
     *
     * Objects are de-duplicated by their `@self.register` + `@self.schema` +
     * `@self.slug` identity. When an override object shares the identity of a
     * base object the override wins (last-writer), keeping the merge idempotent.
     *
     * @param array $base     The base list of seed objects.
     * @param array $override The fragment list of seed objects.
     *
     * @return array The unioned, de-duplicated list of seed objects.
     */
    private static function unionObjects(array $base, array $override): array
    {
        $byIdentity = [];
        $ordered    = [];

        foreach (array_merge(array_values($base), array_values($override)) as $object) {
            if (is_array($object) === false) {
                continue;
            }

            $self = ($object['@self'] ?? []);
            $slug = ($self['slug'] ?? '');

            // Anonymous (slug-less) objects cannot be matched; always keep them.
            if ($slug === '') {
                $ordered[] = $object;
                continue;
            }

            $identity = ($self['register'] ?? '').'|'.($self['schema'] ?? '').'|'.$slug;
            if (isset($byIdentity[$identity]) === true) {
                $ordered[$byIdentity[$identity]] = $object;
                continue;
            }

            $byIdentity[$identity] = count($ordered);
            $ordered[] = $object;
        }//end foreach

        return array_values($ordered);
    }//end unionObjects()

    /**
     * Additively union the schema-membership list of every register.
     *
     * @param array $base     The base register map.
     * @param array $override The fragment register map.
     * @param array $merged   The already deep-merged register map to amend.
     *
     * @return array The register map with unioned schema-membership lists.
     */
    private static function unionRegisterSchemas(array $base, array $override, array $merged): array
    {
        foreach ($override as $registerSlug => $overrideRegister) {
            if (is_array($overrideRegister) === false
                || isset($overrideRegister['schemas']) === false
                || is_array($overrideRegister['schemas']) === false
            ) {
                continue;
            }

            $baseSchemas = self::asArray(value: ($base[$registerSlug]['schemas'] ?? []));

            $merged[$registerSlug]['schemas'] = self::unionScalarList(
                base: $baseSchemas,
                override: $overrideRegister['schemas']
            );
        }//end foreach

        return $merged;
    }//end unionRegisterSchemas()

    /**
     * Additively union two scalar lists preserving order and de-duplicating.
     *
     * @param array $base     The base scalar list.
     * @param array $override The fragment scalar list.
     *
     * @return array The unioned, de-duplicated scalar list.
     */
    private static function unionScalarList(array $base, array $override): array
    {
        $result = array_values($base);
        foreach (array_values($override) as $value) {
            if (in_array($value, $result, true) === false) {
                $result[] = $value;
            }
        }

        return $result;
    }//end unionScalarList()

    /**
     * Recursively deep-merge an override array onto a base array.
     *
     * Associative keys are merged recursively; scalar and list values from the
     * override replace those in the base. Additive list keys (seed objects,
     * register schema membership) are handled separately by
     * {@see self::mergeRegisterConfig()}.
     *
     * @param array $base     The base configuration array.
     * @param array $override The fragment to merge on top of the base.
     *
     * @return array The deep-merged result.
     */
    private static function deepMergeConfig(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) === true
                && isset($base[$key]) === true
                && is_array($base[$key]) === true
                && self::isList(value: $value) === false
                && self::isList(value: $base[$key]) === false
            ) {
                $base[$key] = self::deepMergeConfig(base: $base[$key], override: $value);
                continue;
            }

            $base[$key] = $value;
        }//end foreach

        return $base;
    }//end deepMergeConfig()

    /**
     * Determine whether an array is a sequential list (zero-indexed, no gaps).
     *
     * @param array $value The array to inspect.
     *
     * @return bool True when the array is a sequential list.
     */
    private static function isList(array $value): bool
    {
        if (function_exists('array_is_list') === true) {
            return array_is_list($value);
        }

        $expectedKey = 0;
        foreach (array_keys($value) as $key) {
            if ($key !== $expectedKey) {
                return false;
            }

            $expectedKey++;
        }

        return true;
    }//end isList()
}//end class
