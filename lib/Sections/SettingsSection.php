<?php

declare(strict_types=1);

namespace OCA\Pipelinq\Sections;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class SettingsSection implements IIconSection
{
    public function __construct(
        private IL10N $l,
        private IURLGenerator $urlGenerator,
    ) {
    }

    public function getID(): string
    {
        return 'pipelinq';
    }

    public function getName(): string
    {
        return $this->l->t('Pipelinq');
    }

    public function getPriority(): int
    {
        return 76;
    }

    public function getIcon(): string
    {
        return $this->urlGenerator->imagePath('pipelinq', 'app.svg');
    }
}
