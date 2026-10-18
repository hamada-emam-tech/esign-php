<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Tests\Unit;

use HamadaEmamTech\Esign\Console\Application;
use HamadaEmamTech\Esign\Discovery\ProviderRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConsoleTest extends TestCase
{
    private string $project;

    /** @var list<array{list<string>, string}> */
    private array $ran = [];

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/esign-console-' . bin2hex(random_bytes(4));
        mkdir($this->project);
        file_put_contents($this->project . '/composer.json', '{}');
        // A fake composer on the PATH, so the command is resolvable without running anything real.
        mkdir($this->project . '/bin');
        file_put_contents($this->project . '/bin/composer', "#!/bin/sh\n");
        chmod($this->project . '/bin/composer', 0755);
        putenv('COMPOSER_BINARY=' . $this->project . '/bin/composer');
    }

    protected function tearDown(): void
    {
        putenv('COMPOSER_BINARY');
        @unlink($this->project . '/bin/composer');
        @rmdir($this->project . '/bin');
        @unlink($this->project . '/composer.json');
        @rmdir($this->project);
    }

    /**
     * @param list<string> $argv
     *
     * @return array{int, string}
     */
    private function esign(array $argv, ?string $cwd = null): array
    {
        $out = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        $app = new Application(
            new ProviderRegistry(__DIR__ . '/../Fixtures/installed.json'),
            function (array $command, string $cwd): int {
                $this->ran[] = [$command, $cwd];

                return 0;
            },
            $out,
            $cwd ?? $this->project,
        );
        $code = $app->run($argv);
        rewind($out);

        return [$code, (string) stream_get_contents($out)];
    }

    #[Test]
    public function providers_lists_the_catalog_and_what_is_installed(): void
    {
        [$code, $output] = $this->esign(['providers']);

        self::assertSame(0, $code);
        self::assertMatchesRegularExpression('/^sadq\s+SADQ\s+KSA\s+available\s+yes$/m', $output);   // installed via the fixture
        self::assertMatchesRegularExpression('/^docusign\s+DocuSign\s+global\s+planned\s+no$/m', $output);
        self::assertMatchesRegularExpression('/^fake\s+.*built-in\s+yes$/m', $output);
    }

    #[Test]
    public function add_runs_composer_require_for_the_provider_package(): void
    {
        [$code, $output] = $this->esign(['add', 'sadq']);

        self::assertSame(0, $code);
        self::assertSame([[[$this->project . '/bin/composer', 'require', 'hamada-emam-tech/esign-sadq'], $this->project]], $this->ran);
        self::assertStringContainsString('require hamada-emam-tech/esign-sadq', $output);
    }

    #[Test]
    public function remove_runs_composer_remove(): void
    {
        $this->esign(['remove', 'SADQ']);

        self::assertSame('remove', $this->ran[0][0][1]);
        self::assertSame('hamada-emam-tech/esign-sadq', $this->ran[0][0][2]);
    }

    #[Test]
    public function dry_run_prints_without_running(): void
    {
        [$code, $output] = $this->esign(['add', 'sadq', '--dry-run']);

        self::assertSame(0, $code);
        self::assertSame([], $this->ran);
        self::assertStringContainsString('> ', $output);
    }

    #[Test]
    public function planned_unknown_and_built_in_providers_are_refused_before_composer_runs(): void
    {
        foreach ([['docusign', 'planned but not released'], ['nope', 'Unknown provider'], ['fake', 'built into the core']] as [$name, $message]) {
            [$code, $output] = $this->esign(['add', $name]);
            self::assertSame(1, $code, $name);
            self::assertStringContainsString($message, $output);
        }
        self::assertSame([], $this->ran);
    }

    #[Test]
    public function it_needs_a_composer_json_in_the_working_directory(): void
    {
        [$code, $output] = $this->esign(['add', 'sadq'], sys_get_temp_dir());

        self::assertSame(1, $code);
        self::assertStringContainsString('No composer.json', $output);
    }
}
