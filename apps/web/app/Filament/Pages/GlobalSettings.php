<?php

namespace App\Filament\Pages;

use App\Support\GlobalSettings as GlobalSettingsStore;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class GlobalSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationLabel = 'Configuration globale';
    protected static ?string $navigationGroup = 'Configuration';
    protected static ?int $navigationSort = 10;
    protected static string $view = 'filament.pages.global-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->canManageConfiguration() ?? false;
    }

    public function mount(GlobalSettingsStore $settings): void
    {
        $this->form->fill($settings->all());
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Forms\Components\Section::make('Transcription')
                    ->description('Moteur utilisé par le worker Discord pour transcrire les extraits audio.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('transcription.provider')
                            ->label('Moteur')
                            ->options([
                                'command' => 'Local / open source',
                                'openai' => 'OpenAI',
                                'none' => 'Désactivé',
                            ])
                            ->required()
                            ->live(),
                        Forms\Components\TextInput::make('transcription.engine')
                            ->label('Nom affiché')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('transcription.language')
                            ->label('Langue')
                            ->required()
                            ->maxLength(12),
                        Forms\Components\TextInput::make('transcription.timeout_ms')
                            ->label('Timeout')
                            ->numeric()
                            ->suffix('ms')
                            ->minValue(10000)
                            ->maxValue(1800000)
                            ->required(),
                        Forms\Components\TextInput::make('transcription.batch_size')
                            ->label('Transcriptions par cycle')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(20)
                            ->required(),
                        Forms\Components\TextInput::make('transcription.command')
                            ->label('Commande locale')
                            ->columnSpanFull()
                            ->visible(fn (Forms\Get $get): bool => $get('transcription.provider') === 'command'),
                        Forms\Components\TextInput::make('transcription.whisper_cpp_binary')
                            ->label('Binaire whisper.cpp')
                            ->visible(fn (Forms\Get $get): bool => $get('transcription.provider') === 'command'),
                        Forms\Components\TextInput::make('transcription.whisper_cpp_model')
                            ->label('Modèle whisper.cpp')
                            ->visible(fn (Forms\Get $get): bool => $get('transcription.provider') === 'command'),
                        Forms\Components\TextInput::make('transcription.openai_base_url')
                            ->label('URL API OpenAI')
                            ->visible(fn (Forms\Get $get): bool => $get('transcription.provider') === 'openai'),
                        Forms\Components\TextInput::make('transcription.openai_model')
                            ->label('Modèle OpenAI')
                            ->visible(fn (Forms\Get $get): bool => $get('transcription.provider') === 'openai'),
                    ]),

                Forms\Components\Section::make('Réglages par défaut')
                    ->description('Valeurs appliquées aux nouveaux salons vocaux synchronisés.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Toggle::make('defaults.monitor_new_voice_channels')
                            ->label('Surveiller les nouveaux salons vocaux')
                            ->default(true),
                        Forms\Components\Toggle::make('defaults.transcription_enabled')
                            ->label('Activer la transcription par défaut'),
                        Forms\Components\Toggle::make('defaults.volume_analysis_enabled')
                            ->label('Analyse de volume par défaut'),
                        Forms\Components\TextInput::make('defaults.buffer_seconds')
                            ->label('Tampon audio')
                            ->numeric()
                            ->suffix('s')
                            ->minValue(15)
                            ->maxValue(120)
                            ->required(),
                        Forms\Components\TextInput::make('defaults.retention_days')
                            ->label('Conservation audio')
                            ->numeric()
                            ->suffix('jours')
                            ->minValue(1)
                            ->maxValue(365)
                            ->required(),
                        Forms\Components\TextInput::make('defaults.channel_sync_interval_ms')
                            ->label('Synchronisation des salons')
                            ->numeric()
                            ->suffix('ms')
                            ->minValue(60000)
                            ->maxValue(3600000)
                            ->required(),
                    ]),

                Forms\Components\Section::make('Apparence')
                    ->description('Couleurs utilisées par les composants personnalisés.')
                    ->columns(3)
                    ->schema([
                        Forms\Components\Select::make('appearance.primary_color')
                            ->label('Couleur principale')
                            ->options([
                                'slate' => 'Slate',
                                'gray' => 'Gris',
                                'zinc' => 'Zinc',
                                'red' => 'Rouge',
                                'orange' => 'Orange',
                                'amber' => 'Ambre',
                                'yellow' => 'Jaune',
                                'lime' => 'Lime',
                                'green' => 'Vert',
                                'emerald' => 'Émeraude',
                                'teal' => 'Teal',
                                'cyan' => 'Cyan',
                                'sky' => 'Sky',
                                'blue' => 'Bleu',
                                'indigo' => 'Indigo',
                                'violet' => 'Violet',
                                'purple' => 'Purple',
                                'fuchsia' => 'Fuchsia',
                                'pink' => 'Rose',
                            ])
                            ->required(),
                        Forms\Components\ColorPicker::make('appearance.accent_color')
                            ->label('Accent lecteur')
                            ->required(),
                        Forms\Components\ColorPicker::make('appearance.danger_color')
                            ->label('Alerte live')
                            ->required(),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Enregistrer')
                ->icon('heroicon-o-check')
                ->action('save'),
        ];
    }

    public function save(GlobalSettingsStore $settings): void
    {
        $settings->save($this->form->getState());

        Notification::make()
            ->title('Configuration enregistrée')
            ->body('Les nouveaux réglages seront pris en compte au prochain cycle du worker ou au prochain redémarrage.')
            ->success()
            ->send();
    }
}
