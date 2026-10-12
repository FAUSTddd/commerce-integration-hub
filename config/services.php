<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure();

    $services->load('App\', __DIR__ . '/../src/')
        ->exclude([
            __DIR__ . '/../src/Kernel.php',
            __DIR__ . '/../src/Domain/Model/',
        ]);

    // Explicitly configure FaustDDD bundle Import1CController
    $services->set(FaustDDD\Symfony1cImport\Infrastructure\Controller\Import1CController::class)
        ->args([
            service(FaustDDD\Symfony1cImport\Application\Service\Cml1cInteractor::class),
        ])
        ->tag('controller.service_arguments')
        ->tag('container.service_subscriber')
        ->public();
};
