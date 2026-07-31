<?php
namespace App\Filament\Resources\DiscordBotResource\Pages; use App\Filament\Resources\DiscordBotResource; use Filament\Actions; use Filament\Resources\Pages\ListRecords;
class ListDiscordBots extends ListRecords {protected static string $resource=DiscordBotResource::class; protected function getHeaderActions():array{return [Actions\CreateAction::make()];}}
