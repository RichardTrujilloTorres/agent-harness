<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Command;

use Closure;
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
use Desemax\AgentHarness\Report\ResultData;
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
use Desemax\AgentHarness\Scenario\ValidationError;
use Desemax\AgentHarness\Security\BoundedHttp;
use Desemax\AgentHarness\Security\Redactor;
use Desemax\AgentHarness\Security\TerminalText;
use InvalidArgumentException;
use Override;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'run', description: 'Play scenarios against an agent over HTTP and report pass or fail')]
final class RunCommand extends Command
{
    public const string NAME = 'run';
    public const int EXIT_PASSED = 0;
    public const int EXIT_FAILED = 1;
    public const int EXIT_BAD_INPUT = 2;
    public const int EXIT_AGENT_ERROR = 3;

    private const array STYLES = [
        'passed' => 'fg=green',
        'failed' => 'fg=red',
        'skipped' => 'fg=yellow',
        'error' => 'fg=red;options=bold',
        'flaky' => 'fg=yellow;options=bold',
    ];

    /**
     * @param ?Closure(LlmConfig): LlmClient $llmFactory builds the LLM client from the llm section (default: OpenAI-compatible over HTTP)
     */
    public function __construct(
        private readonly ScenarioLoader $loader = new ScenarioLoader(),
        private readonly ConfigLoader $configLoader = new ConfigLoader(),
        private readonly ?Closure $llmFactory = null,
    ) {
        parent::__construct();
    }

    #[Override]
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
            ->addOption('fail-fast', null, InputOption::VALUE_NONE, 'Stop after the first scenario that fails or errors')
            ->setHelp(<<<'HELP'
                Exit codes:
                  0  no failures (skipped and flaky scenarios allowed)
                  1  at least one scenario failed
                  2  bad path, bad config, bad option or invalid scenarios
                  3  at least one scenario errored (agent or LLM unreachable, bad response, judge without a usable answer)
                HELP)
            ->getDefinition()->addOption(OutputFormat::option());
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = OutputFormat::fromInput($input);
        if (!$format instanceof OutputFormat) {
            return $this->fail($output, OutputFormat::Text, '--format must be "text" or "json".');
        }
        $path = $input->getArgument('path');
        $url = $input->getOption('url');
        $configFile = $input->getOption('config');
        $filter = $input->getOption('filter');
        if (!is_string($path) || ($url !== null && !is_string($url)) || ($configFile !== null && !is_string($configFile)) || ($filter !== null && !is_string($filter))) {
            return $this->fail($output, $format, 'Arguments and options must be strings.');
        }
        $seed = $this->intOption($input->getOption('seed'));
        if ($input->getOption('seed') !== null && $seed === null) {
            return $this->fail($output, $format, sprintf('--seed must be an integer, got "%s".', $this->display($input->getOption('seed'))));
        }
        $reports = [];
        foreach (['report-html' => 'build/report.html', 'report-junit' => 'build/junit.xml', 'transcripts' => 'build/transcripts'] as $option => $example) {
            $value = $input->getOption($option);
            if ($value === null) {
                continue;
            }
            if (!is_string($value) || trim($value) === '') {
                return $this->fail($output, $format, sprintf('--%s needs a path, for example --%s=%s.', $option, $option, $example));
            }
            $reports[$option] = $value;
        }
        $retries = $this->intOption($input->getOption('retries'));
        if ($retries === null || $retries < 0) {
            return $this->fail($output, $format, sprintf('--retries must be an integer >= 0, got "%s".', $this->display($input->getOption('retries'))));
        }

        try {
            $files = $this->loader->files($path);
        } catch (InvalidArgumentException $e) {
            return $this->fail($output, $format, $e->getMessage());
        }
        if ($files === []) {
            return $this->fail($output, $format, sprintf('No .yaml or .yml files found in %s. Scenario files need a .yaml or .yml extension.', $path));
        }
        $printer = new ValidationPrinter($this->loader);
        $validation = $printer->results($files);
        if (ValidationPrinter::invalid($validation) > 0) {
            if ($format === OutputFormat::Json) {
                return OutputFormat::writeJson($output, self::NAME, self::EXIT_BAD_INPUT, ['error' => 'Invalid scenario files; fix them and run again (see "validation").', 'validation' => ValidationPrinter::json($validation)]);
            }
            $printer->print($validation, $output);

            return self::EXIT_BAD_INPUT;
        }

