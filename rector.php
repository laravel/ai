<?php

declare(strict_types=1);

use Rector\CodingStyle\Rector\Encapsed\EncapsedStringsToSprintfRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\For_\RemoveDeadIfForeachForRector;
use Rector\DeadCode\Rector\For_\RemoveDeadLoopRector;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;
use Rector\Strict\Rector\Empty_\DisallowedEmptyRuleFixerRector;
use Rector\TypeDeclaration\Rector\FuncCall\AddArrayFunctionClosureParamTypeRector;
use Rector\TypeDeclaration\Rector\FunctionLike\AddClosureParamTypeForArrayMapRector;
use Rector\TypeDeclaration\Rector\FunctionLike\AddClosureParamTypeForArrayReduceRector;
use Rector\TypeDeclaration\Rector\StmtsAwareInterface\SafeDeclareStrictTypesRector;
use RectorLaravel\Set\LaravelSetProvider;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withSkip([
        ReadOnlyPropertyRector::class,
        EncapsedStringsToSprintfRector::class,
        DisallowedEmptyRuleFixerRector::class,
        // Renames public constructor parameters (e.g. mimeType -> mime), breaking named-argument callers.
        ClassPropertyAssignToConstructorPromotionRector::class,
        // Infers closure param types too narrowly for polymorphic tool lists (Tool|Agent), causing TypeErrors.
        AddClosureParamTypeForArrayMapRector::class,
        AddClosureParamTypeForArrayReduceRector::class,
        AddArrayFunctionClosureParamTypeRector::class,
        // Deletes empty-bodied loops, but iterating a generator (e.g. draining a stream) has side effects and must be kept.
        RemoveDeadLoopRector::class,
        RemoveDeadIfForeachForRector::class,
        // Skipped for now to keep the diff focused (no declare(strict_types=1) churn).
        SafeDeclareStrictTypesRector::class,
    ])
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        earlyReturn: true,
    )
    ->withPhpSets(php81: true)
    ->withSetProviders(LaravelSetProvider::class)
    ->withComposerBased(laravel: true);
