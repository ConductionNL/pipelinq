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
 * @spec openspec/specs/openregister-integration/spec.md#requirement-register-configuration-file-format-compliance
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\App\IAppManager;
use RuntimeException;

/**
 * Service for loading and parsing configuration JSON files.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The class is the one cohesive
 *  register-fragment loader (read + deep-merge + additive-list union + version
 *  hashing); the merge rules belong together (ADR-037).
 *
 * @spec openspec/specs/admin-settings/spec.md#REQ-AS-011
 */
class ConfigFileLoaderService {
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
	public function loadConfigurationFile(): array {
		$appPath = $this->appManager->getAppPath(Application::APP_ID);
		$absoluteFilePath = $appPath . self::REGISTER_FILE;

		if (file_exists($absoluteFilePath) === false) {
			throw new RuntimeException("Configuration file not found: {$absoluteFilePath}");
		}

		$jsonContent = file_get_contents($absoluteFilePath);
		if ($jsonContent === false) {
			throw new RuntimeException("Failed to read configuration file: {$absoluteFilePath}");
		}

		$data = json_decode($jsonContent, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new RuntimeException('Invalid JSON in configuration file: ' . json_last_error_msg());
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
	 * @param array $data The parsed monolith configuration data.
	 * @param string $appPath The absolute app root path.
	 *
	 * @return array The merged configuration data.
	 *
	 * @throws RuntimeException If a fragment file cannot be read or parsed.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Linear validation of each
	 *  fragment file (exists / readable / valid JSON / array) before merge.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Same: sequential guard clauses,
	 *  not nested branching.
	 */
	private function mergeRegisterFragments(array $data, string $appPath): array {
		$fragmentDir = $appPath . self::REGISTER_FRAGMENT_DIR;
		if (is_dir($fragmentDir) === false) {
			return $data;
		}

		$fragmentFiles = glob($fragmentDir . '/*.json');
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
					"Invalid JSON in register fragment {$fragmentFile}: " . json_last_error_msg()
				);
			}

			if (is_array($fragmentData) === false) {
				continue;
			}

			$data = self::deepMergeConfig(base: $data, override: $fragmentData, path: '');
			$fragmentBlob .= $fragmentContent;
		}//end foreach

		if ($fragmentBlob !== '') {
			$baseVersion = ($data['info']['version'] ?? '0.0.0');
			$fragmentHash = substr(hash('sha256', $fragmentBlob), 0, 8);
			if (isset($data['info']) === false || is_array($data['info']) === false) {
				$data['info'] = [];
			}

			$data['info']['version'] = $baseVersion . '+frag.' . $fragmentHash;
		}

