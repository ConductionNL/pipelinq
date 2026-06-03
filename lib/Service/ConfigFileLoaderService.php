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
     * Configuration list keys that fragments contribute to *additively*.
     *
     * The default merge semantics replace a list value wholesale (ADR-037), but
     * a register's `schemas[]` membership list and the top-level seed
     * `components.objects[]` list are *collections* that every fragment extends.
     * Replacing them would drop the monolith's own schemas/seed objects (and any
     * earlier fragment's contributions) the moment a single fragment touched the
     * key. These keys are therefore union-merged: the fragment's entries are
     * appended to the base, with scalar membership entries de-duplicated.
     *
     * @var array<int, string>
     */
    private const MERGE_UNION_LIST_KEYS = [
        'schemas',
        'objects',
    ];

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
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
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

        if ($fragmentBlob !== '') {
            $baseVersion  = ($data['info']['version'] ?? '0.0.0');
            $fragmentHash = substr(hash('sha256', $fragmentBlob), 0, 8);
            if (isset($data['info']) === false || is_array($data['info']) === false) {
                $data['info'] = [];
            }

            $data['info']['version'] = $baseVersion.'+frag.'.$fragmentHash;
        }

        return $data;
    }//end mergeRegisterFragments()

    /**
     * Recursively deep-merge an override array onto a base array.
     *
     * Associative keys are merged recursively; scalar and list values from the
     * override replace those in the base. This mirrors the fragment-merge
     * semantics shared across the fleet (ADR-037).
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
                value: $value,
                base: ($base[$key] ?? null)
            );
        }

        return $base;
    }//end deepMergeConfig()

    /**
     * Resolve the merged value for a single key.
     *
     * Two nested arrays are union-merged when the key is a recognised collection
     * list (register schemas[] membership, seed components.objects[]) so that
     * fragments append rather than replace (ADR-037); two associative arrays are
     * deep-merged recursively; anything else takes the override value.
     *
     * @param string $key   The configuration key.
     * @param mixed  $value The override value for the key.
     * @param mixed  $base  The existing base value (or null when absent).
     *
     * @return mixed The merged value.
     */
    private static function mergeValue(string $key, mixed $value, mixed $base): mixed
    {
        if (is_array($value) === false || is_array($base) === false) {
            return $value;
        }

        $valueIsList = self::isList(value: $value);
        if ($valueIsList !== self::isList(value: $base)) {
            return $value;
        }

        if ($valueIsList === true) {
            if (in_array($key, self::MERGE_UNION_LIST_KEYS, true) === true) {
                return self::unionLists(base: $base, override: $value);
            }

            return $value;
        }

        return self::deepMergeConfig(base: $base, override: $value);
    }//end mergeValue()

    /**
     * Append override list entries to a base list, de-duplicating scalars.
     *
     * Scalar entries (e.g. schema-slug membership strings) are appended only
     * when not already present, so a fragment re-declaring an existing schema
     * slug does not create a duplicate. Non-scalar entries (e.g. seed object
     * arrays) are always appended, since they are distinct records.
     *
     * @param array $base     The base list.
     * @param array $override The fragment list to union onto the base.
     *
     * @return array The unioned list.
     */
    private static function unionLists(array $base, array $override): array
    {
        foreach ($override as $entry) {
            if (is_scalar($entry) === true && in_array($entry, $base, true) === true) {
                continue;
            }

            $base[] = $entry;
        }

        return array_values($base);
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
