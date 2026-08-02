<?php
namespace App\Filament\Resources\ModerationActionResource\Pages; use App\Filament\Resources\ModerationActionResource; use Filament\Actions; use Filament\Resources\Pages\ListRecords;
class ListModerationActions extends ListRecords {protected static string $resource=ModerationActionResource::class; protected function getHeaderActions():array{return [Actions\CreateAction::make()];}}
