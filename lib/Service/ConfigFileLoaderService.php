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
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The class owns the full
 *  ADR-037 fragment-merge pipeline (read → deep-merge → additive-union of
 *  seeds + register membership → version-fold), decomposed into small
 *  single-purpose helpers; the aggregate complexity reflects that cohesive
 *  responsibility, not tangled logic, and splitting it would scatter one
 *  config-loading concern across classes.
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
            $fragmentData    = json_decode($fragmentContent, true);
            if (is_array($fragmentData) === false) {
                continue;
            }

            $preMerge      = $data;
            $data          = self::deepMergeConfig(base: $data, override: $fragmentData);
            $data          = self::unionAdditiveLists(base: $data, before: $preMerge, fragment: $fragmentData);
            $fragmentBlob .= $fragmentContent;
        }//end foreach

        return $this->foldFragmentVersion(data: $data, fragmentBlob: $fragmentBlob);
    }//end mergeRegisterFragments()

    /**
     * Read and validate a single register-fragment file.
     *
     * @param string $fragmentFile The absolute fragment path.
     *
     * @return string The raw fragment JSON.
     *
     * @throws RuntimeException If the file cannot be read or is invalid JSON.
     */
    private function readFragment(string $fragmentFile): string
    {
        $fragmentContent = file_get_contents($fragmentFile);
        if ($fragmentContent === false) {
            throw new RuntimeException("Failed to read register fragment: {$fragmentFile}");
        }

        $decoded = json_decode($fragmentContent, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException(
                "Invalid JSON in register fragment {$fragmentFile}: ".json_last_error_msg()
            );
        }

        return $fragmentContent;
    }//end readFragment()

    /**
     * Fold a short hash of all fragment content into `info.version` so the
     * version-gated OpenRegister import re-runs whenever a fragment changes.
     *
     * @param array  $data         The merged configuration data.
     * @param string $fragmentBlob The concatenated fragment content.
     *
     * @return array The configuration with the version folded in.
     */
    private function foldFragmentVersion(array $data, string $fragmentBlob): array
    {
        if ($fragmentBlob === '') {
            return $data;
        }

        $baseVersion  = ($data['info']['version'] ?? '0.0.0');
        $fragmentHash = substr(hash('sha256', $fragmentBlob), 0, 8);
        if (is_array(($data['info'] ?? null)) === false) {
            $data['info'] = [];
        }

        $data['info']['version'] = $baseVersion.'+frag.'.$fragmentHash;

        return $data;
    }//end foldFragmentVersion()

    /**
     * Additively union the two list locations that fragments must *extend*, not
     * replace, under ADR-037.
     *
     * {@see self::deepMergeConfig()} replaces list/scalar values wholesale, which
     * is correct for property lists (`required`, `enum`, …) but wrong for the two
     * places where independent feature fragments each contribute members to a
     * shared collection:
     *
     *  - `components.objects[]` — the seed-object array. Every fragment seeds its
     *    own objects; a plain replace would drop the monolith's (and prior
     *    fragments') seeds, leaving only the last fragment's.
     *  - `components.registers.<slug>.schemas[]` — the per-register schema
     *    membership list. A fragment that registers a new schema must *add* its
     *    slug to the register, not overwrite the existing membership.
     *
     * For both, the union is computed against the pre-merge base (which still
     * holds the accumulated members) plus the fragment's own contribution, then
     * de-duplicated preserving first-seen order. Schema-membership de-dup is by
     * slug string; seed-object de-dup is by `@self.slug` (falling back to a
     * content hash for slug-less seeds) so re-applying an unchanged fragment is
     * idempotent.
     *
     * @param array $base     The configuration after deepMergeConfig replaced the
     *                        lists with the fragment's values.
     * @param array $before   The configuration as it stood *before* this
     *                        fragment was merged (holds the accumulated members).
     * @param array $fragment The raw fragment contributing the new members.
     *
     * @return array The configuration with the additive lists unioned.
     */
    private static function unionAdditiveLists(array $base, array $before, array $fragment): array
    {
        $base = self::unionSeedObjects(base: $base, before: $before, fragment: $fragment);
        $base = self::unionRegisterSchemas(base: $base, before: $before, fragment: $fragment);

        return $base;
    }//end unionAdditiveLists()

    /**
     * Additively union the `components.objects[]` seed array, de-duplicating by
     * `@self.slug` (or a content hash for slug-less seeds).
     *
     * @param array $base     The configuration after deepMergeConfig.
     * @param array $before   The configuration before this fragment merged.
     * @param array $fragment The raw fragment.
     *
     * @return array The configuration with seeds unioned.
     */
    private static function unionSeedObjects(array $base, array $before, array $fragment): array
    {
        $beforeObjects   = ($before['components']['objects'] ?? []);
        $fragmentObjects = ($fragment['components']['objects'] ?? []);
        if (is_array($beforeObjects) === false || is_array($fragmentObjects) === false) {
            return $base;
        }

        if (empty($beforeObjects) === true && empty($fragmentObjects) === true) {
            return $base;
        }

        $merged = [];
        $seen   = [];
        foreach (array_merge($beforeObjects, $fragmentObjects) as $object) {
            $key = self::seedDedupeKey(object: $object);
            if (isset($seen[$key]) === true) {
                continue;
            }

            $seen[$key] = true;
            $merged[]   = $object;
        }//end foreach

        $base['components']['objects'] = array_values($merged);

        return $base;
    }//end unionSeedObjects()

    /**
     * Derive a de-duplication key for a seed object: its `@self.slug` when
     * present, otherwise a content hash (non-array seeds hash their scalar).
     *
     * @param mixed $object The seed object.
     *
     * @return string The de-duplication key.
     */
    private static function seedDedupeKey(mixed $object): string
    {
        if (is_array($object) === true) {
            $slug = ($object['@self']['slug'] ?? null);
            if (is_string($slug) === true && $slug !== '') {
                return 'slug:'.$slug;
            }
        }

        return 'hash:'.md5((string) json_encode($object));
    }//end seedDedupeKey()

    /**
     * Additively union each fragment register's `schemas[]` membership onto the
     * base register, de-duplicating by slug.
     *
     * @param array $base     The configuration after deepMergeConfig.
     * @param array $before   The configuration before this fragment merged.
     * @param array $fragment The raw fragment.
     *
     * @return array The configuration with register membership unioned.
     */
    private static function unionRegisterSchemas(array $base, array $before, array $fragment): array
    {
        $beforeRegisters   = ($before['components']['registers'] ?? []);
        $fragmentRegisters = ($fragment['components']['registers'] ?? []);
        if (is_array($fragmentRegisters) === false || is_array($beforeRegisters) === false) {
            return $base;
        }

        foreach ($fragmentRegisters as $slug => $fragmentRegister) {
            if (is_array($fragmentRegister) === false
                || is_array(($fragmentRegister['schemas'] ?? null)) === false
            ) {
                continue;
            }

            $existing = ($beforeRegisters[$slug]['schemas'] ?? []);
            if (is_array($existing) === false) {
                $existing = [];
            }

            $union = [];
            foreach (array_merge($existing, $fragmentRegister['schemas']) as $schemaSlug) {
                if (in_array($schemaSlug, $union, true) === false) {
                    $union[] = $schemaSlug;
                }
            }

            $base['components']['registers'][$slug]['schemas'] = $union;
        }//end foreach

        return $base;
    }//end unionRegisterSchemas()

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
            if (is_array($value) === true
                && isset($base[$key]) === true
                && is_array($base[$key]) === true
                && self::isList(value: $value) === false
                && self::isList(value: $base[$key]) === false
            ) {
                $base[$key] = self::deepMergeConfig(base: $base[$key], override: $value);
                continue;
            }

            $base[$key] = $value;
        }//end foreach

        return $base;
    }//end deepMergeConfig()

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
