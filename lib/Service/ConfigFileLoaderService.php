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

use OCA\Pipelinq\Kernel\AppIdentity;
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
		$appPath = $this->appManager->getAppPath(AppIdentity::APP_ID);
		$data = self::readConfigurationData(absoluteFilePath: $appPath . self::REGISTER_FILE);

		$data = $this->mergeRegisterFragments(data: $data, appPath: $appPath);

		return $data;
	}//end loadConfigurationFile()

	/**
	 * Read and decode the monolith configuration file.
	 *
	 * @param string $absoluteFilePath The absolute path of the configuration file.
	 *
	 * @return mixed The decoded configuration data.
	 *
	 * @throws RuntimeException If the file is missing, cannot be read or is not valid JSON.
	 */
	private static function readConfigurationData(string $absoluteFilePath): mixed {
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

		return $data;
	}//end readConfigurationData()

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
	 */
	private function mergeRegisterFragments(array $data, string $appPath): array {
		$fragmentBlob = '';
		foreach (self::fragmentFiles(appPath: $appPath) as $fragmentFile) {
			$fragmentContent = self::readFragment(fragmentFile: $fragmentFile);
			$fragmentData = self::decodeFragment(fragmentFile: $fragmentFile, fragmentContent: $fragmentContent);
			if (is_array($fragmentData) === false) {
				continue;
			}

			$data = self::deepMergeConfig(base: $data, override: $fragmentData);
			$fragmentBlob .= $fragmentContent;
		}

		if ($fragmentBlob === '') {
			return $data;
		}

		return self::withFragmentVersion(data: $data, fragmentBlob: $fragmentBlob);
	}//end mergeRegisterFragments()

	/**
	 * The register fragment files of the app, in stable (sorted) order.
	 *
	 * @param string $appPath The absolute app root path.
	 *
	 * @return array<int, string> The fragment paths; empty when the directory is absent or empty.
	 */
	private static function fragmentFiles(string $appPath): array {
		$fragmentDir = $appPath . self::REGISTER_FRAGMENT_DIR;
		if (is_dir($fragmentDir) === false) {
			return [];
		}

		$fragmentFiles = glob($fragmentDir . '/*.json');
		if ($fragmentFiles === false) {
			return [];
		}

		sort($fragmentFiles);
		return $fragmentFiles;
	}//end fragmentFiles()

	/**
	 * Read one fragment file.
	 *
	 * @param string $fragmentFile The fragment path.
	 *
	 * @return string The raw fragment content.
	 *
	 * @throws RuntimeException If the file cannot be read.
	 */
	private static function readFragment(string $fragmentFile): string {
		$fragmentContent = file_get_contents($fragmentFile);
		if ($fragmentContent === false) {
			throw new RuntimeException("Failed to read register fragment: {$fragmentFile}");
		}

		return $fragmentContent;
	}//end readFragment()

	/**
	 * Decode one fragment's JSON content.
	 *
	 * @param string $fragmentFile    The fragment path, for the error message.
	 * @param string $fragmentContent The raw fragment content.
	 *
	 * @return mixed The decoded fragment.
	 *
	 * @throws RuntimeException If the content is not valid JSON.
	 */
	private static function decodeFragment(string $fragmentFile, string $fragmentContent): mixed {
		$fragmentData = json_decode($fragmentContent, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new RuntimeException(
				"Invalid JSON in register fragment {$fragmentFile}: " . json_last_error_msg()
			);
		}

		return $fragmentData;
	}//end decodeFragment()

	/**
	 * Fold a short hash of the merged fragment content into `info.version`, so
	 * OpenRegister's version-gated import re-runs whenever a fragment changes.
	 *
	 * @param array  $data         The merged configuration data.
	 * @param string $fragmentBlob The concatenated content of the merged fragments.
	 *
	 * @return array The data with the stamped version.
	 */
	private static function withFragmentVersion(array $data, string $fragmentBlob): array {
		$baseVersion = ($data['info']['version'] ?? '0.0.0');
		$fragmentHash = substr(hash('sha256', $fragmentBlob), 0, 8);
		if (isset($data['info']) === false || is_array($data['info']) === false) {
			$data['info'] = [];
		}

		$data['info']['version'] = $baseVersion . '+frag.' . $fragmentHash;
		return $data;
	}//end withFragmentVersion()

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
	 * @param array $override The fragment to merge on top of the base (at the root).
	 *
	 * @return array The deep-merged result.
	 */
	private static function deepMergeConfig(array $base, array $override): array {
		return self::mergeMap(base: $base, fragment: new ConfigFragmentValue(value: $override, path: ''));
	}//end deepMergeConfig()

	/**
	 * Merge an associative fragment value onto a base array, key by key.
	 *
	 * @param array               $base     The base array at the fragment's path.
	 * @param ConfigFragmentValue $fragment The fragment array and its path.
	 *
	 * @return array The merged array.
	 */
	private static function mergeMap(array $base, ConfigFragmentValue $fragment): array {
		foreach ($fragment->value as $key => $value) {
			if (self::isEmptyPlaceholder(baseValue: ($base[$key] ?? null), value: $value) === true) {
				continue;
			}

			$base[$key] = self::mergedValue(baseValue: ($base[$key] ?? null), fragment: $fragment->child(key: $key));
		}

		return $base;
	}//end mergeMap()

	/**
	 * Whether an override is an empty placeholder over a populated base array.
	 *
	 * An empty override array (a JSON `{}` or `[]`) carries no data to merge.
	 * PHP decodes both to `[]`, which `isList()` reports as a list, so without
	 * this guard an empty `{}` placeholder (e.g. a fragment declaring
	 * `registers.pipelinq: {}` to anchor schema overrides elsewhere) would fall
	 * through to the replace branch and clobber a populated base associative
	 * array — silently dropping a sibling fragment's register membership
	 * (ADR-037 union semantics).
	 *
	 * @param mixed $baseValue The base value under the key, null when absent.
	 * @param mixed $value     The override value under the key.
	 *
	 * @return bool True when the base value is kept as it is.
	 */
	private static function isEmptyPlaceholder(mixed $baseValue, mixed $value): bool {
		return $value === [] && is_array($baseValue) === true && $baseValue !== [];
	}//end isEmptyPlaceholder()

	/**
	 * The value a key holds after merging one fragment value onto its base value.
	 *
	 * Anything other than two arrays is replaced by the override.
	 *
	 * @param mixed               $baseValue The base value, null when absent.
	 * @param ConfigFragmentValue $fragment  The override value and its path.
	 *
	 * @return mixed The merged value.
	 */
	private static function mergedValue(mixed $baseValue, ConfigFragmentValue $fragment): mixed {
		if (is_array($fragment->value) === false || is_array($baseValue) === false) {
			return $fragment->value;
		}

		return self::mergedArray(base: $baseValue, fragment: $fragment);
	}//end mergedValue()

	/**
	 * Merge two arrays by shape: an additive-path list pair is unioned, an
	 * associative pair is merged recursively, anything else is replaced.
	 *
	 * @param array               $base     The base array.
	 * @param ConfigFragmentValue $fragment The override array and its path.
	 *
	 * @return array The merged array.
	 */
	private static function mergedArray(array $base, ConfigFragmentValue $fragment): array {
		$overrideIsList = self::isList(value: $fragment->value);
		if ($overrideIsList !== self::isList(value: $base)) {
			return $fragment->value;
		}

		if ($overrideIsList === false) {
			return self::mergeMap(base: $base, fragment: $fragment);
		}

		if (self::isAdditiveListPath(path: $fragment->path) === true) {
			return self::unionLists(base: $base, override: $fragment->value);
		}

		return $fragment->value;
	}//end mergedArray()

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
			if (self::pathMatchesPattern(pattern: $pattern, segments: $segments) === true) {
				return true;
			}
		}

		return false;
	}//end isAdditiveListPath()

	/**
	 * Whether the segments of a dot-path match one additive-list pattern,
	 * where a `*` segment matches any single segment.
	 *
	 * @param string             $pattern  The configured dot-path pattern.
	 * @param array<int, string> $segments The segments of the concrete dot-path.
	 *
	 * @return bool True when every segment matches.
	 */
	private static function pathMatchesPattern(string $pattern, array $segments): bool {
		$patternSegments = explode('.', $pattern);
		if (count($patternSegments) !== count($segments)) {
			return false;
		}

		foreach ($patternSegments as $index => $patternSegment) {
			if ($patternSegment !== '*' && $patternSegment !== $segments[$index]) {
				return false;
			}
		}

		return true;
	}//end pathMatchesPattern()

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
			if (self::listContains(list: $result, candidate: $candidate) === false) {
				$result[] = $candidate;
			}
		}

		return $result;
	}//end unionLists()

	/**
	 * Whether a list already holds an entry equal to the candidate.
	 *
	 * @param array $list      The list built so far.
	 * @param mixed $candidate The entry to look for.
	 *
	 * @return bool True when an equal entry is present.
	 */
	private static function listContains(array $list, mixed $candidate): bool {
		foreach ($list as $existing) {
			if (self::valuesEqual(left: $existing, right: $candidate) === true) {
				return true;
			}
		}

		return false;
	}//end listContains()

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
