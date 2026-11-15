<?php

declare(strict_types=1);

namespace Desemax\AgentHarness\Command;

use Desemax\AgentHarness\Report\ReportWriter;
use Desemax\AgentHarness\Security\TerminalText;
use Override;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'init', description: 'Create a commented harness.yaml and an example scenario in the current directory')]
final class InitCommand extends Command
{
    public const string NAME = 'init';

    private const string CONFIG = <<<'YAML'
        # yaml-language-server: $schema=vendor/desemax/agent-harness/schema/harness.schema.json
        # Agent endpoint. It can also be supplied with --url.
        agent:
          url: http://127.0.0.1:8787/chat
          timeout_ms: 10000
          # headers:
          #   Authorization: Bearer replace-me
          follow_redirects: false
          max_response_bytes: 1048576

        # Map the JSON fields used by your agent. Dot-paths address nested fields.
        request:
          session_field: session_id
          message_field: message
        response:
          reply: reply
          tool_calls: tool_calls
          tool_name: name
          tool_arguments: arguments
          handoff: handoff

        # Optional: enables LLM-played users and judge expectations.
        # llm:
        #   base_url: https://llm.example.com/v1
        #   model: your-model-name
        #   api_key_env: HARNESS_LLM_API_KEY
        #   timeout_ms: 30000
        #   max_tokens: 400
        #   max_response_bytes: 1048576
        YAML;

    private const string SCENARIO = <<<'YAML'
        # yaml-language-server: $schema=../vendor/desemax/agent-harness/schema/scenario.schema.json
        name: Example conversation
        persona: A customer who answers every question
        goal: Check that the agent answers helpfully
        user:
          mode: scripted
          turns:
            - Hello
        max_turns: 2
        expect:
          - said: hello
          - handoff: false
        YAML;

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root = getcwd();
        if ($root === false) {
            $output->writeln('<error>Cannot determine the current directory. Run init from a writable project directory.</error>');

            return Command::FAILURE;
        }

        $files = [$root . '/harness.yaml' => self::CONFIG, $root . '/scenarios/example.yaml' => self::SCENARIO];
        foreach (array_keys($files) as $file) {
            if (file_exists($file) || is_link($file)) {
                $output->writeln(sprintf('<error>Refusing to overwrite %s. Move it aside or run init in an empty project directory.</error>', TerminalText::console($file)));

                return Command::FAILURE;
            }
        }

        try {
            ReportWriter::directory($root . '/scenarios');
            foreach ($files as $file => $contents) {
                $this->create($file, $contents . "\n");
                $output->writeln('Created ' . TerminalText::console($file));
            }
        } catch (RuntimeException $e) {
            $output->writeln('<error>' . TerminalText::console($e->getMessage()) . '</error>');

            return Command::FAILURE;
        }

        $output->writeln('');
        $output->writeln('Next steps:');
        $output->writeln('  1. Set agent.url in harness.yaml, or pass --url to the run command.');
        $output->writeln('  2. Edit scenarios/example.yaml and add scenarios for your agent.');
        $output->writeln('  3. Run: agent-harness validate scenarios');
        $output->writeln('  4. Run: agent-harness run scenarios');
        $output->writeln('Start the bundled demo agent with "composer demo" to try the example locally.');

        return Command::SUCCESS;
    }

    private function create(string $file, string $contents): void
    {
        set_error_handler(static fn(): bool => true);
        try {
            $handle = fopen($file, 'x');
            if ($handle === false) {
                throw new RuntimeException(sprintf('Cannot create %s. Check that it does not exist and the directory is writable.', $file));
            }
            try {
                if (fwrite($handle, $contents) !== strlen($contents)) {
                    throw new RuntimeException(sprintf('Could not write all of %s. Check the available disk space.', $file));
                }
            } finally {
                fclose($handle);
            }
        } finally {
            restore_error_handler();
        }
    }
}
