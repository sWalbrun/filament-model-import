<?php

namespace SWalbrun\FilamentModelImport\Filament\Pages;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Pages\Page;
use Livewire\WithFileUploads;
use Maatwebsite\Excel\Facades\Excel;
use SWalbrun\FilamentModelImport\Import\Services\ImportService;

class ImportPage extends Page
{
    use WithFileUploads;
    use InteractsWithForms;

    /**
     * Needed for the file upload input to work properly.
     *
     * @var mixed
     */
    public $import = null;

    public const IMPORT = 'import';

    protected static ?string $navigationIcon = 'bi-filetype-xlsx';

    protected static string $view = 'filament-model-import::pages.import';

    protected static string $viewIdentifier = 'import';

    protected function getFormSchema(): array
    {
        return [
            FileUpload::make(self::IMPORT)
                ->label(trans('Import'))
                ->afterStateUpdated(
                    function () {
                        $files = collect($this->import);
                        if ($files->isEmpty()) {
                            return;
                        }
                        Excel::import(
                            resolve(ImportService::class),
                            array_pop($this->import)->getRealPath()
                        );
                    }
                )
        ];
    }
}
