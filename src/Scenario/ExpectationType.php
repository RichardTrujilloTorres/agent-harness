<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Scenario;

enum ExpectationType: string
{
    case ToolCalled = 'tool_called';
    case Said = 'said';
    case NeverSaid = 'never_said';
    case Handoff = 'handoff';
    case MaxTurns = 'max_turns';
    case LatencyMsUnder = 'latency_ms_under';
    case Judge = 'judge';
}
