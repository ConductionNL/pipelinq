<?php

/**
 * Pipelinq full data export.
 *
 * "Take your data with you": one archive with every schema of the pipelinq
 * register, as JSON Lines and as CSV, the schema definitions as JSON Schema
 * the files attached to objects under their object identifier and a
 * manifest, stored in the administrator's Nextcloud Files. The export
 * jobs of the BI export page already format one schema at a time
 * (ExportDataService::format()); this service only adds the packaging, so the
 * archive and the jobs never disagree about how a row is written.
 *
 * The archive holds ALL objects regardless of per-user read rules (REQ-DPE-002),
 * which is why only an administrator may start it (FullExportController). A
 * read that fails fails the whole export: an archive with a schema silently
 * cut short reads as complete and is not.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Export
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/data-portability-full-export/specs/data-portability/spec.md#REQ-DPE-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Export;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\FullExportServiceInterface;
use OCA\Pipelinq\Service\ConfigFileLoaderService;
use OCA\Pipelinq\Util\CastingPayloadToArray;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Builds the full-export archive and stores it in a user's Files.
 *
 * @spec openspec/changes/data-portability-full-export/specs/data-portability/spec.md#REQ-DPE-002
 */
class FullExportService implements FullExportServiceInterface {
	use CastingPayloadToArray {
		toArray as private;
	}

	/**
	 * Objects read per page.
	 *
	 * @var int
	 */
	private const PAGE_SIZE = 500;

	/**
	 * The formats every schema is written in, by file extension.
	 *
	 * @var array<string, string>
	 */
	private const FORMATS = ['jsonl' => 'jsonl', 'csv' => 'csv'];

	/**
	 * OpenRegister's published read of an object's attached files.
	 *
	 * Named as a string and looked up in the container, so an OpenRegister
	 * that does not publish it yet still exports everything else; the
	 * manifest then says the attached files are not included.
	 *
	 * @var string
	 */
	private const OBJECT_FILE_READER = 'OCA\\OpenRegister\\Contract\\ObjectFileReaderInterface';

	/**
	 * Constructor.
	 *
	 * @param ConfigFileLoaderService $configFiles   The register definition, fragments merged.
	 * @param ExportDataService       $formatter     Writes rows as JSON Lines and CSV.
	 * @param ObjectServiceInterface  $objectService OpenRegister's object service.
	 * @param IAppConfig              $appConfig     The configured register and schema ids.
	 * @param IRootFolder             $rootFolder    Nextcloud Files, where the archive is stored.
	 * @param ITimeFactory            $timeFactory   The export time.
	 * @param ContainerInterface|null $container     The DI container, for OpenRegister's object file reader.
	 *
	 * @spec openspec/changes/data-portability-full-export/specs/data-portability/spec.md#REQ-DPE-002
	 */
	public function __construct(
		private readonly ConfigFileLoaderService $configFiles,
		private readonly ExportDataService $formatter,
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly IRootFolder $rootFolder,
		private readonly ITimeFactory $timeFactory,
		private readonly ?ContainerInterface $container=null,
	) {
	}//end __construct()

	/**
	 * Every schema the pipelinq register declares, fragments included, sorted.
	 *
	 * This is the list an export can include: none is left out, and a schema
	 * a later release adds is in it without anyone listing it here.
	 *
	 * @return list<string> The schema slugs.
	 *
	 * @spec openspec/changes/data-portability-full-export/specs/data-portability/spec.md#REQ-DPE-001
	 */
	public function schemaSlugs(): array {
		$schemas = ($this->configFiles->loadConfigurationFile()['components']['schemas'] ?? []);
		if (is_array($schemas) === false) {
			return [];
		}

		$slugs = array_map('strval', array_keys($schemas));
		sort($slugs);

		return $slugs;
	}//end schemaSlugs()

	/**
	 * Build the archive and store it in the user's Files.
	 *
	 * @param string $userId The administrator who asked for it.
	 *
	 * @return array{name: string, path: string, fileId: int, manifest: array<string, mixed>} Where it was stored and what it holds.
	 *
	 * @throws RuntimeException When an object read fails or the archive cannot be written or stored.
	 *
	 * @spec openspec/changes/data-portability-full-export/specs/data-portability/spec.md#REQ-DPE-002
	 */
	public function exportFor(string $userId): array {
		$now      = $this->timeFactory->getDateTime();
		$name     = 'pipelinq-export-' . $now->format('Y-m-d-His') . '.zip';
		$tempPath = tempnam(sys_get_temp_dir(), 'pipelinq-export-');
		if ($tempPath === false) {
			throw new RuntimeException('No temporary file for the export archive.');
		}

		try {
			$manifest = $this->writeArchive(path: $tempPath, exportedAt: $now->format(DATE_ATOM));
			$contents = file_get_contents($tempPath);
			if ($contents === false) {
				throw new RuntimeException('The export archive could not be read back.');
			}

			$file = $this->rootFolder->getUserFolder($userId)->newFile($name, $contents);
		} finally {
			if (file_exists($tempPath) === true) {
				unlink($tempPath);
			}
		}

		return ['name' => $name, 'path' => $file->getPath(), 'fileId' => (int) $file->getId(), 'manifest' => $manifest];
	}//end exportFor()

