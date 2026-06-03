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
 * @spec openspec/changes/reverse-2026-05-26-be-settings/tasks.md#task-10
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
            $fragmentData    = $this->decodeFragment(fragmentFile: $fragmentFile, fragmentContent: $fragmentContent);
            if (is_array($fragmentData) === false) {
                continue;
            }

            $data          = self::deepMergeConfig(base: $data, override: $fragmentData);
            $fragmentBlob .= $fragmentContent;
        }//end foreach

        return $this->stampFragmentVersion(data: $data, fragmentBlob: $fragmentBlob);
    }//end mergeRegisterFragments()

    /**
     * Read a fragment file's raw content.
     *
     * @param string $fragmentFile The absolute fragment path.
     *
     * @return string The file content.
     *
     * @throws RuntimeException If the file cannot be read.
     */
    private function readFragment(string $fragmentFile): string
    {
        $fragmentContent = file_get_contents($fragmentFile);
        if ($fragmentContent === false) {
            throw new RuntimeException("Failed to read register fragment: {$fragmentFile}");
        }

        return $fragmentContent;
    }//end readFragment()

    /**
     * Decode a fragment file's JSON content.
     *
     * @param string $fragmentFile    The absolute fragment path (for messages).
     * @param string $fragmentContent The raw JSON content.
     *
     * @return mixed The decoded value.
     *
     * @throws RuntimeException If the JSON is invalid.
     */
    private function decodeFragment(string $fragmentFile, string $fragmentContent): mixed
    {
        $fragmentData = json_decode($fragmentContent, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException(
                "Invalid JSON in register fragment {$fragmentFile}: ".json_last_error_msg()
            );
        }

        return $fragmentData;
    }//end decodeFragment()

    /**
     * Fold a short hash of all fragment content into `info.version`.
     *
     * @param array  $data         The merged configuration data.
     * @param string $fragmentBlob The concatenated fragment content.
     *
     * @return array The data with a fragment-stamped version.
     */
    private function stampFragmentVersion(array $data, string $fragmentBlob): array
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
     * Keys whose list values are additively unioned across fragments rather
     * than replaced.
     *
     * The default fragment-merge replaces a list value wholesale (a fragment's
     * list wins). That is wrong for the two membership lists that several
     * fragments legitimately contribute to: a register's `schemas` slug
     * membership and the seed `objects` array. Replacing either would let one
     * fragment silently drop the schemas/seeds added by the monolith or an
     * earlier-sorted fragment. For these keys the base and override lists are
     * concatenated and de-duplicated instead, so every fragment's contribution
     * survives (ADR-037, fleet-standard additive union).
     *
     * @var array<int, string>
     */
    private const ADDITIVE_UNION_KEYS = ['schemas', 'objects'];

    /**
     * Recursively deep-merge an override array onto a base array.
     *
     * Associative keys are merged recursively; scalar and list values from the
     * override replace those in the base, except for the additive-union
     * membership lists ({@see self::ADDITIVE_UNION_KEYS}) which are concatenated
     * and de-duplicated so concurrent fragments never clobber each other's
     * register-schema membership or seed objects. This mirrors the
     * fragment-merge semantics shared across the fleet (ADR-037).
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

            if (self::shouldRecurse(value: $value, current: $current) === true) {
                $base[$key] = self::deepMergeConfig(base: $current, override: $value);
                continue;
            }

            if (self::shouldUnion(key: $key, value: $value, current: $current) === true) {
                $base[$key] = self::unionLists(base: $current, override: $value);
                continue;
            }

            $base[$key] = $value;
        }//end foreach

        return $base;
    }//end deepMergeConfig()

    /**
     * Whether two associative arrays at a key should be merged recursively.
     *
     * @param mixed $value   The override value.
     * @param mixed $current The base value at the same key.
     *
     * @return bool True when both are associative (non-list) arrays.
     */
    private static function shouldRecurse(mixed $value, mixed $current): bool
    {
        return (is_array($value) === true
            && is_array($current) === true
            && self::isList(value: $value) === false
            && self::isList(value: $current) === false);
    }//end shouldRecurse()

    /**
     * Whether two lists at an additive-union key should be unioned.
     *
     * @param int|string $key     The merge key.
     * @param mixed      $value   The override value.
     * @param mixed      $current The base value at the same key.
     *
     * @return bool True when both are lists at a membership key.
     */
    private static function shouldUnion(int|string $key, mixed $value, mixed $current): bool
    {
        return (in_array($key, self::ADDITIVE_UNION_KEYS, true) === true
            && is_array($value) === true
            && is_array($current) === true
            && self::isList(value: $value) === true
            && self::isList(value: $current) === true);
    }//end shouldUnion()

    /**
     * Concatenate two lists, de-duplicating scalar members.
     *
     * Used for the additive-union membership keys. Scalar entries (e.g. schema
     * slug strings) that already exist in the base are not appended again;
     * non-scalar entries (e.g. seed object arrays) are always appended because
     * value identity is not meaningful for them here.
     *
     * @param array $base     The base list.
     * @param array $override The fragment list to union on top.
     *
     * @return array The unioned list.
     */
    private static function unionLists(array $base, array $override): array
    {
        $result = $base;
        foreach ($override as $item) {
            if (is_scalar($item) === true && in_array($item, $result, true) === true) {
                continue;
            }

            $result[] = $item;
        }

        return array_values($result);
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
