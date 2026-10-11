<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/tools', __DIR__ . '/bin'])
    ->notPath('v3-source')
    ->append([__DIR__ . '/autoload.php']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2x0' => true, // renamed in cs-fixer 3.9x; pinned version in CI
        'declare_strict_types' => true,
        'native_function_invocation' => ['include' => ['@compiler_optimized'], 'scope' => 'namespaced'],
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        'phpdoc_align' => false,
    ])
    ->setFinder($finder);
