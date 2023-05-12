<?php

namespace SWalbrun\FilamentModelImport\Import\Services;

use Closure;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Row;
use ReflectionFunction;
use ReflectionParameter;
use SWalbrun\FilamentModelImport\Import\ColumnMapping;
use SWalbrun\FilamentModelImport\Import\ModelMapping\AssociationRegistrar;
use SWalbrun\FilamentModelImport\Import\ModelMapping\IdentificationOf;
use SWalbrun\FilamentModelImport\Import\ModelMapping\IdentificationRegistrar;

/**
 * This processor is trying to create models using the {@link IdentificationOf::propertyMapping()} taking care of
 * {@link IdentificationOf::uniqueColumns() unique columns} making sure the import can be idempotent.<br>
 * Associations get also set in case {@link IdentificationOf::associationHooks() hooks} are set.
 */
class ImportService implements OnEachRow
{
    private IdentificationRegistrar $identificationRegister;

    private AssociationRegistrar $associationRegister;

    private bool $firstRow = true;

    private Collection $nonMatchingHeadingCells;

    /**
     * @var Collection<string, ColumnMapping>
     */
    private Collection $headingToColumnMapping;

    public function __construct(IdentificationRegistrar $identificationRegister, AssociationRegistrar $associationRegister)
    {
        $this->identificationRegister = $identificationRegister;
        $this->associationRegister = $associationRegister;
        $this->nonMatchingHeadingCells = collect();
        $this->headingToColumnMapping = collect();
    }

    /**
     * @return void
     *
     * @throws Exception
     */
    public function onRow(Row $row)
    {
        $collectedRow = $row->toCollection();
        if ($this->firstRow) {
            $this->determineHeader($collectedRow);
            $this->firstRow = false;

            return;
        }
        $this->handleDataRow($collectedRow);
    }

    /**
     * @throws Exception
     */
    private function determineHeader(Collection $row): void
    {
        $row->each(function (?string $cell, int $index) {
            if (! isset($cell)) {
                // Since the cell is not having a heading row, we cannot process it since it gets used as regEx subject
                return;
            }
            $this->identificationRegister
                ->getMappings()
                ->each(function (IdentificationOf $mapping) use ($index, $cell) {
                    $matchingColumns = $mapping->propertyMapping()
                        ->filter(fn (string $regEx, string $column) => preg_match($regEx, $cell) || $column === $cell);
                    if ($matchingColumns->count() > 1) {
                        // The same table is having some overlapping regular expressions
                        $this->throwOverlappingException($matchingColumns->implode(', '), $cell);
                    }
                    if ($matchingColumns->isEmpty()) {
                        return;
                    }

                    if ($this->headingToColumnMapping->has($index)) {
                        // Several tables have overlapping regular expressions
                        // phpcs:ignore
                        /** @var ColumnMapping $mapping */
                        $mapping = $this->headingToColumnMapping->get($index);
                        $this->throwOverlappingException(collect([
                            $mapping->originalRegEx,
                            $matchingColumns->first(),
                        ])->implode(', '), $cell);
                    }
                    $this->headingToColumnMapping->put(
                        $index,
                        new ColumnMapping($mapping, $matchingColumns->keys()->first(), $matchingColumns->first())
                    );
                });
            if (! $this->headingToColumnMapping->has($index)) {
                $this->nonMatchingHeadingCells->push($cell);
            }
        });
    }

    private function handleDataRow(Collection $row): void
    {
        $this->reset();
        $this->setAttributes($row);
        $this->persistAllModels();
        $this->setRelations();
    }

    private function reset()
    {
        $this->headingToColumnMapping
            ->each(
                fn (
                    ColumnMapping $columnValue
                ) => $columnValue->identificationOf->model = $columnValue->identificationOf->model->newInstance()
            );
    }

    private function setAttributes(Collection $row): void
    {
        $row->each(function (?string $cell, int $index) {
            /** @var ColumnMapping|null $columnValue */
            $columnValue = $this->headingToColumnMapping->get($index);
            if (! isset($columnValue)) {
                // This column has not been recognized. We can just skip it.
                return;
            }

            $columnValue->identificationOf->model->{$columnValue->column} = $cell;
        });
    }

    private function setRelations()
    {
        $allModels = $this->headingToColumnMapping
            ->map(fn (ColumnMapping $columnValue) => $columnValue->identificationOf->model)
            ->unique();
        $this->associationRegister->getClosures()
            ->map(function (Closure $callback) use ($allModels) {
                $reflectionMethod = new ReflectionFunction($callback);
                // We have to remember the position within the method declaration to make sure the closure
                // always get called correctly
                $requiredTypes = collect($reflectionMethod->getParameters())
                    ->mapWithKeys(fn (ReflectionParameter $parameter) => [
                        // @phpstan-ignore-next-line
                        $parameter->getPosition() => $parameter->getType()->getName(),
                    ]);

                $requiredModels = $allModels
                    ->mapWithKeys(
                        fn (Model $importedModel) => [
                            $requiredTypes->search(fn (string $requiredType) => $importedModel instanceof $requiredType) => $importedModel,
                        ]
                    );
                if ($requiredTypes->count() !== $requiredModels->count()) {
                    return;
                }

                // Seems like all required models for this callback has been identified
                $callback->__invoke(...$requiredModels->sortKeys());
            });
    }

    private function persistAllModels(): void
    {
        $this->headingToColumnMapping
            ->unique(fn (ColumnMapping $columnValue) => $columnValue->identificationOf)
            ->each(function (ColumnMapping $columnMapping) {
                if (count($columnMapping->identificationOf->uniqueColumns()) > 0) {
                    $model = $columnMapping->identificationOf->model;
                    $uniqueColumns = collect($model->getAttributes())->filter(
                        fn ($value, string $column) => in_array($column, $columnMapping->identificationOf->uniqueColumns())
                    );

                    $builder = $model->newQuery();
                    $uniqueColumns->each(fn ($value, string $column) => $builder->where($column, '=', $value));
                    $modelToPersist = $builder->firstOrNew();
                    $modelToPersist->fill($model->getAttributes());
                    $columnMapping->identificationOf->saving($modelToPersist);
                    $modelToPersist->save();
                    $columnMapping->identificationOf->saved($modelToPersist);
                    $columnMapping->identificationOf->model = $modelToPersist;

                    return;
                }
                $columnMapping->identificationOf->model->save();
            });
    }

    /**
     * @return mixed
     *
     * @throws Exception
     */
    private function throwOverlappingException(string $implode, string $cell)
    {
        throw new Exception(
            'The regex\'s result is overlapping. More than one matching regex ('
            .$implode
            .") has been found for column ($cell)"
        );
    }
}
