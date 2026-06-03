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
            $fragmentContent = $this->readFragment(fragmentFile: $fragmentFile);
            $fragmentData    = $this->decodeFragment(fragmentFile: $fragmentFile, content: $fragmentContent);
            if (is_array($fragmentData) === false) {
                continue;
            }

            $data          = self::deepMergeConfig(base: $data, override: $fragmentData);
            $fragmentBlob .= $fragmentContent;
        }//end foreach

        return $this->foldFragmentVersion(data: $data, fragmentBlob: $fragmentBlob);
    }//end mergeRegisterFragments()

    /**
     * Read a fragment file's raw content.
     *
     * @param string $fragmentFile The fragment file path.
     *
     * @return string The file content.
     *
     * @throws RuntimeException When the file cannot be read.
     */
    private function readFragment(string $fragmentFile): string
    {
        $content = file_get_contents($fragmentFile);
        if ($content === false) {
            throw new RuntimeException("Failed to read register fragment: {$fragmentFile}");
        }

        return $content;
    }//end readFragment()

    /**
     * Decode a fragment's JSON content.
     *
     * @param string $fragmentFile The fragment file path (for error context).
     * @param string $content      The raw JSON content.
     *
     * @return mixed The decoded value.
     *
     * @throws RuntimeException When the content is not valid JSON.
     */
    private function decodeFragment(string $fragmentFile, string $content): mixed
    {
        $decoded = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException(
                "Invalid JSON in register fragment {$fragmentFile}: ".json_last_error_msg()
            );
        }

        return $decoded;
    }//end decodeFragment()

    /**
     * Fold a short hash of all fragment content into `info.version`.
     *
     * @param array  $data         The merged configuration data.
     * @param string $fragmentBlob The concatenated fragment content (empty when none).
     *
     * @return array The data with a version-stamped `info` block.
     */
    private function foldFragmentVersion(array $data, string $fragmentBlob): array
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
    }//end foldFragmentVersion()

    /**
     * Keys whose list values are additively unioned (not replaced) when a
     * fragment contributes them.
     *
     * Per ADR-037 a feature build extends the register by dropping a fragment
     * under `register.d/`. Two list-valued keys carry membership/seed data that
     * MUST accumulate across fragments rather than the last fragment winning:
     *
     * - `objects` — the `components.objects[]` seed list. Each fragment appends
     *   its own seed objects; replacing would drop the monolith's seeds (and any
     *   earlier fragment's seeds).
     * - `schemas` — a register's `schemas[]` membership list (string slugs).
     *   A fragment that registers a new schema must add its slug to the
     *   register, not overwrite the existing membership.
     *
     * @var array<int, string>
     */
    private const UNION_LIST_KEYS = ['objects', 'schemas'];

    /**
     * Recursively deep-merge an override array onto a base array.
     *
     * Associative keys are merged recursively; scalar values and most list
     * values from the override replace those in the base. The two list keys in
     * {@see self::UNION_LIST_KEYS} (`components.objects[]` seeds and a register's
     * `schemas[]` membership) are instead additively unioned so concurrent
     * fragments accumulate rather than clobber one another (ADR-037).
     *
     * @param array $base     The base configuration array.
     * @param array $override The fragment to merge on top of the base.
     *
     * @return array The deep-merged result.
     */
    private static function deepMergeConfig(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            $base[$key] = self::mergeValue(key: $key, baseValue: ($base[$key] ?? null), overrideValue: $value);
        }

        return $base;
    }//end deepMergeConfig()

    /**
     * Merge a single key's override value onto its base value.
     *
     * Returns the unioned list for {@see self::UNION_LIST_KEYS}, the recursively
     * merged map for two associative arrays, and the override value otherwise.
     *
     * @param int|string $key           The key being merged.
     * @param mixed      $baseValue     The base value at this key (null when absent).
     * @param mixed      $overrideValue The override value at this key.
     *
     * @return mixed The merged value.
     */
    private static function mergeValue(int|string $key, mixed $baseValue, mixed $overrideValue): mixed
    {
        if (is_array($overrideValue) === false || is_array($baseValue) === false) {
            return $overrideValue;
        }

        $bothLists = (self::isList(value: $overrideValue) === true && self::isList(value: $baseValue) === true);

        if ($bothLists === true && in_array($key, self::UNION_LIST_KEYS, true) === true) {
            return self::unionLists(base: $baseValue, override: $overrideValue);
        }

        if ($bothLists === false
            && self::isList(value: $overrideValue) === false
            && self::isList(value: $baseValue) === false
        ) {
            return self::deepMergeConfig(base: $baseValue, override: $overrideValue);
        }

        return $overrideValue;
    }//end mergeValue()

    /**
     * Additively union two lists, preserving base order and dropping duplicates.
     *
     * Scalar duplicates (e.g. a schema slug already present in the register's
     * membership) are skipped. Non-scalar entries (e.g. seed object maps) are
     * appended as-is since they have no cheap identity to compare on; fragment
     * authors give each seed a unique slug, so this never produces collisions in
     * practice.
     *
     * @param array $base     The base list.
     * @param array $override The fragment list to union onto the base.
     *
     * @return array The unioned list.
     */
    private static function unionLists(array $base, array $override): array
    {
        $result = $base;
        foreach ($override as $value) {
            if (is_scalar($value) === true && in_array($value, $result, true) === true) {
                continue;
            }

            $result[] = $value;
        }//end foreach

        return $result;
    }//end unionLists()

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

        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, (count($value) - 1));
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
