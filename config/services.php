<?php

declare(strict_types=1);

use Survos\TranslatorBundle\Service\TranslatorManager;
use Survos\TranslatorBundle\Service\TranslatorRegistry;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $c): void {
    $s = $c->services()->defaults()->autowire()->autoconfigure();

    // public so apps can inject them easily
    $s->set(TranslatorRegistry::class)->public();
    $s->set(TranslatorManager::class)->public();
};
