<?php

declare(strict_types=1);

namespace HtmlPhp\Config;

use HtmlPhp\Config;

use HtmlPhp\Exception\ConfigException;

/**
 * SSI behaviour: document root, escaping, `#exec` policy and formats.
 */
final readonly class Ssi
{
    /**
     * @param list<string> $echoAllow allow-list of echo-able variable names (empty = all)
     * @param list<string> $execCmdAllow regex patterns matched against `cmd=` binaries
     * @param list<string> $execCgiExtensions allowed `cgi=` file extensions
     * @param (callable(string, array<string, string>): bool|string|null)|null $execDelegate
     *        host decision callback for {@see ExecMode::Delegate}: return `true`
     *        to run, `false` to deny, or a string to use as the output
     */
    public function __construct(
        public bool $enabled = true,
        public ?string $documentRoot = null,
        public bool $allowParentPaths = false,
        public bool $escapeEcho = true,
        public array $echoAllow = [],
        public string $timeFormat = '%A, %d-%b-%Y %H:%M:%S %Z',
        public SizeFormat $sizeFormat = SizeFormat::Abbrev,
        public ?string $errorMessage = null,
        public ExecMode $execMode = ExecMode::Deny,
        public array $execCmdAllow = [],
        public array $execCgiExtensions = ['php'],
        public string $execCgiMode = 'spawn',
        public mixed $execDelegate = null,
        public bool $processRenderedOutput = true,
        public bool $printenvEnabled = true
    ) {
        if ($execCgiMode !== 'spawn' && $execCgiMode !== 'include') {
            throw new ConfigException("ssi.exec_cgi_mode must be 'spawn' or 'include'.", ['value' => $execCgiMode]);
        }

        if ($execDelegate !== null && !is_callable($execDelegate)) {
            throw new ConfigException('ssi.exec_delegate must be callable or null.');
        }

        if ($execMode === ExecMode::Delegate && $execDelegate === null) {
            throw new ConfigException('ssi.exec_mode "delegate" requires ssi.exec_delegate.');
        }
    }

    /** @param array<string, mixed> $config */
    public static function fromArray(array $config): self
    {
        $execMode = Config::value($config, 'exec_mode', ExecMode::Deny->value);

        // v3 stored a boolean master switch; accept `true/false` as deny/allows-nothing.
        if (is_bool($execMode)) {
            $execMode = $execMode ? ExecMode::AllowList->value : ExecMode::Deny->value;
        }

        $sizeFormat = SizeFormat::tryFrom((string) Config::value($config, 'size_format', SizeFormat::Abbrev->value))
            ?? throw new ConfigException('ssi.size_format must be "abbrev" or "bytes".');

        return new self(
            enabled: Config::bool($config, 'enabled', true),
            documentRoot: Config::nullableString($config, 'document_root'),
            allowParentPaths: Config::bool($config, 'allow_parent_paths', false),
            escapeEcho: Config::bool($config, 'escape_echo', true),
            echoAllow: Config::stringList($config, 'echo_allow'),
            timeFormat: (string) Config::value($config, 'time_format', '%A, %d-%b-%Y %H:%M:%S %Z'),
            sizeFormat: $sizeFormat,
            errorMessage: Config::nullableString($config, 'error_message'),
            execMode: ExecMode::tryFrom((string) $execMode)
                ?? throw new ConfigException('ssi.exec_mode must be one of: deny, allow-list, delegate.'),
            execCmdAllow: Config::stringList($config, 'exec_cmd_allow'),
            execCgiExtensions: Config::stringList($config, 'exec_cgi_extensions') ?: ['php'],
            execCgiMode: (string) Config::value($config, 'exec_cgi_mode', 'spawn'),
            execDelegate: Config::callableOrNull($config, 'exec_delegate'),
            processRenderedOutput: Config::bool($config, 'process_rendered_output', true),
            printenvEnabled: Config::bool($config, 'printenv_enabled', true)
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'document_root' => $this->documentRoot,
            'allow_parent_paths' => $this->allowParentPaths,
            'escape_echo' => $this->escapeEcho,
            'echo_allow' => $this->echoAllow,
            'time_format' => $this->timeFormat,
            'size_format' => $this->sizeFormat->value,
            'error_message' => $this->errorMessage,
            'exec_mode' => $this->execMode->value,
            'exec_cmd_allow' => $this->execCmdAllow,
            'exec_cgi_extensions' => $this->execCgiExtensions,
            'exec_cgi_mode' => $this->execCgiMode,
            'exec_delegate' => $this->execDelegate === null ? null : '(callable)',
            'process_rendered_output' => $this->processRenderedOutput,
            'printenv_enabled' => $this->printenvEnabled,
        ];
    }

    /** Known keys of this group — used to reject typos. */
    public const array KEYS = [
        'enabled', 'document_root', 'allow_parent_paths', 'escape_echo', 'echo_allow',
        'time_format', 'size_format', 'error_message', 'exec_mode', 'exec_cmd_allow',
        'exec_cgi_extensions', 'exec_cgi_mode', 'exec_delegate', 'process_rendered_output',
        'printenv_enabled',
    ];
}
