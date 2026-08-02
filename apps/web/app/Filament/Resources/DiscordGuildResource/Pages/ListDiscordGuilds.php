<?php
namespace App\Filament\Resources\DiscordGuildResource\Pages; use App\Filament\Resources\DiscordGuildResource; use Filament\Actions; use Filament\Resources\Pages\ListRecords;
class ListDiscordGuilds extends ListRecords {protected static string $resource=DiscordGuildResource::class; protected function getHeaderActions():array{return [Actions\CreateAction::make()];}}
