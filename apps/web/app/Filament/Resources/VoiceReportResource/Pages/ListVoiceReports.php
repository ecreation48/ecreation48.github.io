<?php
namespace App\Filament\Resources\VoiceReportResource\Pages; use App\Filament\Resources\VoiceReportResource; use Filament\Actions; use Filament\Resources\Pages\ListRecords;
class ListVoiceReports extends ListRecords {protected static string $resource=VoiceReportResource::class; protected function getHeaderActions():array{return [Actions\CreateAction::make()];}}
