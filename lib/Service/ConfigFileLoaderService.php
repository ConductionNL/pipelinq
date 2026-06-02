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
 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-002
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
            $fragmentContent = $this->readFragmentFile(fragmentFile: $fragmentFile);
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
     * Read a fragment file's raw contents.
     *
     * @param string $fragmentFile The fragment file path.
     *
     * @return string The file contents.
     *
     * @throws RuntimeException If the file cannot be read.
     */
    private function readFragmentFile(string $fragmentFile): string
    {
        $fragmentContent = file_get_contents($fragmentFile);
        if ($fragmentContent === false) {
            throw new RuntimeException("Failed to read register fragment: {$fragmentFile}");
        }

        return $fragmentContent;
    }//end readFragmentFile()

    /**
     * Decode a fragment's JSON contents.
     *
     * @param string $fragmentFile The fragment file path (for error context).
     * @param string $content      The raw JSON contents.
     *
     * @return mixed The decoded value.
     *
     * @throws RuntimeException If the JSON is invalid.
     */
    private function decodeFragment(string $fragmentFile, string $content): mixed
    {
        $fragmentData = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException(
                "Invalid JSON in register fragment {$fragmentFile}: ".json_last_error_msg()
            );
        }

        return $fragmentData;
    }//end decodeFragment()

    /**
     * Fold a hash of the merged fragment content into `info.version` so
     * OpenRegister's version-gated import re-runs whenever a fragment changes.
     *
     * @param array  $data         The merged configuration data.
     * @param string $fragmentBlob The concatenated fragment content (empty = no-op).
     *
     * @return array The data with a fragment-aware version.
     */
    private function foldFragmentVersion(array $data, string $fragmentBlob): array
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
     * Keys whose list values are *additively unioned* across fragments rather
     * than replaced (ADR-037). These are the two OpenRegister membership/seed
     * lists a feature fragment legitimately extends:
     *
     *   - `schemas`  — a register's schema-membership list (list of slugs). A
     *     fragment that introduces a new schema (e.g. `paymentProvider`) must be
     *     able to add it to the register's `schemas[]` without dropping the 30+
     *     schemas the monolith already declares.
     *   - `objects`  — `components.objects[]`, the seed-object list. A fragment
     *     seeds its own objects; replacing the list would wipe every seed the
     *     monolith (and other fragments) contribute.
     *
     * For every other list/scalar key the override still *replaces* the base
     * value, preserving the original fleet semantics for ordinary config.
     *
     * @var array<int, string>
     */
    private const ADDITIVE_LIST_KEYS = ['schemas', 'objects'];

    /**
     * Recursively deep-merge an override array onto a base array.
     *
     * Associative keys are merged recursively; scalar and list values from the
     * override replace those in the base, EXCEPT for the {@see self::ADDITIVE_LIST_KEYS}
     * register membership / seed lists, which are additively unioned so a feature
     * fragment can extend the register's `schemas[]` and add its own
     * `components.objects[]` seeds without clobbering what the monolith (or a
     * sibling fragment) already contributes. This mirrors the fragment-merge
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
            $baseValue = ($base[$key] ?? null);

            if (self::isAssocMerge(baseValue: $baseValue, value: $value) === true) {
                $base[$key] = self::deepMergeConfig(base: $baseValue, override: $value);
                continue;
            }

            if (self::isUnionMerge(key: $key, baseValue: $baseValue, value: $value) === true) {
                $base[$key] = self::unionList(base: $baseValue, override: $value);
                continue;
            }

            $base[$key] = $value;
        }//end foreach

        return $base;
    }//end deepMergeConfig()

    /**
     * Whether two values should be recursively associative-merged.
     *
     * @param mixed $baseValue The current base value at the key.
     * @param mixed $value     The override value.
     *
     * @return bool True when both are associative arrays.
     */
    private static function isAssocMerge(mixed $baseValue, mixed $value): bool
    {
        return (is_array($value) === true
            && is_array($baseValue) === true
            && self::isList(value: $value) === false
            && self::isList(value: $baseValue) === false);
    }//end isAssocMerge()

    /**
     * Whether two list values at a key should be additively unioned (ADR-037).
     *
     * @param string $key       The config key.
     * @param mixed  $baseValue The current base value at the key.
     * @param mixed  $value     The override value.
     *
     * @return bool True when the key is union-eligible and both sides are lists.
     */
    private static function isUnionMerge(string $key, mixed $baseValue, mixed $value): bool
    {
        return (in_array($key, self::ADDITIVE_LIST_KEYS, true) === true
            && is_array($value) === true
            && is_array($baseValue) === true
            && self::isList(value: $value) === true
            && self::isList(value: $baseValue) === true);
    }//end isUnionMerge()

    /**
     * Additively union two lists, de-duplicating by a stable identity.
     *
     * Schema-membership entries are plain strings, deduped by value. Seed
     * objects are associative arrays, deduped by their `@self.slug` (the
     * idempotent re-import key) when present, falling back to a JSON hash of the
     * whole entry. Base entries are kept in order; override entries that are not
     * already present are appended. This makes a re-import idempotent: dropping
     * the same fragment twice never duplicates a schema membership or a seed.
     *
     * @param array<int, mixed> $base     The base list.
     * @param array<int, mixed> $override The fragment list.
     *
     * @return array<int, mixed> The unioned list.
     */
    private static function unionList(array $base, array $override): array
    {
        $seen   = [];
        $result = [];

        foreach (array_merge($base, $override) as $entry) {
            $identity = self::listEntryIdentity(entry: $entry);
            if (isset($seen[$identity]) === true) {
                continue;
            }

            $seen[$identity] = true;
            $result[]        = $entry;
        }

        return $result;
    }//end unionList()

    /**
     * Derive a stable identity string for a union-list entry.
     *
     * @param mixed $entry The list entry (string slug or seed-object array).
     *
     * @return string The identity used for de-duplication.
     */
    private static function listEntryIdentity(mixed $entry): string
    {
        if (is_string($entry) === true) {
            return 's:'.$entry;
        }

        if (is_array($entry) === true) {
            $slug = ($entry['@self']['slug'] ?? null);
            if (is_string($slug) === true && $slug !== '') {
                return 'o:'.$slug;
            }

            return 'h:'.md5((string) json_encode($entry));
        }

        return 'v:'.md5((string) json_encode($entry));
    }//end listEntryIdentity()

    /**
     * Determine whether an array is a sequential list (zero-indexed, no gaps).
     *
     * @param array $value The array to inspect.
     *
     * @return bool True when the array is a sequential list.
     */
    private static function isList(array $value): bool
    {
        return array_is_list($value);
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