        $configFile ??= $this->defaultConfigFile();
        try {
            $config = $configFile === null ? new HarnessConfig() : $this->configLoader->load($configFile);
        } catch (InvalidArgumentException $e) {
            return $this->fail($output, $format, $e->getMessage());
        } catch (InvalidConfigException $e) {
            return $this->invalidConfig($output, $format, $configFile, $e);
        }

        if ($url !== null) {
            if (!BoundedHttp::isHttpUrl($url)) {
                return $this->fail($output, $format, sprintf('--url must be an http:// or https:// URL with a host, got "%s".', $url));
            }
            $config = $config->withUrl($url);
        }
        if ($config->url === null) {
            return $this->fail($output, $format, 'No agent URL: pass --url or set agent.url in ' . ConfigLoader::DEFAULT_FILE . '.');
        }

        $scenarios = $this->loader->load($path);
        if ($filter !== null && $filter !== '') {
            $scenarios = array_values(array_filter($scenarios, static fn(Scenario $s): bool => mb_stripos($s->name, $filter) !== false));
            if ($scenarios === []) {
                return $this->fail($output, $format, sprintf('No scenario name contains "%s". Run "agent-harness list %s" to see the names.', $filter, $path));
            }
        }

        $redactor = new Redactor($this->secrets($config));
        $llm = null;
        if ($config->llm instanceof LlmConfig && $this->needsLlm($scenarios)) {
            try {
                $llm = $this->llmFactory instanceof Closure ? ($this->llmFactory)($config->llm) : OpenAiCompatibleClient::fromConfig($config->llm);
            } catch (LlmException $e) {
                return $this->fail($output, $format, $redactor->text($e->getMessage()));
            }
        }

        $runner = new SuiteRunner(new ScenarioRunner(EvaluatorRegistry::withLlm($llm, $seed), llm: $llm, seed: $seed), $retries);
        $live = $format === OutputFormat::Text ? $output : new NullOutput();
        $suite = $redactor->suite($runner->run(
            $scenarios,
            new HttpAgentAdapter($config),
            fn(ScenarioResult $result) => $this->print($redactor->result($result), $live),
            $input->getOption('fail-fast') === true,
        ));
        $exit = match (true) {
            $suite->count(ScenarioStatus::Error) > 0 => self::EXIT_AGENT_ERROR,
            $suite->count(ScenarioStatus::Failed) > 0 => self::EXIT_FAILED,
            default => self::EXIT_PASSED,
        };
        $this->summary($suite, count($scenarios), $live);

        try {
            $written = $this->writeReports($suite, $reports, $live);
        } catch (RuntimeException $e) {
            return $this->fail($output, $format, $e->getMessage());
        }

        if ($format === OutputFormat::Json) {
            return OutputFormat::writeJson($output, self::NAME, $exit, [
                'summary' => ['scenarios' => count($scenarios), 'run' => count($suite->results)] + $suite->counts() + ['duration_ms' => $suite->durationMs],
                'scenarios' => array_map(ResultData::scenario(...), $suite->results),
                'reports' => (object) $written,
            ]);
        }

