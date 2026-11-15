<?php

declare(strict_types=1);

/*
 * Pest plugin: loaded through composer autoload.files. It only does something when Pest is installed,
 * so the agent-harness CLI keeps working without it.
 */

use Desemax\AgentHarness\Pest\Conversation;
use Desemax\AgentHarness\Pest\ScenarioAssertions;
use Desemax\AgentHarness\Scenario\Scenario;
use Pest\Expectation;

if (class_exists(Expectation::class)) {
    if (!function_exists('conversation')) {
        /**
         * Starts a conversation test for one scenario file or Scenario object.
         */
        function conversation(string|Scenario $scenario): Conversation
        {
            return new Conversation($scenario);
        }
    }

    ScenarioAssertions::register();
}
