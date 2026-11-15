<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Pest;

use Desemax\AgentHarness\Agent\AgentAdapter;
use Desemax\AgentHarness\Agent\HttpAgentAdapter;
use Desemax\AgentHarness\Assertion\EvaluatorRegistry;
use Desemax\AgentHarness\Config\ConfigLoader;
use Desemax\AgentHarness\Config\HarnessConfig;
use Desemax\AgentHarness\Llm\LlmClient;
use Desemax\AgentHarness\Llm\OpenAiCompatibleClient;
use Desemax\AgentHarness\Runner\ScenarioResult;
use Desemax\AgentHarness\Runner\ScenarioRunner;
use Desemax\AgentHarness\Scenario\ExpectationType;
use Desemax\AgentHarness\Scenario\Scenario;
use Desemax\AgentHarness\Scenario\ScenarioLoader;
use Desemax\AgentHarness\Scenario\UserMode;
use InvalidArgumentException;

/**
 * Builder behind conversation(): one scenario against one agent, inside a test.
 *
 *     $result = conversation(__DIR__ . '/scenarios/happy-booking.yaml')
 *         ->against('http://127.0.0.1:8787/chat')
 *         ->run();
 */
final class Conversation
{
    private string|AgentAdapter|null $agent = null;

    private ?string $configFile = null;

    private ?LlmClient $llm = null;

    private ?int $seed = null;

    /**
     * @param string|Scenario $scenario path to one scenario file, or a Scenario object
     */
    public function __construct(private readonly string|Scenario $scenario) {}

    /**
     * The agent to talk to: its chat URL, or any AgentAdapter.
     */
    public function against(string|AgentAdapter $agent): self
    {
        $clone = clone $this;
        $clone->agent = $agent;

        return $clone;
    }

    /**
     * A harness.yaml for request/response mapping, headers, timeout and the llm section.
     */
    public function withConfig(string $path): self
    {
        $clone = clone $this;
        $clone->configFile = $path;

        return $clone;
    }

    /**
     * The LLM for an LLM-played user and judge expectations, instead of the one in the config.
     */
    public function withLlm(LlmClient $llm): self
    {
        $clone = clone $this;
        $clone->llm = $llm;

        return $clone;
    }

    public function withSeed(int $seed): self
    {
        $clone = clone $this;
        $clone->seed = $seed;

        return $clone;
    }

    /**
     * @throws InvalidArgumentException when the scenario, config or agent cannot be used
     * @throws \Desemax\AgentHarness\Scenario\InvalidScenarioException when the scenario file is invalid
     * @throws \Desemax\AgentHarness\Config\InvalidConfigException when the config file is invalid
     * @throws \Desemax\AgentHarness\Llm\LlmException when the LLM API key variable is not set
     */
    public function run(): ScenarioResult
    {
        $scenario = $this->scenario();
        $config = $this->configFile === null ? new HarnessConfig() : (new ConfigLoader())->load($this->configFile);

        $agent = $this->agent;
        if (is_string($agent)) {
            if (preg_match('#^https?://#i', $agent) !== 1) {
                throw new InvalidArgumentException(sprintf('against() needs a URL starting with http:// or https://, got "%s".', $agent));
            }
            $config = $config->withUrl($agent);
        }
        if (!$agent instanceof AgentAdapter) {
            if ($config->url === null) {
                throw new InvalidArgumentException('No agent: call against() with a URL or an AgentAdapter, or set agent.url in the config.');
            }
            $agent = new HttpAgentAdapter($config);
        }

        $llm = $this->llm;
        if ($llm === null && $config->llm !== null && self::needsLlm($scenario)) {
            $llm = OpenAiCompatibleClient::fromConfig($config->llm);
        }

        return (new ScenarioRunner(EvaluatorRegistry::withLlm($llm, $this->seed), llm: $llm, seed: $this->seed))->run($scenario, $agent);
    }

    private function scenario(): Scenario
    {
        if ($this->scenario instanceof Scenario) {
            return $this->scenario;
        }
        if (!is_file($this->scenario)) {
            throw new InvalidArgumentException(sprintf('conversation() needs one scenario file; not a file: %s', $this->scenario));
        }

        return (new ScenarioLoader())->load($this->scenario)[0];
    }

    private static function needsLlm(Scenario $scenario): bool
    {
        if ($scenario->user->mode === UserMode::Llm) {
            return true;
        }
        foreach ($scenario->expect as $expectation) {
            if ($expectation->type === ExpectationType::Judge) {
                return true;
            }
        }

        return false;
    }
}
