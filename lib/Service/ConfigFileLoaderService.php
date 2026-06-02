<?php

/**
 * Pipelinq ConfigFileLoaderService.
 *
 * Service for loading and parsing the Pipelinq register configuration JSON file.
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-64
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\App\IAppManager;
use RuntimeException;

/**
 * Service for loading and parsing configuration JSON files.
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-64
 * @spec openspec/changes/project-task-hierarchy/specs.md#REQ-PTH-001
 */
class ConfigFileLoaderService
{
    /**
     * Path to the register config file.
     *
     * @var string
     */
    private const REGISTER_FILE = '/lib/Settings/pipelinq_register.json';

    /**
     * Directory (relative to the app root) holding modular register fragments.
     *
     * Each `*.json` file in this directory is deep-merged onto the monolith
     * register configuration (ADR-037). This lets concurrent same-app builds
     * extend the register/schema set by dropping a new fragment file instead of
     * editing the shared monolith, eliminating merge conflicts on the register.
     *
     * @var string
     */
    private const REGISTER_FRAGMENT_DIR = '/lib/Settings/register.d';

    /**
     * Constructor.
     *
     * @param IAppManager $appManager The app manager.
     */
    public function __construct(
        private IAppManager $appManager,
    ) {
    }//end __construct()

    /**
     * Load and parse the configuration JSON file.
     *
     * @return array The parsed configuration data.
     *
     * @throws RuntimeException If the file cannot be read or parsed.
     * @spec   openspec/changes/reverse-2026-05-26-be-settings/tasks.md#task-10
     */
    public function loadConfigurationFile(): array
    {
        $appPath          = $this->appManager->getAppPath(Application::APP_ID);
        $absoluteFilePath = $appPath.self::REGISTER_FILE;

        if (file_exists($absoluteFilePath) === false) {
            throw new RuntimeException("Configuration file not found: {$absoluteFilePath}");
        }

        $jsonContent = file_get_contents($absoluteFilePath);
        if ($jsonContent === false) {
            throw new RuntimeException("Failed to read configuration file: {$absoluteFilePath}");
        }

        $data = json_decode($jsonContent, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Invalid JSON in configuration file: '.json_last_error_msg());
        }

        $data = $this->mergeRegisterFragments(data: $data, appPath: $appPath);

        return $data;
    }//end loadConfigurationFile()

    /**
     * Deep-merge modular register fragments onto the monolith configuration.
     *
     * Reads every `*.json` file under {@see self::REGISTER_FRAGMENT_DIR} in a
     * stable (sorted) order and deep-merges each onto the base configuration via
     * {@see self::deepMergeConfig()}. A short hash derived from the merged
     * fragment content is folded into `info.version` so OpenRegister's
     * version-gated import re-runs whenever a fragment changes (ADR-037).
     *
     * When the fragment directory is absent or empty, the monolith data is
     * returned unchanged.
     *
     * @param array  $data    The parsed monolith configuration data.
     * @param string $appPath The absolute app root path.
     *
     * @return array The merged configuration data.
     *
     * @throws RuntimeException If a fragment file cannot be read or parsed.
     */
    private function mergeRegisterFragments(array $data, string $appPath): array
    {
        $fragmentDir = $appPath.self::REGISTER_FRAGMENT_DIR;
        if (is_dir($fragmentDir) === false) {
            return $data;
        }

        $fragmentFiles = glob($fragmentDir.'/*.json');
        if ($fragmentFiles === false || empty($fragmentFiles) === true) {
            return $data;
        }

        sort($fragmentFiles);

        $fragmentBlob = '';
        foreach ($fragmentFiles as $fragmentFile) {
            $fragmentContent = file_get_contents($fragmentFile);
            if ($fragmentContent === false) {
                throw new RuntimeException("Failed to read register fragment: {$fragmentFile}");
            }

            $fragmentData = json_decode($fragmentContent, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException(
                    "Invalid JSON in register fragment {$fragmentFile}: ".json_last_error_msg()
                );
            }

            if (is_array($fragmentData) === false) {
                continue;
            }

            $data          = self::deepMergeConfig(base: $data, override: $fragmentData);
            $fragmentBlob .= $fragmentContent;
        }//end foreach

        return self::foldFragmentVersion(data: $data, fragmentBlob: $fragmentBlob);
    }//end mergeRegisterFragments()

    /**
     * Fold a hash of the fragment content into `info.version`.
     *
     * OpenRegister's import is version-gated, so a changed fragment set must bump
     * the reported version to force a re-import (ADR-037). A no-op when no
     * fragment content was merged.
     *
     * @param array  $data         The merged configuration data.
     * @param string $fragmentBlob The concatenated raw fragment content.
     *
     * @return array The data with the version suffix applied.
     */
    private static function foldFragmentVersion(array $data, string $fragmentBlob): array
    {
        if ($fragmentBlob === '') {
            return $data;
        }

        if (isset($data['info']) === false || is_array($data['info']) === false) {
            $data['info'] = [];
        }

        $baseVersion  = ($data['info']['version'] ?? '0.0.0');
        $fragmentHash = substr(hash('sha256', $fragmentBlob), 0, 8);
        $data['info']['version'] = $baseVersion.'+frag.'.$fragmentHash;

        return $data;
    }//end foldFragmentVersion()

