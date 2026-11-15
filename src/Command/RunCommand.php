<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Command;

use Closure;
use Desemax\AgentHarness\Agent\AgentAdapter;
use Desemax\AgentHarness\Agent\HttpAgentAdapter;
use Desemax\AgentHarness\Assertion\EvaluatorRegistry;
use Desemax\AgentHarness\Config\ConfigLoader;
use Desemax\AgentHarness\Config\HarnessConfig;
use Desemax\AgentHarness\Config\InvalidConfigException;
use Desemax\AgentHarness\Llm\LlmClient;
use Desemax\AgentHarness\Llm\LlmConfig;
use Desemax\AgentHarness\Llm\LlmException;
use Desemax\AgentHarness\Llm\OpenAiCompatibleClient;
use Desemax\AgentHarness\Report\HtmlReport;
use Desemax\AgentHarness\Report\JunitReport;
use Desemax\AgentHarness\Report\ReportWriter;
use Desemax\AgentHarness\Report\TranscriptReport;
use Desemax\AgentHarness\Runner\ExpectationStatus;
use Desemax\AgentHarness\Runner\ScenarioResult;
use Desemax\AgentHarness\Runner\ScenarioRunner;
use Desemax\AgentHarness\Runner\ScenarioStatus;
use Desemax\AgentHarness\Runner\SuiteResult;
use Desemax\AgentHarness\Runner\SuiteRunner;
use Desemax\AgentHarness\Scenario\ExpectationType;
use Desemax\AgentHarness\Scenario\Scenario;
use Desemax\AgentHarness\Scenario\ScenarioLoader;
use Desemax\AgentHarness\Scenario\UserMode;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'run', description: 'Play scenarios against an agent over HTTP and report pass or fail')]
final class RunCommand extends Command
{
    public const EXIT_PASSED = 0;
    public const EXIT_FAILED = 1;
    public const EXIT_BAD_INPUT = 2;
    public const EXIT_AGENT_ERROR = 3;

    private const STYLES = [
        'passed' => 'fg=green',
        'failed' => 'fg=red',
        'skipped' => 'fg=yellow',
        'error' => 'fg=red;options=bold',
        'flaky' => 'fg=yellow;options=bold',
    ];

    /** @var Closure(LlmConfig): LlmClient */
    private readonly Closure $llmFactory;

