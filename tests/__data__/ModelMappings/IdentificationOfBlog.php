<?php

namespace SWalbrun\FilamentModelImport\Tests\__Data__\ModelMappings;

use SWalbrun\FilamentModelImport\Import\ModelMapping\IdentificationOf;
use Illuminate\Support\Collection;

class IdentificationOfBlog extends IdentificationOf
{

    public function propertyMapping(): Collection
    {
        return collect([
            'blogName' => '/BlogName/i',
        ]);
    }

    public function uniqueColumns(): array
    {
        return [];
    }
}
