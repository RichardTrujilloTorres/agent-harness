<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Scenario;

enum UserMode: string
{
    case Scripted = 'scripted';
    case Llm = 'llm';
}
