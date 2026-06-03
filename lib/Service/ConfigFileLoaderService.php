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
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The class owns the full ADR-037
 *               fragment-merge contract: monolith load, fragment discovery, recursive
 *               deep-merge with additive-union semantics for register schema-membership
 *               and seed-object lists, and version stamping. The branches are small,
 *               extracted into focused helpers, and cohesive to this single concern.
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
            $fragmentData = $this->readFragment(fragmentFile: $fragmentFile);
            if ($fragmentData === null) {
                continue;
            }

            $data          = self::deepMergeConfig(base: $data, override: $fragmentData);
            $fragmentBlob .= json_encode($fragmentData);
        }//end foreach

        return self::stampFragmentVersion(data: $data, fragmentBlob: $fragmentBlob);
    }//end mergeRegisterFragments()

    /**
     * Read and decode a single register fragment file.
     *
     * @param string $fragmentFile The absolute fragment path.
     *
     * @return array|null The decoded fragment, or null when it is not an array.
     *
     * @throws RuntimeException If the file cannot be read or parsed.
     */
    private function readFragment(string $fragmentFile): ?array
    {
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
            return null;
        }

        return $fragmentData;
    }//end readFragment()

    /**
     * Fold a short hash of the merged fragments into info.version.
     *
     * @param array  $data         The merged configuration.
     * @param string $fragmentBlob The concatenated fragment payloads.
     *
     * @return array The configuration with a version-stamped info block.
     */
    private static function stampFragmentVersion(array $data, string $fragmentBlob): array
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
    }//end stampFragmentVersion()

    /**
     * Key names whose list values are additively unioned (not replaced) when a
     * fragment merges onto the base configuration.
     *
     * Two register-level list keys must accumulate across fragments rather than
     * overwrite the monolith (ADR-037 fleet-standard rule):
     *
     * - `schemas`  — the register's schema-membership list (slug strings). A
     *   fragment that adds a new schema must extend, not replace, the existing
     *   membership, otherwise every monolith schema disappears from the register.
     * - `objects`  — the `components.objects[]` seed list. A fragment that ships
     *   seed objects must append to the existing seeds, not clobber them.
     *
     * All other list keys keep replace-semantics (the fragment value wins).
     *
     * @var array<int, string>
     */
    private const ADDITIVE_LIST_KEYS = ['schemas', 'objects'];

    /**
     * Recursively deep-merge an override array onto a base array.
     *
     * Associative keys are merged recursively; scalar and list values from the
     * override replace those in the base, EXCEPT the additive-union list keys
     * (see {@see self::ADDITIVE_LIST_KEYS}) which accumulate across fragments.
     * This mirrors the fragment-merge semantics shared across the fleet (ADR-037).
     *
     * @param array $base     The base configuration array.
     * @param array $override The fragment to merge on top of the base.
     *
     * @return array The deep-merged result.
     */
    private static function deepMergeConfig(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            $current = ($base[$key] ?? null);

            // Additive union for register schema-membership and seed-object lists:
            // accumulate entries instead of replacing the monolith's list wholesale.
            if (self::shouldUnion(key: $key, base: $current, value: $value) === true) {
                $base[$key] = self::unionLists(base: $current, override: $value);
                continue;
            }

            // Associative (map) values merge recursively; lists/scalars replace.
            if (self::shouldRecurse(base: $current, value: $value) === true) {
                $base[$key] = self::deepMergeConfig(base: $current, override: $value);
                continue;
            }

            $base[$key] = $value;
        }//end foreach

        return $base;
    }//end deepMergeConfig()

    /**
     * Whether a fragment value should additively union onto the base list.
     *
     * @param int|string $key   The override key.
     * @param mixed      $base  The current base value at $key.
     * @param mixed      $value The override value.
     *
     * @return bool True when both sides are lists under an additive-union key.
     */
    private static function shouldUnion(int|string $key, mixed $base, mixed $value): bool
    {
        return is_string($key) === true
            && in_array($key, self::ADDITIVE_LIST_KEYS, true) === true
            && is_array($value) === true
            && is_array($base) === true
            && self::isList(value: $value) === true
            && self::isList(value: $base) === true;
    }//end shouldUnion()

    /**
     * Whether a fragment value should deep-merge (both sides associative maps).
     *
     * @param mixed $base  The current base value at the key.
     * @param mixed $value The override value.
     *
     * @return bool True when both sides are associative arrays.
     */
    private static function shouldRecurse(mixed $base, mixed $value): bool
    {
        return is_array($value) === true
            && is_array($base) === true
            && self::isList(value: $value) === false
            && self::isList(value: $base) === false;
    }//end shouldRecurse()

    /**
     * Additively union two sequential lists, de-duplicating by stable identity.
     *
     * Scalar entries (e.g. schema-membership slug strings) de-duplicate by value.
     * Object entries (e.g. seed objects) de-duplicate by their `@self.slug`
     * (falling back to a top-level `id`); entries without a stable identity are
     * always appended. Override entries replace a base entry sharing the same
     * identity so a fragment can refine a seed it owns without creating a clone.
     *
     * @param array $base     The base list (monolith values).
     * @param array $override The fragment list to union on top.
     *
     * @return array The unioned list, base order preserved, new entries appended.
     */
    private static function unionLists(array $base, array $override): array
    {
        $indexByIdentity = [];
        foreach ($base as $position => $entry) {
            $identity = self::listEntryIdentity(entry: $entry);
            if ($identity !== null) {
                $indexByIdentity[$identity] = $position;
            }
        }

        foreach ($override as $entry) {
            $identity = self::listEntryIdentity(entry: $entry);
            if ($identity !== null && isset($indexByIdentity[$identity]) === true) {
                $base[$indexByIdentity[$identity]] = $entry;
                continue;
            }

            $base[] = $entry;
            if ($identity !== null) {
                $indexByIdentity[$identity] = (array_key_last($base));
            }
        }//end foreach

        return $base;
    }//end unionLists()

    /**
     * Derive a stable de-duplication identity for a union-list entry.
     *
     * @param mixed $entry The list entry (scalar slug or seed object array).
     *
     * @return string|null The identity string, or null when none can be derived.
     */
    private static function listEntryIdentity(mixed $entry): ?string
    {
        if (is_string($entry) === true) {
            return $entry;
        }

        if (is_array($entry) === true) {
            $slug = ($entry['@self']['slug'] ?? null);
            if (is_string($slug) === true && $slug !== '') {
                return '@self.slug:'.$slug;
            }

            $id = ($entry['id'] ?? null);
            if (is_string($id) === true && $id !== '') {
                return 'id:'.$id;
            }
        }

        return null;
    }//end listEntryIdentity()

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
