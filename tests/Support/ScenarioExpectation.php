<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Tests\Support;

use Pest\Expectation;

/**
 * Typed front for the scenario expectations the plugin registers with Pest (toPassScenario, ...).
 *
 * Pest resolves extensions through Expectation::__call, which static analysis cannot see; this
 * class calls that same entry point, so the tests still go through the registered extensions.
 */
final readonly class ScenarioExpectation
{
    /**
     * @param Expectation<mixed> $expectation
     */
    private function __construct(private Expectation $expectation, private bool $negated = false) {}

    public static function of(mixed $value): self
    {
        return new self(new Expectation($value));
    }

    /**
     * The next expectation must fail (Pest's ->not).
     */
    public function not(): self
    {
        return new self($this->expectation, true);
    }

    /**
     * @template TValue
     *
     * @param TValue $value
     *
     * @return Expectation<TValue>
     */
    public function and(mixed $value): Expectation
    {
        return $this->expectation->and($value);
    }

    public function toPassScenario(): self
    {
        return $this->call('toPassScenario', []);
    }

    /**
     * @param ?array<mixed> $args
     */
    public function toHaveCalledTool(string $name, ?array $args = null): self
    {
        return $this->call('toHaveCalledTool', $args === null ? [$name] : [$name, $args]);
    }

    public function toHaveSaid(string $text): self
    {
        return $this->call('toHaveSaid', [$text]);
    }

    public function toHaveHandedOff(): self
    {
        return $this->call('toHaveHandedOff', []);
    }

    /**
     * @param list<mixed> $arguments
     */
    private function call(string $name, array $arguments): self
    {
        if ($this->negated) {
            $this->expectation->not()->__call($name, $arguments);

            return new self($this->expectation);
        }
        $this->expectation->__call($name, $arguments);

        return $this;
    }
}