    /**
     * Recursively deep-merge an override array onto a base array.
     *
     * Associative keys are merged recursively; scalar and list values from the
     * override replace those in the base. This mirrors the fragment-merge
     * semantics shared across the fleet (ADR-037).
     *
     * Two list paths are an intentional exception and are **additively unioned**
     * rather than replaced, so a fragment can contribute seed objects and
     * register-schema memberships without clobbering the monolith (or earlier
     * fragments) — the canonical failure mode of concurrent same-app builds:
     *
     *  - the seed-object list `components.objects[]` is unioned, de-duplicated by
     *    each object's `@self.slug` (fragment wins on a slug clash);
     *  - any register's `schemas[]` membership list (a list of schema-slug
     *    strings) is unioned, de-duplicated by value, order-preserving.
     *
     * Every other list keeps replace semantics. The union is keyed on the local
     * key during iteration (`objects` / `schemas`); `components.schemas` is a
     * dict, never a list, so only a register's membership `schemas[]` list can
     * reach the union branch.
     *
     * @param array $base     The base configuration array.
     * @param array $override The fragment to merge on top of the base.
     *
     * @return array The deep-merged result.
     */
    private static function deepMergeConfig(array $base, array $override): array
    {
        foreach ($override as $childKey => $value) {
            $base[$childKey] = self::mergeValue(
                key: (string) $childKey,
                baseValue: ($base[$childKey] ?? null),
                overrideValue: $value
            );
        }

        return $base;
    }//end deepMergeConfig()

    /**
     * Merge a single override value onto its base counterpart by fragment rules.
     *
     * Two assoc arrays merge recursively; the `objects` / `schemas` list paths
     * are additively unioned; every other value (including other lists) is
     * replaced by the override.
     *
     * @param string $key           The key the values sit under.
     * @param mixed  $baseValue     The current base value (null when absent).
     * @param mixed  $overrideValue The fragment value.
     *
     * @return mixed The merged value.
     */
    private static function mergeValue(string $key, mixed $baseValue, mixed $overrideValue): mixed
    {
        if (is_array($overrideValue) === false || is_array($baseValue) === false) {
            return $overrideValue;
        }

        $overrideIsList = self::isList(value: $overrideValue);
        if ($overrideIsList === false && self::isList(value: $baseValue) === false) {
            return self::deepMergeConfig(base: $baseValue, override: $overrideValue);
        }

        if ($overrideIsList === true && self::isList(value: $baseValue) === true) {
            if ($key === 'objects') {
                return self::unionObjectsBySlug(base: $baseValue, override: $overrideValue);
            }

            if ($key === 'schemas') {
                return self::unionScalarList(base: $baseValue, override: $overrideValue);
            }
        }

        return $overrideValue;
    }//end mergeValue()

    /**
     * Additively union two seed-object lists, de-duplicating by `@self.slug`.
     *
     * Objects present only in the base are preserved in order; objects from the
     * override are appended, and an override object whose `@self.slug` already
     * exists in the base replaces that base entry in place (fragment wins). Seed
     * objects without a resolvable slug are always appended (they cannot collide).
     *
     * @param array $base     The base seed-object list.
     * @param array $override The fragment's seed-object list.
     *
     * @return array The unioned seed-object list.
     */
    private static function unionObjectsBySlug(array $base, array $override): array
    {
        $indexBySlug = [];
        foreach ($base as $position => $object) {
            $slug = self::extractObjectSlug(object: $object);
            if ($slug !== null) {
                $indexBySlug[$slug] = $position;
            }
        }

        foreach ($override as $object) {
            $slug = self::extractObjectSlug(object: $object);
            if ($slug !== null && isset($indexBySlug[$slug]) === true) {
                $base[$indexBySlug[$slug]] = $object;
                continue;
            }

            $base[]   = $object;
            $position = (count($base) - 1);
            if ($slug !== null) {
                $indexBySlug[$slug] = $position;
            }
        }//end foreach

        return array_values($base);
    }//end unionObjectsBySlug()

    /**
     * Extract the `@self.slug` of a seed object, or null when absent.
     *
     * @param mixed $object The seed object (expected to be an array).
     *
     * @return string|null The slug, or null when it cannot be resolved.
     */
    private static function extractObjectSlug(mixed $object): ?string
    {
        if (is_array($object) === false) {
            return null;
        }

        $slug = ($object['@self']['slug'] ?? null);
        if (is_string($slug) === true && $slug !== '') {
            return $slug;
        }

        return null;
    }//end extractObjectSlug()

    /**
     * Additively union two scalar lists, de-duplicating by value, order-preserving.
     *
     * Used for register `schemas[]` membership: base entries keep their order,
     * override entries not already present are appended.
     *
     * @param array $base     The base scalar list.
     * @param array $override The fragment's scalar list.
     *
     * @return array The unioned scalar list.
     */
    private static function unionScalarList(array $base, array $override): array
    {
        foreach ($override as $value) {
            if (in_array($value, $base, true) === false) {
                $base[] = $value;
            }
        }

        return array_values($base);
    }//end unionScalarList()

    /**
     * Determine whether an array is a sequential list (zero-indexed, no gaps).
     *
     * Provided for PHP < 8.1 parity where `array_is_list()` is unavailable.
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

    /**
     * Ensure the x-openregister sourceType is set on configuration data.
     *
     * @param array $data The configuration data.
     *
     * @return array The data with sourceType ensured.
     * @spec   openspec/changes/reverse-2026-05-26-be-settings/tasks.md#task-9
     */
    public function ensureSourceType(array $data): array
    {
        if (isset($data['x-openregister']) === false) {
            $data['x-openregister'] = [];
        }

        if (isset($data['x-openregister']['sourceType']) === false) {
            $data['x-openregister']['sourceType'] = 'local';
        }

        return $data;
    }//end ensureSourceType()
}//end class