    /**
     * @param ?Closure(LlmConfig): LlmClient $llmFactory builds the LLM client from the llm section (default: OpenAI-compatible over HTTP)
     */
    public function __construct(
        private readonly ScenarioLoader $loader = new ScenarioLoader(),
        private readonly ConfigLoader $configLoader = new ConfigLoader(),
        ?Closure $llmFactory = null,
    ) {
        $this->llmFactory = $llmFactory ?? static fn(LlmConfig $config): LlmClient => OpenAiCompatibleClient::fromConfig($config);
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::REQUIRED, 'Scenario file or directory')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Agent URL (overrides agent.url)')
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Config file (default: ./' . ConfigLoader::DEFAULT_FILE . ' if present)')
            ->addOption('filter', null, InputOption::VALUE_REQUIRED, 'Only run scenarios whose name contains this text (case-insensitive)')
            ->addOption('seed', null, InputOption::VALUE_REQUIRED, 'Seed passed to the LLM for the LLM-played user and the judge')
            ->addOption('report-html', null, InputOption::VALUE_REQUIRED, 'Write a self-contained HTML report to this file')
            ->addOption('report-junit', null, InputOption::VALUE_REQUIRED, 'Write a JUnit XML report to this file')
            ->addOption('transcripts', null, InputOption::VALUE_REQUIRED, 'Write one JSON transcript per scenario into this directory')
            ->addOption('retries', null, InputOption::VALUE_REQUIRED, 'Re-run a failed or errored scenario up to this many times; passing on a retry is reported FLAKY', '0')
            ->setHelp(<<<'HELP'
                Exit codes:
                  0  no failures (skipped and flaky scenarios allowed)
                  1  at least one scenario failed
                  2  bad path, bad config or invalid scenarios
                  3  at least one scenario errored (agent or LLM unreachable, bad response, judge without a usable answer)
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $input->getArgument('path');
        $url = $input->getOption('url');
        $configFile = $input->getOption('config');
        $filter = $input->getOption('filter');
        if (!is_string($path) || ($url !== null && !is_string($url)) || ($configFile !== null && !is_string($configFile)) || ($filter !== null && !is_string($filter))) {
            return $this->fail($output, 'Arguments and options must be strings.');
        }
        $seed = self::intOption($input->getOption('seed'));
        if ($input->getOption('seed') !== null && $seed === null) {
            return $this->fail($output, '--seed must be an integer.');
        }
        $reports = [];
        foreach (['report-html', 'report-junit', 'transcripts'] as $option) {
            $value = $input->getOption($option);
            if ($value === null) {
                continue;
            }
            if (!is_string($value) || trim($value) === '') {
                return $this->fail($output, sprintf('--%s needs a path.', $option));
            }
            $reports[$option] = $value;
        }
        $retries = self::intOption($input->getOption('retries'));
        if ($retries === null || $retries < 0) {
            return $this->fail($output, '--retries must be an integer >= 0.');
        }

        try {
            $files = $this->loader->files($path);
        } catch (InvalidArgumentException $e) {
            return $this->fail($output, $e->getMessage());
        }
        if ($files === []) {
            return $this->fail($output, sprintf('No .yaml or .yml files found in %s', $path));
        }
        if ((new ValidationPrinter($this->loader))->check($files, $output, false) > 0) {
            return self::EXIT_BAD_INPUT;
        }

        $configFile ??= $this->defaultConfigFile();
        try {
            $config = $configFile === null ? new HarnessConfig() : $this->configLoader->load($configFile);
        } catch (InvalidArgumentException $e) {
            return $this->fail($output, $e->getMessage());
        } catch (InvalidConfigException $e) {
            $output->writeln(sprintf('<error>Invalid config %s</error>', $configFile));
            foreach ($e->errors as $error) {
                $output->writeln(sprintf('  - %s: %s', $error->path, $error->reason));
            }

            return self::EXIT_BAD_INPUT;
        }

        if ($url !== null) {
            if (preg_match('#^https?://#i', $url) !== 1) {
                return $this->fail($output, sprintf('--url must start with http:// or https://, got "%s"', $url));
            }
            $config = $config->withUrl($url);
        }
        if ($config->url === null) {
            return $this->fail($output, 'No agent URL: pass --url or set agent.url in ' . ConfigLoader::DEFAULT_FILE . '.');
        }

        $scenarios = $this->loader->load($path);
        if ($filter !== null && $filter !== '') {
            $scenarios = array_values(array_filter($scenarios, static fn(Scenario $s): bool => mb_stripos($s->name, $filter) !== false));
            if ($scenarios === []) {
                return $this->fail($output, sprintf('No scenario name contains "%s".', $filter));
            }
        }

        $llm = null;
        if ($config->llm !== null && self::needsLlm($scenarios)) {
            try {
                $llm = ($this->llmFactory)($config->llm);
            } catch (LlmException $e) {
                return $this->fail($output, $e->getMessage());
            }
        }

        $runner = new SuiteRunner(new ScenarioRunner(EvaluatorRegistry::withLlm($llm, $seed), llm: $llm, seed: $seed), $retries);

        $suite = $this->runAll($scenarios, $runner, new HttpAgentAdapter($config), $output);

        try {
            $this->writeReports($suite, $reports, $output);
        } catch (RuntimeException $e) {
            return $this->fail($output, $e->getMessage());
        }

        return match (true) {
            $suite->count(ScenarioStatus::Error) > 0 => self::EXIT_AGENT_ERROR,
            $suite->count(ScenarioStatus::Failed) > 0 => self::EXIT_FAILED,
            default => self::EXIT_PASSED,
        };
    }

