<?php

namespace SWalbrun\FilamentModelImport;

use Exception;
use Spatie\LaravelPackageTools\Exceptions\InvalidPackage;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use SWalbrun\FilamentModelImport\Commands\MakeImportMapper;
use SWalbrun\FilamentModelImport\Import\ModelMapping\BaseMapper;
use SWalbrun\FilamentModelImport\Import\ModelMapping\MappingRegistrar;
use SWalbrun\FilamentModelImport\Import\ModelMapping\RelationRegistrar;
use SWalbrun\FilamentModelImport\Import\ModelMapping\Relator;

class FilamentRegexImportServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-regex-import';

    protected array $styles = [
        'plugin-filament-regex-import' => __DIR__.'/../resources/dist/filament-regex-import.css',
    ];

    protected array $scripts = [
        'plugin-filament-regex-import' => __DIR__.'/../resources/dist/filament-regex-import.js',
    ];

    public function configurePackage(Package $package): void
    {
        $package->name(static::$name)
            ->hasConfigFile()
            ->hasTranslations()
            ->hasCommand(MakeImportMapper::class)
            ->hasViews();
    }

    /**
     * @throws InvalidPackage
     * @throws Exception
     */
    public function boot()
    {
        parent::boot();
        $this->app->singleton(MappingRegistrar::class);
        $this->app->singleton(RelationRegistrar::class);

        /** @var MappingRegistrar $mappingRegistrar */
        $mappingRegistrar = resolve(MappingRegistrar::class);

        /** @var RelationRegistrar $relationRegistrar */
        $relationRegistrar = resolve(RelationRegistrar::class);
        $configIdentifier = static::$name.'.mappers';
        $mappers = collect(config($configIdentifier));
        $mappers->each(function ($class) use ($configIdentifier) {
            if (! (is_subclass_of($class, BaseMapper::class) || is_subclass_of($class, Relator::class))) {
                throw new Exception(
                    'The configured mapper class '.
                    "$class in $configIdentifier does neither implement "
                    .BaseMapper::class
                    .' nor '
                    .Relator::class
                );
            }
        })->each(function (string $mapperClass) use ($relationRegistrar, $mappingRegistrar) {
            $mapper = resolve($mapperClass);
            if ($mapper instanceof BaseMapper) {
                $mappingRegistrar->register($mapper);
            }
            if ($mapper instanceof Relator) {
                $relationRegistrar->registerRelator($mapper);
            }
        });

    }
}
