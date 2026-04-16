<?php

/**
 * Pipelinq ContactmomentService.
 *
 * Service for contactmoment business operations including permission-checked deletion.
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
 * @spec openspec/changes/contactmomenten/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\NotPermittedException;
use OCP\IGroupManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for contactmoment business operations.
 *
 * Handles permission-checked deletion: only the creating agent or a Nextcloud admin
 * may delete a contactmoment.
 */
class ContactmomentService
{
    /**
     * Constructor.
     *
     * @param ContainerInterface $container       The DI container.
     * @param SettingsService    $settingsService The settings service.
     * @param IGroupManager      $groupManager    The group manager.
     * @param LoggerInterface    $logger          The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private SettingsService $settingsService,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the OpenRegister ObjectService.
     *
     * @return \OCA\OpenRegister\Service\ObjectService The object service.
     *
     * @throws \RuntimeException If OpenRegister is not available.
     */
    public function getObjectService(): \OCA\OpenRegister\Service\ObjectService
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Exception $e) {
            throw new \RuntimeException('OpenRegister service is not available.');
        }
    }//end getObjectService()

    /**
     * Get the configured register and schema for contactmomenten.
     *
     * @return array{register: string, schema: string} The register and schema IDs.
     *
     * @throws \RuntimeException If configuration is missing.
     */
    public function getConfig(): array
    {
        $settings = $this->settingsService->getSettings();
        $register = $settings['register'] ?? '';
        $schema   = $settings['contactmoment_schema'] ?? '';

        if ($register === '' || $schema === '') {
            throw new \RuntimeException('Contactmoment register or schema not configured.');
        }

        return [
            'register' => $register,
            'schema'   => $schema,
        ];
    }//end getConfig()

    /**
     * Delete a contactmoment with permission checking.
     *
     * Only the creating agent or a Nextcloud admin may delete.
     *
     * @param string $id            The contactmoment object UUID.
     * @param string $currentUserId The ID of the user requesting deletion.
     *
     * @return bool True if deleted successfully.
     *
     * @throws DoesNotExistException  If contactmoment not found.
     * @throws NotPermittedException  If user lacks permission.
     *
     * @spec openspec/changes/contactmomenten/tasks.md#task-1.1
     */
    public function delete(string $id, string $currentUserId): bool
    {
        $objectService = $this->getObjectService();
        $config        = $this->getConfig();

        // Fetch the object to check ownership.
        $object = $objectService->find(
            id: $id,
            register: $config['register'],
            schema: $config['schema']
        );

        if ($object === null) {
            throw new DoesNotExistException(
                'Contactmoment not found: '.$id
            );
        }

        $objectArray = $object->getObject();
        $agent       = $objectArray['agent'] ?? '';
        $isCreator   = ($agent === $currentUserId);
        $isAdmin     = $this->groupManager->isAdmin($currentUserId);

        if ($isCreator === false && $isAdmin === false) {
            throw new NotPermittedException(
                'Only the creating agent or an admin can delete this contactmoment.'
            );
        }

        $objectService
            ->setRegister($config['register'])
            ->setSchema($config['schema'])
            ->deleteObject(uuid: $id);

        $this->logger->info(
            'Contactmoment deleted',
            [
                'id'     => $id,
                'userId' => $currentUserId,
            ]
        );

        return true;
    }//end delete()
}//end class