        return $exit;
    }

    /**
     * Values that must never be printed: configured header values and the LLM API key.
     *
     * @return list<string>
     */
    private function secrets(HarnessConfig $config): array
    {
        $secrets = array_values($config->headers);
        if ($config->llm instanceof LlmConfig) {
            $key = getenv($config->llm->apiKeyEnv);
            if (is_string($key) && $key !== '') {
                $secrets[] = $key;
            }
        }

        return $secrets;
    }

    /**
     * @param array<string, string> $reports option name => path
     * @return array<string, string|list<string>> what was written: html, junit, transcripts
     *
     * @throws RuntimeException when a file cannot be written
     */
    private function writeReports(SuiteResult $suite, array $reports, OutputInterface $output): array
    {
        $written = [];
        if (isset($reports['report-html'])) {
            ReportWriter::write($reports['report-html'], (new HtmlReport())->render($suite));
            $output->writeln('HTML report: ' . TerminalText::console($reports['report-html']));
            $written['html'] = $reports['report-html'];
        }
        if (isset($reports['report-junit'])) {
            ReportWriter::write($reports['report-junit'], (new JunitReport())->render($suite));
            $output->writeln('JUnit report: ' . TerminalText::console($reports['report-junit']));
            $written['junit'] = $reports['report-junit'];
        }
        if (isset($reports['transcripts'])) {
            $paths = (new TranscriptReport())->write($suite, $reports['transcripts']);
            $output->writeln(sprintf('Transcripts: %d file(s) in %s', count($paths), TerminalText::console($reports['transcripts'])));
            $written['transcripts'] = $paths;
        }

        return $written;
    }

    private function intOption(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value) || preg_match('/^-?\d+$/', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }

    private function display(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : get_debug_type($value);
    }

    /**
     * @param list<Scenario> $scenarios
     */
    private function needsLlm(array $scenarios): bool
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

    private function summary(SuiteResult $suite, int $total, OutputInterface $output): void
    {
        $counts = $suite->counts();
        $output->writeln('');
        $output->writeln(sprintf(
            '%d scenario(s): %d passed, %s%d failed, %d skipped, %d errors in %d ms%s',
            $total,
            $counts['passed'],
            $counts['flaky'] > 0 ? sprintf('%d flaky, ', $counts['flaky']) : '',
            $counts['failed'],
            $counts['skipped'],
            $counts['error'],
            $suite->durationMs,
            count($suite->results) < $total ? sprintf(' (stopped by --fail-fast, %d not run)', $total - count($suite->results)) : '',
        ));
    }

    /**
     * Prints one result. Every piece of text that can come from the agent, an LLM or a file is
     * stripped of terminal escape sequences and escaped for the console formatter.
     */
    private function print(ScenarioResult $result, OutputInterface $output): void
    {
        $turns = $result->transcript->count();
        $output->writeln(sprintf(
            '<%s>%s</>  %s  (%d %s, %d ms)',
            self::STYLES[$result->status->value],
            str_pad($result->status->label(), 5),
            TerminalText::console($result->scenario->name),
            $turns,
            $turns === 1 ? 'turn' : 'turns',
            $result->durationMs,
        ));

        if ($result->message !== null) {
            $output->writeln('       ' . TerminalText::console($result->message));
        }

        $verbose = $output->isVerbose();
        foreach ($result->expectations as $expectation) {
            if ($expectation->status === ExpectationStatus::Failed) {
                $output->writeln('       ✗ ' . TerminalText::console($expectation->reason));
            } elseif ($expectation->status === ExpectationStatus::Error) {
                $output->writeln('       ! ' . TerminalText::console($expectation->reason));
            } elseif ($verbose) {
                $mark = $expectation->status === ExpectationStatus::Passed ? '✓' : '~';
                $output->writeln(sprintf('       %s %s', $mark, TerminalText::console($expectation->status === ExpectationStatus::Skipped
                    ? sprintf('%s: skipped (%s)', $expectation->expectation->type->value, $expectation->reason)
                    : $expectation->reason)));
            }
        }

        foreach ($result->warnings as $warning) {
            $output->writeln('       <comment>⚠ ' . TerminalText::console($warning) . '</comment>');
        }

        if ($verbose && $turns > 0) {
            $output->writeln('       Transcript:');
            foreach ($result->transcript->lines() as $line) {
                $output->writeln('         ' . TerminalText::console($line));
            }
        }
    }

    private function invalidConfig(OutputInterface $output, OutputFormat $format, string $file, InvalidConfigException $e): int
    {
        if ($format === OutputFormat::Json) {
            return OutputFormat::writeJson($output, self::NAME, self::EXIT_BAD_INPUT, [
                'error' => sprintf('Invalid config %s; fix it and run again (see "config_errors").', $file),
                'config_errors' => array_map(static fn(ValidationError $error): array => ['path' => $error->path, 'message' => $error->reason], $e->errors),
            ]);
        }
        $output->writeln(sprintf('<error>Invalid config %s</error>', TerminalText::console($file)));
        foreach ($e->errors as $error) {
            $output->writeln(sprintf('  - %s: %s', TerminalText::console($error->path), TerminalText::console($error->reason)));
        }

        return self::EXIT_BAD_INPUT;
    }

    /**
     * ./harness.yaml when it exists, otherwise null (built-in defaults).
     */
    private function defaultConfigFile(): ?string
    {
        $default = getcwd() . DIRECTORY_SEPARATOR . ConfigLoader::DEFAULT_FILE;

        return is_file($default) ? $default : null;
    }

    private function fail(OutputInterface $output, OutputFormat $format, string $message): int
    {
        if ($format === OutputFormat::Json) {
            return OutputFormat::writeJson($output, self::NAME, self::EXIT_BAD_INPUT, ['error' => $message]);
        }
        $output->writeln(sprintf('<error>%s</error>', TerminalText::console($message)));

        return self::EXIT_BAD_INPUT;
    }
}
