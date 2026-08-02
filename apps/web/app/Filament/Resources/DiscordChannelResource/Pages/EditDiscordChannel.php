<?php
namespace App\Filament\Resources\DiscordChannelResource\Pages; use App\Filament\Resources\DiscordChannelResource; use Filament\Actions; use Filament\Resources\Pages\EditRecord;
class EditDiscordChannel extends EditRecord {protected static string $resource=DiscordChannelResource::class; protected function getHeaderActions():array{return [Actions\DeleteAction::make()];}}
