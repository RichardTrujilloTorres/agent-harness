<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Assertion;

use Desemax\AgentHarness\Llm\LlmClient;
use Desemax\AgentHarness\Runner\ExpectationResult;
use Desemax\AgentHarness\Runner\Transcript;
use Desemax\AgentHarness\Scenario\Expectation;
use Desemax\AgentHarness\Scenario\ExpectationType;
use LogicException;

/**
 * Dispatches each expectation to the evaluator registered for its type.
 */
final class EvaluatorRegistry implements Evaluator
{
    /** @var array<string, Evaluator> keyed by ExpectationType value */
    private readonly array $evaluators;

    /**
     * @param array<string, Evaluator> $overrides evaluators keyed by ExpectationType value, replacing the defaults
     */
    public function __construct(array $overrides = [])
    {
        $this->evaluators = $overrides + [
            ExpectationType::ToolCalled->value => new ToolCalledEvaluator(),
            ExpectationType::Said->value => new SaidEvaluator(),
            ExpectationType::NeverSaid->value => new NeverSaidEvaluator(),
            ExpectationType::Handoff->value => new HandoffEvaluator(),
            ExpectationType::MaxTurns->value => new MaxTurnsEvaluator(),
            ExpectationType::LatencyMsUnder->value => new LatencyEvaluator(),
            ExpectationType::Judge->value => new JudgeEvaluator(),
        ];
    }

    /**
     * The default evaluators with a judge backed by the given LLM (skipped when null).
     */
    public static function withLlm(?LlmClient $llm, ?int $seed = null): self
    {
        return new self([ExpectationType::Judge->value => new JudgeEvaluator($llm, $seed)]);
    }

    public function for(ExpectationType $type): Evaluator
    {
        return $this->evaluators[$type->value] ?? throw new LogicException(sprintf('No evaluator registered for %s.', $type->value));
    }

    public function evaluate(Expectation $expectation, Transcript $transcript): ExpectationResult
    {
        return $this->for($expectation->type)->evaluate($expectation, $transcript);
    }
}
