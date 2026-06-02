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
 * @spec openspec/changes/loyalty-program/tasks.md#task-1.1
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
     * Fold a short hash of the merged fragment content into `info.version`.
     *
     * This makes OpenRegister's version-gated import re-run whenever any
     * fragment changes (ADR-037). When no fragment content was merged the data
     * is returned unchanged.
     *
     * @param array  $data         The merged configuration data.
     * @param string $fragmentBlob Concatenated raw fragment content.
     *
     * @return array The data with `info.version` updated when applicable.
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
     * Deep-merge a single fragment onto a base configuration array.
     *
     * Thin public wrapper around {@see self::deepMergeConfig()} exposing the
     * fleet-standard fragment-merge semantics (recursive object merge, additive
     * `schemas[]` membership and `components.objects[]` seed lists, replace for
     * all other lists/scalars) for direct testing and reuse.
     *
     * @param array $base     The base configuration array.
     * @param array $override The fragment to merge on top of the base.
     *
     * @return array The deep-merged result.
     *
     * @spec openspec/changes/loyalty-program/tasks.md#task-1.1
     */
    public function mergeFragment(array $base, array $override): array
    {
        return self::deepMergeConfig(base: $base, override: $override);
    }//end mergeFragment()

    /**
     * Recursively deep-merge an override array onto a base array.
     *
     * Associative keys are merged recursively. Two membership/seed lists are
     * additive (ADR-037): the register's `schemas[]` membership list and the
     * top-level `components.objects[]` seed list are concatenated and
     * deduplicated rather than replaced, so a fragment can register new schemas
     * and ship new seed objects without clobbering those contributed by the
     * monolith or by other fragments. All other list values from the override
     * replace those in the base, mirroring the fragment-merge semantics shared
     * across the fleet.
     *
     * @param array $base     The base configuration array.
     * @param array $override The fragment to merge on top of the base.
     *
     * @return array The deep-merged result.
     */
    private static function deepMergeConfig(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            $base[$key] = self::mergeValue(
                key: $key,
                baseValue: ($base[$key] ?? null),
                hasBase: array_key_exists($key, $base),
                overrideValue: $value
            );
        }//end foreach

        return $base;
    }//end deepMergeConfig()

    /**
     * Resolve the merged value for a single key during a deep merge.
     *
     * Associative arrays merge recursively; the additive membership/seed lists
     * (`schemas[]`, `objects[]`) are unioned; everything else takes the override
     * value (replace semantics).
     *
     * @param string|int $key           The key being merged.
     * @param mixed      $baseValue     The base value for the key (null when absent).
     * @param bool       $hasBase       Whether the base actually holds this key.
     * @param mixed      $overrideValue The override value for the key.
     *
     * @return mixed The merged value.
     */
    private static function mergeValue(string | int $key, mixed $baseValue, bool $hasBase, mixed $overrideValue): mixed
    {
        if (is_array($overrideValue) === false || $hasBase === false || is_array($baseValue) === false) {
            return $overrideValue;
        }

        $overrideIsList = self::isList(value: $overrideValue);
        $baseIsList     = self::isList(value: $baseValue);

        if ($overrideIsList === false && $baseIsList === false) {
            return self::deepMergeConfig(base: $baseValue, override: $overrideValue);
        }

        if ($overrideIsList === true && $baseIsList === true) {
            return match ($key) {
                'schemas' => self::mergeSchemaMembership(base: $baseValue, override: $overrideValue),
                'objects' => self::mergeSeedObjects(base: $baseValue, override: $overrideValue),
                default => $overrideValue,
            };
        }

        return $overrideValue;
    }//end mergeValue()

    /**
     * Concatenate and deduplicate a register `schemas[]` membership list.
     *
     * Membership entries are schema-slug strings. Override entries are appended
     * to the base in order, skipping slugs already present, yielding an
     * idempotent union (ADR-037).
     *
     * @param array $base     The base membership list (schema-slug strings).
     * @param array $override The fragment membership list to fold in.
     *
     * @return array The deduplicated union of both lists.
     */
    private static function mergeSchemaMembership(array $base, array $override): array
    {
        $merged = $base;
        foreach ($override as $slug) {
            if (in_array($slug, $merged, true) === false) {
                $merged[] = $slug;
            }
        }

        return $merged;
    }//end mergeSchemaMembership()

    /**
     * Concatenate and deduplicate a `components.objects[]` seed list.
     *
     * Seed objects are deduplicated by their `@self.slug`; an override object
     * sharing a slug with a base object replaces that base entry in place, so
     * re-importing the same fragment is idempotent. Objects without a resolvable
     * slug are always appended (never silently dropped).
     *
     * @param array $base     The base seed-object list.
     * @param array $override The fragment seed-object list to fold in.
     *
     * @return array The deduplicated union of both lists.
     */
    private static function mergeSeedObjects(array $base, array $override): array
    {
        $indexBySlug = [];
        foreach ($base as $index => $object) {
            $slug = self::objectSlug(object: $object);
            if ($slug !== null) {
                $indexBySlug[$slug] = $index;
            }
        }

        $merged = $base;
        foreach ($override as $object) {
            $slug = self::objectSlug(object: $object);
            if ($slug !== null && isset($indexBySlug[$slug]) === true) {
                $merged[$indexBySlug[$slug]] = $object;
                continue;
            }

            $merged[] = $object;
            if ($slug !== null) {
                $indexBySlug[$slug] = (array_key_last($merged));
            }
        }

        return array_values($merged);
    }//end mergeSeedObjects()

    /**
     * Resolve the `@self.slug` identity of a seed object.
     *
     * @param mixed $object A candidate seed-object value.
     *
     * @return string|null The slug when present and non-empty, otherwise null.
     */
    private static function objectSlug(mixed $object): ?string
    {
        if (is_array($object) === false) {
            return null;
        }

        $slug = ($object['@self']['slug'] ?? null);
        if (is_string($slug) === true && $slug !== '') {
            return $slug;
        }

        return null;
    }//end objectSlug()

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
