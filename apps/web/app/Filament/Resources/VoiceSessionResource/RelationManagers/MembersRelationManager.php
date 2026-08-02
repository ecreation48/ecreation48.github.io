<?php
namespace App\Filament\Resources\VoiceSessionResource\RelationManagers;
use Filament\Forms\Form; use Filament\Resources\RelationManagers\RelationManager; use Filament\Tables; use Filament\Tables\Table;
class MembersRelationManager extends RelationManager { protected static string $relationship='members'; protected static ?string $title='Membres';
 public function form(Form $form): Form{return $form->schema([]);}
 public function table(Table $table): Table{return $table->columns([Tables\Columns\TextColumn::make('display_name')->label('Nom')->searchable(),Tables\Columns\TextColumn::make('discord_user_id')->label('Discord ID')->copyable()->searchable(),Tables\Columns\TextColumn::make('joined_at')->dateTime(),Tables\Columns\TextColumn::make('left_at')->dateTime()]);}}
