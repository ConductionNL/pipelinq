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
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The class is the single
 *  owner of the ADR-037 fragment-merge algorithm: file discovery + parse, the
 *  recursive deep-merge, and the additive-union special cases (seed objects by
 *  `@self` identity, register schema membership). These are many small,
 *  single-purpose, individually unit-tested helpers whose cohesion is
 *  intentional; splitting them across classes would scatter one merge concern
 *  without reducing real complexity.
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
     * Read a fragment file's raw content, failing loudly on an I/O error.
     *
     * @param string $fragmentFile The absolute fragment path.
     *
     * @return string The file content.
     *
     * @throws RuntimeException If the file cannot be read.
     */
    private function readFragmentFile(string $fragmentFile): string
    {
        $content = file_get_contents($fragmentFile);
        if ($content === false) {
            throw new RuntimeException("Failed to read register fragment: {$fragmentFile}");
        }

        return $content;
    }//end readFragmentFile()

    /**
     * Decode a fragment's JSON content, failing loudly on malformed JSON.
     *
     * @param string $fragmentFile The fragment path (for the error message).
     * @param string $content      The raw JSON content.
     *
     * @return mixed The decoded data (typically an array).
     *
     * @throws RuntimeException If the JSON is invalid.
     */
    private function decodeFragment(string $fragmentFile, string $content): mixed
    {
        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException(
                "Invalid JSON in register fragment {$fragmentFile}: ".json_last_error_msg()
            );
        }

        return $data;
    }//end decodeFragment()

    /**
     * Public, side-effect-free seam over {@see self::deepMergeConfig()}.
     *
     * Exposes the fleet-standard ADR-037 merge semantics (recursive object
     * merge + additive union of `components.objects[]` by `@self` identity and
     * register `schemas[]` membership) for unit testing and for callers that
     * need to merge two already-parsed register fragments without touching the
     * filesystem.
     *
     * @param array $base     The base configuration array.
     * @param array $override The fragment to merge on top of the base.
     *
     * @return array The deep-merged result.
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#1
     */
    public static function mergeConfig(array $base, array $override): array
    {
        return self::deepMergeConfig(base: $base, override: $override);
    }//end mergeConfig()

    /**
     * Recursively deep-merge an override array onto a base array.
     *
     * Associative keys are merged recursively; scalar and most list values from
     * the override replace those in the base. Two list keys are special-cased to
     * **union additively** instead of replacing, so a fragment can contribute new
     * seed objects / schema memberships without wiping the monolith's
     * (ADR-037, fleet-standard):
     *
     * - `components.objects[]` — seed objects are unioned, de-duplicated by
     *   `@self.register` + `@self.schema` + `@self.slug` (the fragment object
     *   wins on a collision so a fragment can re-seed an existing slug).
     * - a register's `schemas[]` — schema-membership entries (scalar slugs) are
     *   unioned, de-duplicated by value, preserving base order then appending
     *   any new fragment entries.
     *
     * Without this, a fragment that declares `components.objects` or a register's
     * `schemas` array would replace the monolith's list (the prior list-replace
     * rule), silently dropping every existing seed object / schema membership.
     *
     * @param array $base     The base configuration array.
     * @param array $override The fragment to merge on top of the base.
     * @param array $path     The current key path (internal recursion context).
     *
     * @return array The deep-merged result.
     */
    private static function deepMergeConfig(array $base, array $override, array $path=[]): array
    {
        foreach ($override as $key => $value) {
            $base[$key] = self::mergeValue(
                existing: ($base[$key] ?? null),
                hasExisting: array_key_exists($key, $base),
                value: $value,
                path: array_merge($path, [$key])
            );
        }

        return $base;
    }//end deepMergeConfig()

    /**
     * Merge a single override value onto its base counterpart at $path.
     *
     * Two same-shaped lists at a recognised special path union additively (seed
     * objects by `@self` identity, register schemas by value); two associative
     * arrays merge recursively; anything else replaces the base value.
     *
     * @param mixed $existing    The base value at this key (or null).
     * @param bool  $hasExisting Whether the base actually had this key.
     * @param mixed $value       The override value.
     * @param array $path        The key path of this value.
     *
     * @return mixed The merged value.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $hasExisting distinguishes a
     *  present null from an absent key for the merge decision.
     */
    private static function mergeValue(mixed $existing, bool $hasExisting, mixed $value, array $path): mixed
    {
        if ($hasExisting === false || is_array($value) === false || is_array($existing) === false) {
            return $value;
        }

        $valueIsList    = self::isList(value: $value);
        $existingIsList = self::isList(value: $existing);

        if ($valueIsList === true && $existingIsList === true) {
            return self::mergeLists(existing: $existing, value: $value, path: $path);
        }

        if ($valueIsList === false && $existingIsList === false) {
            return self::deepMergeConfig(base: $existing, override: $value, path: $path);
        }

        return $value;
    }//end mergeValue()

    /**
     * Merge two same-shaped lists, applying the special additive-union paths.
     *
     * @param array $existing The base list.
     * @param array $value    The override list.
     * @param array $path      The key path of the list.
     *
     * @return array The merged list (override replaces unless a special path).
     */
    private static function mergeLists(array $existing, array $value, array $path): array
    {
        if (self::isObjectsListPath(path: $path) === true) {
            return self::unionObjectsBySelfSlug(base: $existing, override: $value);
        }

        if (self::isSchemaMembershipPath(path: $path) === true) {
            return self::unionScalarList(base: $existing, override: $value);
        }

        return $value;
    }//end mergeLists()

    /**
     * Whether a key path points at the top-level `components.objects` seed list.
     *
     * @param array $path The key path being merged.
     *
     * @return bool True for `components.objects`.
     */
    private static function isObjectsListPath(array $path): bool
    {
        return $path === ['components', 'objects'];
    }//end isObjectsListPath()

    /**
     * Whether a key path points at a register's `schemas` membership list.
     *
     * Matches `components.registers.<anyRegisterSlug>.schemas`.
     *
     * @param array $path The key path being merged.
     *
     * @return bool True for a register schema-membership list.
     */
    private static function isSchemaMembershipPath(array $path): bool
    {
        return count($path) === 4
            && $path[0] === 'components'
            && $path[1] === 'registers'
            && $path[3] === 'schemas';
    }//end isSchemaMembershipPath()

    /**
     * Union two seed-object lists, de-duplicating by the `@self` identity.
     *
     * The identity is the tuple (register, schema, slug) read from each object's
     * `@self` envelope. Base objects are kept in order; a fragment object with a
     * matching identity replaces the base one (so a fragment can re-seed an
     * existing slug); fragment objects with a new identity are appended. Objects
     * without a resolvable `@self.slug` are always appended (never deduped).
     *
     * @param array<int, mixed> $base     The base object list.
     * @param array<int, mixed> $override The fragment object list.
     *
     * @return array<int, mixed> The unioned object list.
     */
    private static function unionObjectsBySelfSlug(array $base, array $override): array
    {
        $byKey  = [];
        $result = [];

        foreach ($base as $object) {
            $key = self::selfIdentity(object: $object);
            if ($key === null) {
                $result[] = $object;
                continue;
            }

            $byKey[$key] = count($result);
            $result[]    = $object;
        }

        foreach ($override as $object) {
            $key = self::selfIdentity(object: $object);
            if ($key === null) {
                $result[] = $object;
                continue;
            }

            if (isset($byKey[$key]) === true) {
                $result[$byKey[$key]] = $object;
                continue;
            }

            $byKey[$key] = count($result);
            $result[]    = $object;
        }

        return array_values($result);
    }//end unionObjectsBySelfSlug()

    /**
     * Build the de-duplication identity for a seed object from its `@self`.
     *
     * @param mixed $object The seed object.
     *
     * @return string|null The "register|schema|slug" identity, or null when no
     *                     slug is resolvable.
     */
    private static function selfIdentity(mixed $object): ?string
    {
        if (is_array($object) === false) {
            return null;
        }

        $self = ($object['@self'] ?? null);
        if (is_array($self) === false) {
            return null;
        }

        $slug = (string) ($self['slug'] ?? '');
        if ($slug === '') {
            return null;
        }

        $register = (string) ($self['register'] ?? '');
        $schema   = (string) ($self['schema'] ?? '');

        return $register.'|'.$schema.'|'.$slug;
    }//end selfIdentity()

    /**
     * Union two scalar lists preserving base order, appending new values.
     *
     * @param array<int, mixed> $base     The base list.
     * @param array<int, mixed> $override The fragment list.
     *
     * @return array<int, mixed> The unioned list (de-duplicated by value).
     */
    private static function unionScalarList(array $base, array $override): array
    {
        $result = $base;
        foreach ($override as $value) {
            if (in_array($value, $result, true) === false) {
                $result[] = $value;
            }
        }

        return array_values($result);
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