	/**
	 * Write the archive to a path.
	 *
	 * Per schema `data/<schema>.jsonl` and `data/<schema>.csv`, its definition
	 * as `schemas/<schema>.schema.json`, every object's attached files as
	 * `files/<object id>/<file name>`, and `manifest.json`.
	 *
	 * @param string $path       The archive path.
	 * @param string $exportedAt The export time, ISO 8601.
	 *
	 * @return array<string, mixed> The manifest.
	 *
	 * @throws RuntimeException When an object read fails or the archive cannot be written.
	 *
	 * @spec openspec/changes/data-portability-full-export/specs/data-portability/spec.md#REQ-DPE-002
	 */
	public function writeArchive(string $path, string $exportedAt): array {
		$zip = new ZipArchive();
		if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
			throw new RuntimeException('The export archive could not be created.');
		}

		$definitions = ($this->configFiles->loadConfigurationFile()['components']['schemas'] ?? []);
		$fileReader  = $this->objectFileReader();
		$entries     = [];
		foreach ($this->schemaSlugs() as $slug) {
			$rows        = $this->rowsOf(slug: $slug);
			$attachments = 0;
			if ($fileReader !== null) {
				$attachments = $this->addAttachedFiles(zip: $zip, reader: $fileReader, slug: $slug, rows: ($rows ?? []));
			}

			$entries[] = [
				'schema'        => $slug,
				'configured'    => $rows !== null,
				'objects'       => count($rows ?? []),
				'attachedFiles' => $attachments,
				'files'         => $this->fileNames(slug: $slug),
			];
			foreach (self::FORMATS as $extension => $format) {
				$zip->addFromString('data/' . $slug . '.' . $extension, $this->formatter->format(rows: ($rows ?? []), format: $format));
			}

			$zip->addFromString('schemas/' . $slug . '.schema.json', $this->jsonSchema(slug: $slug, definition: ($definitions[$slug] ?? [])));
		}

		$manifest = [
			'app'        => AppIdentity::APP_ID,
			'exportedAt' => $exportedAt,
			'formats'    => array_values(self::FORMATS),
			'attachedFilesIncluded' => $fileReader !== null,
			'schemas'    => $entries,
		];
		$zip->addFromString('manifest.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

		if ($zip->close() !== true) {
			throw new RuntimeException('The export archive could not be written.');
		}

		return $manifest;
	}//end writeArchive()

	/**
	 * Every object of a schema, read past per-user rules, or null when this
	 * instance never configured the schema (so it holds no objects).
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return list<array<string, mixed>>|null The objects.
	 *
	 * @throws RuntimeException When a page cannot be read.
	 */
	private function rowsOf(string $slug): ?array {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$schema   = $this->appConfig->getValueString(AppIdentity::APP_ID, $slug . '_schema', '');
		if ($register === '' || $schema === '') {
			return null;
		}

		return $this->allRows(slug: $slug, register: $register, schema: $schema);
	}//end rowsOf()

	/**
	 * Every object of a configured schema, page by page, read past per-user rules.
	 *
	 * @param string $slug The schema slug, for the error.
	 * @param string $register The register id.
	 * @param string $schema The schema id.
	 *
	 * @return list<array<string, mixed>> The objects.
	 *
	 * @throws RuntimeException When a page cannot be read.
	 */
	private function allRows(string $slug, string $register, string $schema): array {
		$rows   = [];
		$offset = 0;
		do {
			$page = $this->pageOf(slug: $slug, register: $register, schema: $schema, offset: $offset);
			foreach ($page as $object) {
				$rows[] = $this->toArray(object: $object);
			}

			$pageSize = count($page);
			$offset  += self::PAGE_SIZE;
		} while ($pageSize === self::PAGE_SIZE);

		return $rows;
	}//end allRows()

