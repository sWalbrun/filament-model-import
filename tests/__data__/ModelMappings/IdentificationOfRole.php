<?php

namespace SWalbrun\FilamentModelImport\Tests\__Data__\ModelMappings;

use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;
use SWalbrun\FilamentModelImport\Import\ModelMapping\IdentificationOf;

class IdentificationOfRole extends IdentificationOf
{
    public function __construct()
    {
        parent::__construct(new Role());
    }

    public function uniqueColumns(): array
    {
        return [
            'name',
        ];
    }

    public function propertyMapping(): Collection
    {
        return collect([
            'name' => '/^Rolle$/i',
        ]);
    }
}
