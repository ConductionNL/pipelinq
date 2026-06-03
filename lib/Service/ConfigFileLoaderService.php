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
 *  config loader plus the ADR-037 fragment deep-merge / additive-union rule; the
 *  union helpers are deliberately defensive (each branch handles one malformed-
 *  fragment shape), which raises the aggregate count without tangling logic.
 *
 * @spec openspec/changes/pos-split-tender/tasks.md#1.1
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
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Each branch handles one
     *  defensive case in the fragment sweep (missing dir, glob failure, read
     *  failure, JSON failure, non-array fragment, version stamping); collapsing
     *  them would hide the per-failure handling.
     * @SuppressWarnings(PHPMD.NPathComplexity)      Same defensive-sweep rationale.
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

            // Snapshot the additive set-lists BEFORE the deep merge replaces them,
            // then re-union the fragment's additions back in afterwards (ADR-037).
            $preMerge      = $data;
            $data          = self::deepMergeConfig(base: $data, override: $fragmentData);
            $data          = self::unionAdditiveLists(base: $data, preMerge: $preMerge, override: $fragmentData);
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
     * Additively union the register lists that are semantically sets (ADR-037).
     *
     * {@see self::deepMergeConfig()} treats every JSON list as a replace (per the
     * documented fragment merge semantics). Two register paths, however, are
     * conceptually *sets* a fragment must EXTEND rather than overwrite, or one
     * feature build's fragment would silently drop every schema/seed contributed
     * by the base monolith (and by earlier fragments):
     *
     *   - `components.objects[]` — the seed objects. Deduped by `@self.slug`; a
     *     fragment object with a slug that already exists replaces that one entry
     *     (so a fragment can still correct a base seed) while leaving the rest.
     *   - `components.registers.<slug>.schemas[]` — a register's schema
     *     membership. Deduped by value (the schema slug string), order-preserving.
     *
     * This is the fleet-standard additive-union rule: it runs AFTER the deep
     * merge (which has, at this point, already replaced these two lists with the
     * fragment's partial list). The pre-merge snapshot supplies the base side of
     * the union so neither side is lost.
     *
     * @param array $base     The post-deep-merge configuration (fragment lists won).
     * @param array $preMerge The configuration snapshot taken before the deep merge.
     * @param array $override The raw fragment that contributed the additive lists.
     *
     * @return array The configuration with the additive lists unioned back in.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Each branch guards one shape
     *  the additive union must tolerate (missing/non-list objects, missing/non-
     *  array registers, missing/non-list schema membership) before unioning.
     *
     * @spec openspec/changes/pos-split-tender/tasks.md#1.1
     */
    private static function unionAdditiveLists(array $base, array $preMerge, array $override): array
    {
        // Union the seed objects (components.objects[]), deduped by @self.slug.
        $overrideObjects = ($override['components']['objects'] ?? null);
        if (is_array($overrideObjects) === true && self::isList(value: $overrideObjects) === true) {
            $baseObjects = ($preMerge['components']['objects'] ?? []);
            if (is_array($baseObjects) === false) {
                $baseObjects = [];
            }

            $base['components']['objects'] = self::unionObjectsBySlug(
                base: $baseObjects,
                additions: $overrideObjects
            );
        }

        // Union each register's schema membership list, deduped by value.
        $overrideRegisters = ($override['components']['registers'] ?? null);
        if (is_array($overrideRegisters) === true) {
            foreach ($overrideRegisters as $registerSlug => $registerData) {
                if (is_array($registerData) === false) {
                    continue;
                }

                $additionSchemas = ($registerData['schemas'] ?? null);
                if (is_array($additionSchemas) === false || self::isList(value: $additionSchemas) === false) {
                    continue;
                }

                $baseSchemas = ($preMerge['components']['registers'][$registerSlug]['schemas'] ?? []);
                if (is_array($baseSchemas) === false) {
                    $baseSchemas = [];
                }

                $base['components']['registers'][$registerSlug]['schemas'] = self::unionScalarList(
                    base: $baseSchemas,
                    additions: $additionSchemas
                );
            }//end foreach
        }//end if

        return $base;
    }//end unionAdditiveLists()

    /**
     * Union two seed-object lists, deduping by `@self.slug`.
     *
     * Base objects keep their position; an addition whose slug already exists
     * replaces that base entry in place (last-writer-wins for a colliding slug),
     * and a slug-less or new-slug addition is appended.
     *
     * @param array<int, mixed> $base      The base seed objects.
     * @param array<int, mixed> $additions The fragment's seed objects.
     *
     * @return array<int, mixed> The unioned seed objects.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) The slug index build + the
     *  replace-in-place vs append decision each branch on the slug's presence;
     *  splitting would scatter the one dedup-by-slug rule.
     */
    private static function unionObjectsBySlug(array $base, array $additions): array
    {
        $indexBySlug = [];
        foreach ($base as $index => $object) {
            $slug = self::objectSlug(object: $object);
            if (is_string($slug) === true && $slug !== '') {
                $indexBySlug[$slug] = $index;
            }
        }

        foreach ($additions as $object) {
            $slug = self::objectSlug(object: $object);
            if (is_string($slug) === true && $slug !== '' && isset($indexBySlug[$slug]) === true) {
                $base[$indexBySlug[$slug]] = $object;
                continue;
            }

            $base[] = $object;
            if (is_string($slug) === true && $slug !== '') {
                $indexBySlug[$slug] = array_key_last($base);
            }
        }

        return array_values($base);
    }//end unionObjectsBySlug()

    /**
     * Extract the `@self.slug` from a seed object, or null when absent.
     *
     * @param mixed $object The candidate seed object.
     *
     * @return string|null The slug, or null when the object has none.
     */
    private static function objectSlug(mixed $object): ?string
    {
        if (is_array($object) === false) {
            return null;
        }

        $slug = ($object['@self']['slug'] ?? null);
        if (is_string($slug) === true) {
            return $slug;
        }

        return null;
    }//end objectSlug()

    /**
     * Union two scalar lists, preserving base order and appending new values.
     *
     * @param array<int, mixed> $base      The base list.
     * @param array<int, mixed> $additions The values to union in.
     *
     * @return array<int, mixed> The deduped union (base order first).
     */
    private static function unionScalarList(array $base, array $additions): array
    {
        foreach ($additions as $value) {
            if (in_array($value, $base, true) === false) {
                $base[] = $value;
            }
        }

        return array_values($base);
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