	/**
	 * One page of a schema's objects.
	 *
	 * @param string $slug The schema slug, for the error.
	 * @param string $register The register id.
	 * @param string $schema The schema id.
	 * @param int $offset The page offset.
	 *
	 * @return array<mixed> The objects on the page.
	 *
	 * @throws RuntimeException When the page cannot be read.
	 */
	private function pageOf(string $slug, string $register, string $schema, int $offset): array {
		try {
			return $this->objectService->findAll(
				config: ['filters' => ['register' => $register, 'schema' => $schema], 'limit' => self::PAGE_SIZE, 'offset' => $offset],
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			throw new RuntimeException('The objects of schema ' . $slug . ' could not be read: ' . $e->getMessage(), 0, $e);
		}
	}//end pageOf()

	/**
	 * OpenRegister's object file reader, or null when this OpenRegister does not publish it.
	 *
	 * @return object|null The reader.
	 */
	private function objectFileReader(): ?object {
		if ($this->container === null || $this->container->has(self::OBJECT_FILE_READER) === false) {
			return null;
		}

		return $this->container->get(self::OBJECT_FILE_READER);
	}//end objectFileReader()

	/**
	 * Copy every object's attached files into the archive under its identifier.
	 *
	 * @param ZipArchive                 $zip    The archive.
	 * @param object                     $reader OpenRegister's object file reader.
	 * @param string                     $slug   The schema slug, for the error message.
	 * @param list<array<string, mixed>> $rows   The schema's objects.
	 *
	 * @return int The number of files copied.
	 *
	 * @throws RuntimeException When an object's files cannot be read.
	 *
	 * @spec openspec/changes/data-portability-full-export/specs/data-portability/spec.md#REQ-DPE-002
	 */
	private function addAttachedFiles(ZipArchive $zip, object $reader, string $slug, array $rows): int {
		$count = 0;
		foreach ($rows as $row) {
			$objectId = $this->objectIdOf(row: $row);
			if ($objectId === '') {
				continue;
			}

			try {
				foreach ($reader->getObjectFiles(objectUuid: $objectId) as $file) {
					$name = basename(str_replace('\\', '/', (string) $file->getName()));
					if ($name === '' || $name === '.' || $name === '..') {
						continue;
					}

					$zip->addFromString('files/' . $objectId . '/' . $name, (string) $file->getContent());
					$count++;
				}
			} catch (Throwable $e) {
				throw new RuntimeException('The attached files of ' . $slug . ' object ' . $objectId . ' could not be read: ' . $e->getMessage(), 0, $e);
			}
		}//end foreach

		return $count;
	}//end addAttachedFiles()

	/**
	 * An object's identifier as OpenRegister serialises it, safe as a directory name.
	 *
	 * @param array<string, mixed> $row The object.
	 *
	 * @return string The identifier, or '' when it has none usable.
	 */
	private function objectIdOf(array $row): string {
		$self = $row['@self'] ?? [];
		$id   = '';
		if (is_array($self) === true && is_scalar($self['id'] ?? null) === true) {
			$id = (string) $self['id'];
		}

		foreach (['uuid', 'id'] as $key) {
			if ($id === '' && is_scalar($row[$key] ?? null) === true) {
				$id = (string) $row[$key];
			}
		}

		if (preg_match('/^[A-Za-z0-9._-]+$/', $id) !== 1 || trim($id, '.') === '') {
			return '';
		}

		return $id;
	}//end objectIdOf()

	/**
	 * The archive's file names for a schema, as the manifest lists them.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return list<string> The file names.
	 */
	private function fileNames(string $slug): array {
		$names = [];
		foreach (array_keys(self::FORMATS) as $extension) {
			$names[] = 'data/' . $slug . '.' . $extension;
		}

		$names[] = 'schemas/' . $slug . '.schema.json';

		return $names;
	}//end fileNames()

	/**
	 * A schema definition as a JSON Schema document.
	 *
	 * @param string $slug       The schema slug.
	 * @param mixed  $definition The register's definition of the schema.
	 *
	 * @return string The JSON Schema.
	 */
	private function jsonSchema(string $slug, mixed $definition): string {
		if (is_array($definition) === false) {
			$definition = [];
		}

		$document   = [
			'$schema' => 'https://json-schema.org/draft/2020-12/schema',
			'$id'     => $slug,
			'title'   => (string) ($definition['title'] ?? $slug),
			'type'    => 'object',
		];
		foreach (['description', 'required', 'properties'] as $key) {
			if (isset($definition[$key]) === true) {
				$document[$key] = $definition[$key];
			}
		}

		return (string) json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}//end jsonSchema()
}//end class
