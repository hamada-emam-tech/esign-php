<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Console;

use HamadaEmamTech\Esign\Catalog\CatalogEntry;
use HamadaEmamTech\Esign\Catalog\ProviderCatalog;
use HamadaEmamTech\Esign\Catalog\ProviderStatus;
use HamadaEmamTech\Esign\Discovery\ProviderRegistry;

/**
 * `vendor/bin/esign`: see every provider and add or remove one from inside the core.
 *
 *     vendor/bin/esign providers          # what exists, what is installed
 *     vendor/bin/esign add sadq           # runs: composer require hamada-emam-tech/esign-sadq
 *     vendor/bin/esign remove sadq        # runs: composer remove hamada-emam-tech/esign-sadq
 *
 * It only ever runs Composer, so the result is a normal composer.json entry you can
 * update or remove with Composer directly.
 */
final class Application
{
    /** @var callable(list<string>, string): int */
    private $runner;

    /** @var resource */
    private $out;

    /**
     * @param (callable(list<string>, string): int)|null $runner runs a command in a directory, returns the exit code
     * @param resource|null $out
     */
    public function __construct(
        private readonly ?ProviderRegistry $registry = null,
        ?callable $runner = null,
        $out = null,
        private readonly ?string $cwd = null,
    ) {
        $this->runner = $runner ?? self::passthru(...);
        $this->out = $out ?? STDOUT;
    }

    /** @param list<string> $argv without the script name */
    public function run(array $argv): int
    {
        $dryRun = in_array('--dry-run', $argv, true);
        $args = array_values(array_filter($argv, fn (string $a) => $a !== '--dry-run'));
        $command = array_shift($args) ?? 'help';

        return match ($command) {
            'providers', 'list' => $this->providers(),
            'add', 'install' => $this->change('require', $args, $dryRun),
            'remove', 'uninstall' => $this->change('remove', $args, $dryRun),
            'help', '--help', '-h' => $this->help(),
            default => $this->fail("Unknown command \"{$command}\".\n\n" . self::USAGE),
        };
    }

    private const USAGE = <<<'TXT'
        Usage:
          esign providers              List every provider and whether it is installed
          esign add <name>...          Install providers (runs composer require)
          esign remove <name>...       Uninstall providers (runs composer remove)

        Options:
          --dry-run                    Print the composer command without running it
        TXT;

    private function help(): int
    {
        $this->line(self::USAGE);

        return 0;
    }

    private function providers(): int
    {
        $installed = $this->installedNames();
        $this->line(sprintf('%-10s %-28s %-7s %-10s %s', 'NAME', 'PROVIDER', 'REGION', 'STATUS', 'INSTALLED'));
        foreach (ProviderCatalog::all() as $entry) {
            $isInstalled = in_array($entry->name, $installed, true);
            $this->line(sprintf(
                '%-10s %-28s %-7s %-10s %s',
                $entry->name,
                $entry->label,
                $entry->region,
                $entry->status->value,
                $isInstalled ? 'yes' : ($entry->status === ProviderStatus::Available ? "no  -> esign add {$entry->name}" : 'no'),
            ));
        }
        foreach (array_diff($installed, array_keys(ProviderCatalog::all())) as $custom) {
            $this->line(sprintf('%-10s %-28s %-7s %-10s %s', $custom, '(registered by a package)', '-', 'custom', 'yes'));
        }
        $this->line('');
        $this->line('Roadmap: ' . ProviderCatalog::ROADMAP_URL);

        return 0;
    }

    /** @param list<string> $names */
    private function change(string $composerVerb, array $names, bool $dryRun): int
    {
        if ($names === []) {
            return $this->fail("Name at least one provider, e.g.: esign add sadq\n\n" . self::USAGE);
        }

        $packages = [];
        foreach ($names as $name) {
            $entry = ProviderCatalog::find($name);
            $problem = $this->problemWith($entry, $name);
            if ($problem !== null) {
                return $this->fail($problem);
            }
            /** @var CatalogEntry $entry */
            $packages[] = $entry->package;
        }

        $cwd = $this->cwd ?? (string) getcwd();
        if (! is_file($cwd . '/composer.json')) {
            return $this->fail("No composer.json in {$cwd}. Run this from your project's root.");
        }

        $composer = self::composerCommand($cwd);
        if ($composer === null) {
            return $this->fail("Composer was not found. Install it, or run:\n  composer {$composerVerb} " . implode(' ', $packages));
        }

        $command = [...$composer, $composerVerb, ...$packages];
        $this->line('> ' . implode(' ', array_map(self::display(...), $command)));
        if ($dryRun) {
            return 0;
        }

        return ($this->runner)($command, $cwd);
    }

    private function problemWith(?CatalogEntry $entry, string $name): ?string
    {
        return match (true) {
            $entry === null => sprintf('Unknown provider "%s". Run "esign providers" to see them all.', $name),
            $entry->status === ProviderStatus::BuiltIn => sprintf('"%s" is built into the core. Nothing to install.', $entry->name),
            $entry->status === ProviderStatus::Planned => sprintf('"%s" (%s) is planned but not released yet. Roadmap: %s', $entry->name, $entry->label, ProviderCatalog::ROADMAP_URL),
            default => null,
        };
    }

    /** @return list<string> */
    private function installedNames(): array
    {
        return ($this->registry ?? new ProviderRegistry())->names();
    }

    /**
     * How to call Composer: the binary Composer itself exported, then `composer` on the PATH,
     * then a composer.phar in the project.
     *
     * @return list<string>|null
     */
    public static function composerCommand(string $cwd): ?array
    {
        $candidates = [];
        if (($fromEnv = getenv('COMPOSER_BINARY')) !== false && $fromEnv !== '') {
            $candidates[] = $fromEnv;
        }
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            if ($dir !== '') {
                $candidates[] = $dir . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'composer.bat' : 'composer');
            }
        }
        $candidates[] = $cwd . DIRECTORY_SEPARATOR . 'composer.phar';

        foreach ($candidates as $path) {
            if (! is_file($path)) {
                continue;
            }
            if (str_ends_with($path, '.phar')) {
                return [PHP_BINARY, $path];
            }
            if (is_executable($path)) {
                return [$path];
            }
        }

        return null;
    }

    /** @param list<string> $command */
    private static function passthru(array $command, string $cwd): int
    {
        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $cwd);

        return is_resource($process) ? proc_close($process) : 1;
    }

    private static function display(string $part): string
    {
        return preg_match('/^[\w.\/:@=-]+$/', $part) === 1 ? $part : escapeshellarg($part);
    }

    private function fail(string $message): int
    {
        $this->line($message);

        return 1;
    }

    private function line(string $text): void
    {
        fwrite($this->out, $text . PHP_EOL);
    }
}
