<?php

declare(strict_types=1);

use Desemax\AgentHarness\Application;

it('boots with name and version', function (): void {
    $app = new Application();

    expect($app->getName())->toBe('agent-harness')
        ->and($app->getVersion())->toBe('0.1.0-dev');
});
