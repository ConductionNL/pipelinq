<?php

/**
 * In-memory PortalObjectRepository test double.
 *
 * Replaces the OpenRegister-backed repository with a deterministic in-memory
 * store so portal services can be unit-tested without a live OR/ObjectService.
 * It honours the same find / findAll / findOneBy / save / idOf contract,
 * including equality filtering and id minting, so the tests exercise the real
 * service logic (scoping, expiry, rate limits) rather than rigged mocks.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Portal;

use OCA\Pipelinq\Service\Portal\PortalObjectRepository;

/**
 * Deterministic in-memory portal repository for tests.
 */
class FakePortalObjectRepository extends PortalObjectRepository
{
    /**
     * Store keyed by schema slug then id.
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private array $store = [];

    /**
     * Monotonic id counter.
     *
     * @var int
     */
    private int $counter = 0;

    /**
     * Constructor (bypasses the real DI wiring).
     */
    public function __construct()
    {
        // Intentionally does not call parent::__construct(): no OR needed.
    }//end __construct()

    /**
     * Seed an object into a schema with an explicit id.
     *
     * @param string               $schema The schema slug.
     * @param string               $id     The id.
     * @param array<string, mixed> $data   The object data.
     *
     * @return array<string, mixed> The stored object (with @self.id).
     */
    public function seed(string $schema, string $id, array $data): array
    {
        $data['@self'] = ['id' => $id];
        $this->store[$schema][$id] = $data;
        return $data;
    }//end seed()

    /**
     * {@inheritDoc}
     *
     * @param string $schemaSlug The schema slug.
     * @param string $id         The id.
     *
     * @return array<string, mixed>|null The object, or null.
     */
    public function find(string $schemaSlug, string $id): ?array
    {
        return ($this->store[$schemaSlug][$id] ?? null);
    }//end find()

    /**
     * {@inheritDoc}
     *
     * @param string               $schemaSlug The schema slug.
     * @param array<string, mixed> $filters    The equality filters.
     *
     * @return array<int, array<string, mixed>> The matches.
     */
    public function findAll(string $schemaSlug, array $filters=[]): array
    {
        $rows = array_values($this->store[$schemaSlug] ?? []);
        if (empty($filters) === true) {
            return $rows;
        }

        return array_values(array_filter(
            $rows,
            static function (array $row) use ($filters): bool {
                foreach ($filters as $key => $value) {
                    if (($row[$key] ?? null) !== $value) {
                        return false;
                    }
                }

                return true;
            }
        ));
    }//end findAll()

    /**
     * {@inheritDoc}
     *
     * @param string               $schemaSlug The schema slug.
     * @param array<string, mixed> $filters    The equality filters.
     *
     * @return array<string, mixed>|null The first match.
     */
    public function findOneBy(string $schemaSlug, array $filters): ?array
    {
        $matches = $this->findAll($schemaSlug, $filters);
        return ($matches[0] ?? null);
    }//end findOneBy()

    /**
     * {@inheritDoc}
     *
     * @param string               $schemaSlug The schema slug.
     * @param array<string, mixed> $data       The data.
     * @param string|null          $id         The id, or null to mint.
     *
     * @return array<string, mixed> The saved object.
     */
    public function save(string $schemaSlug, array $data, ?string $id=null): array
    {
        if ($id === null) {
            $this->counter++;
            $id = 'id-'.$this->counter;
        }

        $data['@self'] = ['id' => $id];
        $this->store[$schemaSlug][$id] = $data;
        return $data;
    }//end save()

    /**
     * {@inheritDoc}
     *
     * @param array<string, mixed> $object The object.
     *
     * @return string|null The id.
     */
    public function idOf(array $object): ?string
    {
        if (isset($object['@self']['id']) === true) {
            return (string) $object['@self']['id'];
        }

        return ($object['id'] ?? null);
    }//end idOf()

    /**
     * Count stored objects of a schema (test assertion helper).
     *
     * @param string $schema The schema slug.
     *
     * @return int The count.
     */
    public function count(string $schema): int
    {
        return count($this->store[$schema] ?? []);
    }//end count()
}//end class
