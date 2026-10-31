<?php

declare(strict_types=1);

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;

require __DIR__.'/bootstrap.php';

$application = new Application(new Kernel('test', true));
$application->setAutoExit(false);

foreach (['doctrine:migrations:migrate', 'doctrine:schema:validate'] as $command) {
    $status = $application->run(new ArrayInput(['command' => $command, '--env' => 'test', '--no-interaction' => true]));
    if (0 !== $status) {
        exit($status);
    }
}
