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
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The class is a small, cohesive
 *  ADR-037 fragment loader (read monolith + deep-merge fragments with the
 *  additive-union rule for register schemas[] and seed objects[]); the complexity
 *  is the merge recursion, not unrelated responsibilities.
 *
 * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#1.1
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
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Linear fragment-read loop with
     *  the required validation guards (missing dir, read failure, JSON error,
     *  non-array) — each branch is a distinct, necessary failure mode.
     * @SuppressWarnings(PHPMD.NPathComplexity)      Same: the branch count is the
     *  product of independent validation guards, not nested business logic.
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
     * Dot-paths whose list values are additively unioned (not replaced) when a
     * fragment contributes them.
     *
     * The fleet-standard ADR-037 rule: a fragment that adds a new schema to the
     * register's membership list, or contributes seed objects, must *extend* the
     * monolith's list rather than replace it — otherwise dropping one fragment
     * would silently drop every schema/seed the monolith (or an earlier fragment)
     * already declared. `*` matches a single path segment (the register key).
     *
     * @var string[]
     */
    private const UNION_LIST_PATHS = [
        'components.registers.*.schemas',
        'components.objects',
    ];

    /**
     * Recursively deep-merge an override array onto a base array.
     *
     * Associative keys are merged recursively; scalar values and most list
     * values from the override replace those in the base. The exception is the
     * ADR-037 union paths (register `schemas[]` membership and `components.objects[]`
     * seeds), whose lists are additively unioned (de-duplicated) so concurrent
     * fragments and the monolith all contribute. This mirrors the fragment-merge
     * semantics shared across the fleet (ADR-037).
     *
     * @param array  $base     The base configuration array.
     * @param array  $override The fragment to merge on top of the base.
     * @param string $path     The dot-path of the current node (internal).
     *
     * @return array The deep-merged result.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) The two guarded branches
     *  (union-list path vs. recursive associative merge vs. scalar replace) are
     *  the three distinct, necessary merge cases; collapsing them would lose the
     *  ADR-037 additive-union semantics.
     */
    private static function deepMergeConfig(array $base, array $override, string $path=''): array
    {
        foreach ($override as $key => $value) {
            $childPath = $path.'.'.$key;
            if ($path === '') {
                $childPath = (string) $key;
            }

            if (is_array($value) === true
                && isset($base[$key]) === true
                && is_array($base[$key]) === true
                && self::isList(value: $value) === true
                && self::isList(value: $base[$key]) === true
                && self::isUnionListPath(path: $childPath) === true
            ) {
                $base[$key] = self::unionLists(base: $base[$key], override: $value);
                continue;
            }

            if (is_array($value) === true
                && isset($base[$key]) === true
                && is_array($base[$key]) === true
                && self::isList(value: $value) === false
                && self::isList(value: $base[$key]) === false
            ) {
                $base[$key] = self::deepMergeConfig(base: $base[$key], override: $value, path: $childPath);
                continue;
            }

            $base[$key] = $value;
        }//end foreach

        return $base;
    }//end deepMergeConfig()

    /**
     * Determine whether a concrete dot-path matches a configured union-list path.
     *
     * A single `*` segment in the pattern matches exactly one path segment (the
     * register key), so `components.registers.pipelinq.schemas` matches the
     * `components.registers.*.schemas` pattern.
     *
     * @param string $path The concrete dot-path to test.
     *
     * @return bool True when the path is an additive-union list path.
     */
    private static function isUnionListPath(string $path): bool
    {
        $parts = explode('.', $path);
        foreach (self::UNION_LIST_PATHS as $pattern) {
            $patternParts = explode('.', $pattern);
            if (count($patternParts) !== count($parts)) {
                continue;
            }

            $matches = true;
            foreach ($patternParts as $index => $patternPart) {
                if ($patternPart !== '*' && $patternPart !== $parts[$index]) {
                    $matches = false;
                    break;
                }
            }

            if ($matches === true) {
                return true;
            }
        }//end foreach

        return false;
    }//end isUnionListPath()

    /**
     * Additively union two lists, de-duplicating by value.
     *
     * Scalar members are compared directly; associative members (e.g. seed
     * objects) are compared by their canonical JSON encoding so an identical seed
     * present in both the monolith and a fragment is not duplicated. Order is
     * stable: base members first, then new override members.
     *
     * @param array $base     The base list.
     * @param array $override The override list to union in.
     *
     * @return array The de-duplicated union.
     */
    private static function unionLists(array $base, array $override): array
    {
        $result = $base;
        $seen   = [];
        foreach ($base as $member) {
            $seen[self::memberKey(member: $member)] = true;
        }

        foreach ($override as $member) {
            $memberKey = self::memberKey(member: $member);
            if (isset($seen[$memberKey]) === true) {
                continue;
            }

            $seen[$memberKey] = true;
            $result[]         = $member;
        }

        return $result;
    }//end unionLists()

    /**
     * Build a stable de-duplication key for a list member.
     *
     * @param mixed $member The list member (scalar or array).
     *
     * @return string The canonical key.
     */
    private static function memberKey(mixed $member): string
    {
        if (is_scalar($member) === true) {
            return (string) $member;
        }

        $encoded = json_encode($member);
        if ($encoded === false) {
            return serialize($member);
        }

        return $encoded;
    }//end memberKey()

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
