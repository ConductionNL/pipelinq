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
 * The fragment deep-merge + seed-object union logic (ADR-037) is deliberately
 * decomposed into many small single-responsibility helpers. This keeps every
 * individual method well within the cyclomatic threshold, but the sum of those
 * methods raises the class-level WeightedMethodCount just past the default;
 * the per-method clarity is the desired trade-off, so the class-level metric
 * is suppressed (the methods themselves are NOT suppressed).
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-64
 * @spec openspec/changes/kcc-werkplek/tasks.md#task-1
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
            $fragmentContent = self::readFragment(fragmentFile: $fragmentFile);

            $fragmentData = $this->decodeFragment(fragmentFile: $fragmentFile, content: $fragmentContent);
            if (is_array($fragmentData) === false) {
                continue;
            }

            $data          = self::deepMergeConfig(base: $data, override: $fragmentData);
            $fragmentBlob .= $fragmentContent;
        }//end foreach

        return self::foldFragmentVersion(data: $data, fragmentBlob: $fragmentBlob);
    }//end mergeRegisterFragments()

    /**
     * Read a register fragment file's raw contents.
     *
     * @param string $fragmentFile The absolute fragment path.
     *
     * @return string The raw JSON content.
     *
     * @throws RuntimeException When the file cannot be read.
     */
    private static function readFragment(string $fragmentFile): string
    {
        $fragmentContent = file_get_contents($fragmentFile);
        if ($fragmentContent === false) {
            throw new RuntimeException("Failed to read register fragment: {$fragmentFile}");
        }

        return $fragmentContent;
    }//end readFragment()

    /**
     * Decode a register fragment's JSON content.
     *
     * @param string $fragmentFile The fragment path (for error context).
     * @param string $content      The raw JSON content.
     *
     * @return mixed The decoded value.
     *
     * @throws RuntimeException When the content is not valid JSON.
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
     * Fold a short hash of all fragment content into `info.version` so the
     * version-gated OpenRegister import re-runs whenever a fragment changes.
     *
     * @param array  $data         The merged configuration data.
     * @param string $fragmentBlob The concatenated raw fragment content.
     *
     * @return array The configuration data with the version suffix applied.
     */
    private static function foldFragmentVersion(array $data, string $fragmentBlob): array
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
     * Two lists are the documented fleet-standard exception to the
     * "lists replace" rule because fragments routinely *extend* them rather
     * than redefine them:
     *
     *   - `components.objects[]` — seed objects, unioned by their
     *     `@self` identity (`register` + `schema` + `slug`). This lets a
     *     feature fragment contribute seed objects for an existing schema
     *     without wiping the monolith's existing seeds.
     *   - a register's `schemas[]` membership list — unioned by value, so a
     *     fragment can add a schema to a register without dropping the
     *     schemas the monolith already declared.
     *
     * Every other list value from the override still replaces the base value.
     *
     * @param array           $base     The base configuration array.
     * @param array           $override The fragment to merge on top of the base.
     * @param string|int|null $key      The key being merged (for list-union detection).
     *
     * @return array The deep-merged result.
     */
    private static function deepMergeConfig(array $base, array $override, string|int|null $key=null): array
    {
        foreach ($override as $childKey => $value) {
            $base[$childKey] = self::mergeChild(
                parentKey: $key,
                childKey: $childKey,
                baseValue: ($base[$childKey] ?? null),
                overrideValue: $value
            );
        }

        return $base;
    }//end deepMergeConfig()

    /**
     * Merge a single child value into its base counterpart.
     *
     * Two same-shaped arrays are either unioned (when the key is a documented
     * union list) or deep-merged (when both are associative); every other
     * value replaces the base.
     *
     * @param string|int|null $parentKey     The key of the array that owns this child.
     * @param string|int      $childKey      The child key.
     * @param mixed           $baseValue     The existing base value (or null).
     * @param mixed           $overrideValue The override value.
     *
     * @return mixed The merged child value.
     */
    private static function mergeChild(
        string|int|null $parentKey,
        string|int $childKey,
        mixed $baseValue,
        mixed $overrideValue
    ): mixed {
        if (is_array($overrideValue) === false || is_array($baseValue) === false) {
            return $overrideValue;
        }

        $bothLists = (self::isList(value: $overrideValue) === true && self::isList(value: $baseValue) === true);
        if ($bothLists === true) {
            if (self::isUnionList(parentKey: $parentKey, listKey: $childKey) === true) {
                return self::unionList(base: $baseValue, override: $overrideValue, listKey: $childKey);
            }

            return $overrideValue;
        }

        if (self::isList(value: $overrideValue) === false && self::isList(value: $baseValue) === false) {
            return self::deepMergeConfig(base: $baseValue, override: $overrideValue, key: $childKey);
        }

        return $overrideValue;
    }//end mergeChild()

    /**
     * Decide whether a list at the given key must be additively unioned
     * rather than replaced (ADR-037 fleet-standard exception).
     *
     * @param string|int|null $parentKey The key of the array that owns this list.
     * @param string|int      $listKey   The key of the list itself.
     *
     * @return bool True when the list must be unioned, false to replace.
     */
    private static function isUnionList(string|int|null $parentKey, string|int $listKey): bool
    {
        // `components.objects[]` — seed objects.
        if ($parentKey === 'components' && $listKey === 'objects') {
            return true;
        }

        // A register's `schemas[]` membership list (under components.registers.<slug>).
        if ($listKey === 'schemas') {
            return true;
        }

        return false;
    }//end isUnionList()

    /**
     * Additively union an override list onto a base list, de-duplicating.
     *
     * Seed-object lists (`objects`) de-duplicate by `@self` identity
     * (`register` + `schema` + `slug`); an override object with the same
     * identity replaces the matching base object. All other union lists
     * (e.g. a register's `schemas` membership) de-duplicate by scalar value.
     *
     * @param array      $base     The base list.
     * @param array      $override The override list to union on top.
     * @param string|int $listKey  The key of the list (selects the strategy).
     *
     * @return array The unioned list.
     */
    private static function unionList(array $base, array $override, string|int $listKey): array
    {
        if ($listKey === 'objects') {
            return self::unionObjects(base: $base, override: $override);
        }

        return self::unionScalars(base: $base, override: $override);
    }//end unionList()

    /**
     * Union seed-object lists by `@self` identity.
     *
     * An override object whose identity already exists in the base replaces
     * the matching base object (idempotency by slug); objects without a usable
     * identity are always appended.
     *
     * @param array $base     The base object list.
     * @param array $override The override object list.
     *
     * @return array The unioned object list.
     */
    private static function unionObjects(array $base, array $override): array
    {
        $indexByIdentity = [];
        foreach ($base as $index => $object) {
            $identity = self::objectIdentity(object: $object);
            if ($identity !== null) {
                $indexByIdentity[$identity] = $index;
            }
        }

        foreach ($override as $object) {
            $identity = self::objectIdentity(object: $object);
            if ($identity !== null && isset($indexByIdentity[$identity]) === true) {
                $base[$indexByIdentity[$identity]] = $object;
                continue;
            }

            $base[] = $object;
            $indexByIdentity[$identity ?? ''] = (count($base) - 1);
        }

        return array_values($base);
    }//end unionObjects()

    /**
     * Union scalar lists (e.g. a register's schemas membership) by value.
     *
     * @param array $base     The base scalar list.
     * @param array $override The override scalar list.
     *
     * @return array The unioned scalar list.
     */
    private static function unionScalars(array $base, array $override): array
    {
        foreach ($override as $value) {
            if (in_array($value, $base, true) === false) {
                $base[] = $value;
            }
        }

        return array_values($base);
    }//end unionScalars()

    /**
     * Derive the de-duplication identity of a seed object from its `@self`.
     *
     * @param mixed $object The seed object (expected associative array).
     *
     * @return string|null The `register|schema|slug` identity, or null when
     *                     the object carries no usable `@self` envelope.
     */
    private static function objectIdentity(mixed $object): ?string
    {
        if (is_array($object) === false) {
            return null;
        }

        $self = ($object['@self'] ?? null);
        if (is_array($self) === false) {
            return null;
        }

        $slug = ($self['slug'] ?? null);
        if ($slug === null || $slug === '') {
            return null;
        }

        return ($self['register'] ?? '').'|'.($self['schema'] ?? '').'|'.$slug;
    }//end objectIdentity()

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
