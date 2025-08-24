<?php
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Survos\TranslatorBundle\Service\{TranslatorRegistry, TranslatorManager};

return static function (ContainerConfigurator $c): void {
    $s = $c->services()->defaults()->autowire()->autoconfigure();

    // public so apps can inject them easily
    $s->set(TranslatorRegistry::class)->public();
    $s->set(TranslatorManager::class)->public();
};