    /**
     * @param array<string, string> $reports option name => path
     *
     * @throws RuntimeException when a file cannot be written
     */
    private function writeReports(SuiteResult $suite, array $reports, OutputInterface $output): void
    {
        if (isset($reports['report-html'])) {
            ReportWriter::write($reports['report-html'], (new HtmlReport())->render($suite));
            $output->writeln('HTML report: ' . $reports['report-html']);
        }
        if (isset($reports['report-junit'])) {
            ReportWriter::write($reports['report-junit'], (new JunitReport())->render($suite));
            $output->writeln('JUnit report: ' . $reports['report-junit']);
        }
        if (isset($reports['transcripts'])) {
            $paths = (new TranscriptReport())->write($suite, $reports['transcripts']);
            $output->writeln(sprintf('Transcripts: %d file(s) in %s', count($paths), $reports['transcripts']));
        }
    }

    private static function intOption(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value) || preg_match('/^-?\d+$/', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param list<Scenario> $scenarios
     */
    private static function needsLlm(array $scenarios): bool
    {
        foreach ($scenarios as $scenario) {
            if ($scenario->user->mode === UserMode::Llm) {
                return true;
            }
            foreach ($scenario->expect as $expectation) {
                if ($expectation->type === ExpectationType::Judge) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<Scenario> $scenarios
     */
    private function runAll(array $scenarios, SuiteRunner $runner, AgentAdapter $agent, OutputInterface $output): SuiteResult
    {
        $suite = $runner->run($scenarios, $agent, fn(ScenarioResult $result) => $this->print($result, $output));
        $counts = $suite->counts();

        $output->writeln('');
        $output->writeln(sprintf(
            '%d scenario(s): %d passed, %s%d failed, %d skipped, %d errors in %d ms',
            count($scenarios),
            $counts['passed'],
            $counts['flaky'] > 0 ? sprintf('%d flaky, ', $counts['flaky']) : '',
            $counts['failed'],
            $counts['skipped'],
            $counts['error'],
            $suite->durationMs,
        ));

        return $suite;
    }

    private function print(ScenarioResult $result, OutputInterface $output): void
    {
        $turns = $result->transcript->count();
        $output->writeln(sprintf(
            '<%s>%s</>  %s  (%d %s, %d ms)',
            self::STYLES[$result->status->value],
            str_pad($result->status->label(), 5),
            $result->scenario->name,
            $turns,
            $turns === 1 ? 'turn' : 'turns',
            $result->durationMs,
        ));

        if ($result->message !== null) {
            $output->writeln('       ' . $result->message);
        }

        $verbose = $output->isVerbose();
        foreach ($result->expectations as $expectation) {
            if ($expectation->status === ExpectationStatus::Failed) {
                $output->writeln('       ✗ ' . $expectation->reason);
            } elseif ($expectation->status === ExpectationStatus::Error) {
                $output->writeln('       ! ' . $expectation->reason);
            } elseif ($verbose) {
                $mark = $expectation->status === ExpectationStatus::Passed ? '✓' : '~';
                $output->writeln(sprintf('       %s %s', $mark, $expectation->status === ExpectationStatus::Skipped
                    ? sprintf('%s: skipped (%s)', $expectation->expectation->type->value, $expectation->reason)
                    : $expectation->reason));
            }
        }

        if ($verbose && $turns > 0) {
            $output->writeln('       Transcript:');
            foreach ($result->transcript->lines() as $line) {
                $output->writeln('         ' . $line);
            }
        }
    }

    /**
     * ./harness.yaml when it exists, otherwise null (built-in defaults).
     */
    private function defaultConfigFile(): ?string
    {
        $default = getcwd() . DIRECTORY_SEPARATOR . ConfigLoader::DEFAULT_FILE;

        return is_file($default) ? $default : null;
    }

    private function fail(OutputInterface $output, string $message): int
    {
        $output->writeln(sprintf('<error>%s</error>', $message));

        return self::EXIT_BAD_INPUT;
    }
}