		return $data;
	}//end mergeRegisterFragments()

	/**
	 * Dot-paths whose list value is *additively unioned* across fragments
	 * instead of being replaced (ADR-037).
	 *
	 * Register membership (`components.objects` — the seed-object list — and
	 * each register's `schemas[]` membership list) is the one place where a
	 * fragment legitimately needs to *add* entries that other fragments and the
	 * monolith also contribute to. Replacing those lists (the default
	 * list-merge rule) would silently drop every entry owned by another
	 * fragment or by the monolith. The register `schemas` list is matched
	 * positionally via a wildcard segment so it applies to any register slug
	 * (`components.registers.<slug>.schemas`).
	 *
	 * @var array<int, string>
	 */
	private const ADDITIVE_LIST_PATHS = [
		'components.objects',
		'components.registers.*.schemas',
	];

	/**
	 * Recursively deep-merge an override array onto a base array.
	 *
	 * Associative keys are merged recursively; scalar and (by default) list
	 * values from the override replace those in the base. The exception is the
	 * additive-list paths in {@see self::ADDITIVE_LIST_PATHS}: those lists are
	 * unioned (base entries first, then any override entries not already
	 * present) so a fragment can extend register/object membership without
	 * clobbering the monolith's or a sibling fragment's contributions. This
	 * mirrors the fragment-merge semantics shared across the fleet (ADR-037).
	 *
	 * @param array $base The base configuration array.
	 * @param array $override The fragment to merge on top of the base.
	 * @param string $path The dot-path of the current node (root is '').
	 *
	 * @return array The deep-merged result.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) The per-key branch picks one
	 *  of three merge modes (additive-list union, recursive assoc merge, replace);
	 *  the conditions are flat, not nested.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Same flat per-key branch as above;
	 *  path count is high only because of the number of independent merge modes.
	 */
	private static function deepMergeConfig(array $base, array $override, string $path = ''): array {
		foreach ($override as $key => $value) {
			$childPath = $path . '.' . $key;
			if ($path === '') {
				$childPath = (string)$key;
			}

			// An empty override array (a JSON `{}` or `[]`) carries no data to
			// merge. PHP decodes both to `[]`, which `isList()` reports as a
			// list, so without this guard an empty `{}` placeholder (e.g. a
			// fragment declaring `registers.pipelinq: {}` to anchor schema
			// overrides elsewhere) would fall through to the replace branch and
			// clobber a populated base associative array — silently dropping a
			// sibling fragment's register membership (ADR-037 union semantics).
			if (is_array($value) === true
				&& $value === []
				&& isset($base[$key]) === true
				&& is_array($base[$key]) === true
				&& $base[$key] !== []
			) {
				continue;
			}

			if (is_array($value) === true
				&& isset($base[$key]) === true
				&& is_array($base[$key]) === true
				&& self::isList(value: $value) === true
				&& self::isList(value: $base[$key]) === true
				&& self::isAdditiveListPath(path: $childPath) === true
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
	 * Whether a dot-path is configured as an additive (unioned) list path.
	 *
	 * A single `*` segment in a configured pattern matches exactly one path
	 * segment (any register slug), so `components.registers.alpha.schemas`
	 * matches the `components.registers.*.schemas` pattern.
	 *
	 * @param string $path The concrete dot-path to test.
	 *
	 * @return bool True when the path's list value must be unioned.
	 */
	private static function isAdditiveListPath(string $path): bool {
		$segments = explode('.', $path);
		foreach (self::ADDITIVE_LIST_PATHS as $pattern) {
			$patternSegments = explode('.', $pattern);
			if (count($patternSegments) !== count($segments)) {
				continue;
			}

			$match = true;
			foreach ($patternSegments as $index => $patternSegment) {
				if ($patternSegment !== '*' && $patternSegment !== $segments[$index]) {
					$match = false;
					break;
				}
			}

			if ($match === true) {
				return true;
			}
		}//end foreach

		return false;
	}//end isAdditiveListPath()

	/**
	 * Union two lists, preserving base order and appending only the override
	 * entries that are not already present (value-equality, order-insensitive
	 * for associative-array entries).
	 *
	 * @param array $base The base list.
	 * @param array $override The override list to fold in.
	 *
	 * @return array The unioned list.
	 */
	private static function unionLists(array $base, array $override): array {
		$result = array_values($base);
		foreach ($override as $candidate) {
			$exists = false;
			foreach ($result as $existing) {
				if (self::valuesEqual(left: $existing, right: $candidate) === true) {
					$exists = true;
					break;
				}
			}

			if ($exists === false) {
				$result[] = $candidate;
			}
		}

		return $result;
	}//end unionLists()

	/**
	 * Order-insensitive deep equality for two list entries.
	 *
	 * Scalars compare by value; arrays compare by canonicalised JSON with keys
	 * sorted recursively, so two seed objects with the same content but a
	 * different key order are treated as duplicates.
	 *
	 * @param mixed $left The first value.
	 * @param mixed $right The second value.
	 *
	 * @return bool True when the values are deeply equal.
	 */
	private static function valuesEqual(mixed $left, mixed $right): bool {
		if (is_array($left) === true && is_array($right) === true) {
			$leftCopy = $left;
			$rightCopy = $right;
			self::ksortRecursive(value: $leftCopy);
			self::ksortRecursive(value: $rightCopy);
			return json_encode($leftCopy) === json_encode($rightCopy);
		}

		return $left === $right;
	}//end valuesEqual()

	/**
	 * Recursively sort an array by key in place (associative arrays only).
	 *
	 * @param array $value The array to sort, by reference.
	 *
	 * @return void
	 */
	private static function ksortRecursive(array &$value): void {
		foreach ($value as &$child) {
			if (is_array($child) === true) {
				self::ksortRecursive(value: $child);
			}
		}

		unset($child);
		if (self::isList(value: $value) === false) {
			ksort($value);
		}
	}//end ksortRecursive()

	/**
	 * Determine whether an array is a sequential list (zero-indexed, no gaps).
	 *
	 * Provided for PHP < 8.1 parity where `array_is_list()` is unavailable.
	 *
	 * @param array $value The array to inspect.
	 *
	 * @return bool True when the array is a sequential list.
	 */
	private static function isList(array $value): bool {
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
	public function ensureSourceType(array $data): array {
		if (isset($data['x-openregister']) === false) {
			$data['x-openregister'] = [];
		}

		if (isset($data['x-openregister']['sourceType']) === false) {
			$data['x-openregister']['sourceType'] = 'local';
		}

		return $data;
	}//end ensureSourceType()
}//end class
