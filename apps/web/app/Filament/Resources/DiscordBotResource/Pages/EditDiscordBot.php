<?php
namespace App\Filament\Resources\DiscordBotResource\Pages; use App\Filament\Resources\DiscordBotResource; use Filament\Actions; use Filament\Resources\Pages\EditRecord;
class EditDiscordBot extends EditRecord {protected static string $resource=DiscordBotResource::class; protected function getHeaderActions():array{return [Actions\DeleteAction::make()];}}
