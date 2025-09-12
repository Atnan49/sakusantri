<?php
$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__)
    ->exclude(['vendor','node_modules','uploads','public/assets/uploads','_backup_'])
    ->name('*.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'binary_operator_spaces' => ['operators' => ['=>' => 'align', '=' => 'align_single_space_minimal']],
        'combine_consecutive_unsets' => true,
        'compact_nullable_typehint' => true,
        'declare_strict_types' => false,
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'phpdoc_align' => ['align' => 'left'],
        'phpdoc_order' => true,
        'single_quote' => true,
        'ternary_operator_spaces' => true,
        'trailing_comma_in_multiline' => true,
    ])
    ->setFinder($finder);
