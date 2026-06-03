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
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-3.1
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
            $fragmentData    = $this->decodeFragment(fragmentFile: $fragmentFile, fragmentContent: $fragmentContent);
            if (is_array($fragmentData) === false) {
                continue;
            }

            $data          = self::deepMergeConfig(base: $data, override: $fragmentData);
            $fragmentBlob .= $fragmentContent;
        }//end foreach

        return $this->foldFragmentVersion(data: $data, fragmentBlob: $fragmentBlob);
    }//end mergeRegisterFragments()

    /**
     * Read a fragment file, throwing on read failure.
     *
     * @param string $fragmentFile The absolute fragment path.
     *
     * @return string The raw file contents.
     *
     * @throws RuntimeException When the file cannot be read.
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
     * Decode a fragment's JSON, throwing on a parse error.
     *
     * @param string $fragmentFile    The fragment path (for error messages).
     * @param string $fragmentContent The raw JSON content.
     *
     * @return mixed The decoded value (an array for a valid fragment).
     *
     * @throws RuntimeException When the JSON is invalid.
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
     * Fold a short hash of all fragment content into `info.version` (ADR-037).
     *
     * @param array  $data         The merged configuration.
     * @param string $fragmentBlob The concatenated fragment content.
     *
     * @return array The configuration with a fragment-aware version.
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
     * Recursively deep-merge an override array onto a base array.
     *
     * Associative keys are merged recursively; scalar and list values from the
     * override replace those in the base. This mirrors the fragment-merge
     * semantics shared across the fleet (ADR-037).
     *
     * Two list paths are an exception and are **additively unioned** rather than
     * replaced, so a feature fragment can extend them without clobbering the
     * monolith's contributions (and without conflicting with sibling fragments):
     *
     * - `components.objects` — the seed-object list. Entries are unioned and
     *   deduplicated by their `@self.slug` (override wins on a slug clash).
     * - `components.registers.<reg>.schemas` — a register's schema-membership
     *   list. String slugs are unioned and deduplicated, preserving order.
     *
     * Without this rule a fragment that adds three schemas to the register or a
     * handful of seed objects would silently drop the 30+ schemas and 39 seeds
     * already declared in the monolith, because list values otherwise replace.
     *
     * @param array  $base     The base configuration array.
     * @param array  $override The fragment to merge on top of the base.
     * @param string $path     Dot-path of the current node (internal recursion
     *                         bookkeeping; callers pass '').
     *
     * @return array The deep-merged result.
     */
    private static function deepMergeConfig(array $base, array $override, string $path=''): array
    {
        foreach ($override as $key => $value) {
            $childPath = $path.'.'.$key;
            if ($path === '') {
                $childPath = (string) $key;
            }

            $base[$key] = self::mergeValue(
                baseValue: ($base[$key] ?? null),
                overrideValue: $value,
                hasBase: array_key_exists($key, $base),
                childPath: $childPath
            );
        }//end foreach

        return $base;
    }//end deepMergeConfig()

    /**
     * Compute the merged value for a single key (ADR-037 merge rules).
     *
     * - Two union-path lists are additively unioned.
     * - Two associative maps are deep-merged recursively.
     * - Everything else (scalars, non-union lists) is replaced by the override.
     *
     * @param mixed  $baseValue     The base value (null when the key is absent).
     * @param mixed  $overrideValue The override value.
     * @param bool   $hasBase       Whether the base actually had this key.
     * @param string $childPath     The dot-path of this key.
     *
     * @return mixed The merged value.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) — $hasBase distinguishes a present null from an absent key
     */
    private static function mergeValue(mixed $baseValue, mixed $overrideValue, bool $hasBase, string $childPath): mixed
    {
        $bothArrays = ($hasBase === true && is_array($baseValue) === true && is_array($overrideValue) === true);
        if ($bothArrays === false) {
            return $overrideValue;
        }

        $bothLists = (self::isList(value: $baseValue) === true && self::isList(value: $overrideValue) === true);
        if ($bothLists === true) {
            if (self::isUnionListPath(path: $childPath) === true) {
                return self::unionSeedList(base: $baseValue, override: $overrideValue);
            }

            return $overrideValue;
        }

        if (self::isList(value: $baseValue) === false && self::isList(value: $overrideValue) === false) {
            return self::deepMergeConfig(base: $baseValue, override: $overrideValue, path: $childPath);
        }

        return $overrideValue;
    }//end mergeValue()

    /**
     * Determine whether a dot-path is an additively-unioned list (ADR-037).
     *
     * Matches `components.objects` (seed objects) and any register's
     * `components.registers.<reg>.schemas` membership list, regardless of the
     * register slug in the path.
     *
     * @param string $path The dot-path of the list node.
     *
     * @return bool True when the list at this path must be unioned, not replaced.
     */
    private static function isUnionListPath(string $path): bool
    {
        if ($path === 'components.objects') {
            return true;
        }

        return (bool) preg_match('/^components\.registers\.[^.]+\.schemas$/', $path);
    }//end isUnionListPath()

    /**
     * Additively union two register/seed lists, deduplicating by identity.
     *
     * Scalar (string slug) entries are matched by value; associative entries
     * (seed objects) are matched by their `@self.slug`. An override entry with
     * the same identity as a base entry replaces that base entry in place;
     * otherwise it is appended. Order is base-first, then new override entries.
     *
     * @param array $base     The base list.
     * @param array $override The override list to fold in.
     *
     * @return array The unioned list.
     */
    private static function unionSeedList(array $base, array $override): array
    {
        $indexByIdentity = [];
        foreach ($base as $index => $entry) {
            $identity = self::seedIdentity(entry: $entry);
            if ($identity !== null) {
                $indexByIdentity[$identity] = $index;
            }
        }

        foreach ($override as $entry) {
            $identity = self::seedIdentity(entry: $entry);
            if ($identity !== null && isset($indexByIdentity[$identity]) === true) {
                $base[$indexByIdentity[$identity]] = $entry;
                continue;
            }

            $base[] = $entry;
            if ($identity !== null) {
                $indexByIdentity[$identity] = (array_key_last($base));
            }
        }//end foreach

        return array_values($base);
    }//end unionSeedList()

    /**
     * Derive a stable identity for a union-list entry.
     *
     * String entries (schema-membership slugs) identify by their own value;
     * seed objects identify by `@self.slug`. Entries without a derivable
     * identity return null and are always appended (never deduplicated).
     *
     * @param mixed $entry The list entry.
     *
     * @return string|null The identity, or null when none can be derived.
     */
    private static function seedIdentity(mixed $entry): ?string
    {
        if (is_string($entry) === true) {
            return $entry;
        }

        if (is_array($entry) === true && isset($entry['@self']['slug']) === true
            && is_string($entry['@self']['slug']) === true
        ) {
            return '@self:'.$entry['@self']['slug'];
        }

        return null;
    }//end seedIdentity()

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
