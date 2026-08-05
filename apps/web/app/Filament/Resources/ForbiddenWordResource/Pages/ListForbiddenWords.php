<?php

namespace App\Filament\Resources\ForbiddenWordResource\Pages;

use App\Filament\Resources\ForbiddenWordResource;
use App\Models\ForbiddenWord;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListForbiddenWords extends ListRecords
{
    protected static string $resource = ForbiddenWordResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('downloadCsvTemplate')
                ->label('Télécharger modèle CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn (): StreamedResponse => response()->streamDownload(function (): void {
                    $output = fopen('php://output', 'w');
                    fwrite($output, "\xEF\xBB\xBF");
                    fputcsv($output, ['word', 'severity', 'is_active', 'notes']);
                    fputcsv($output, ['exemple interdit', 'high', 'true', 'Expression à surveiller']);
                    fputcsv($output, ['mot sensible', 'medium', 'true', '']);
                    fclose($output);
                }, 'modele-mots-interdits.csv', ['Content-Type' => 'text/csv; charset=UTF-8'])),
            Actions\Action::make('importCsv')
                ->label('Importer CSV')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('success')
                ->modalHeading('Importer des mots interdits')
                ->modalDescription(new HtmlString('Colonnes attendues : <strong>word</strong> obligatoire, puis <strong>severity</strong>, <strong>is_active</strong>, <strong>notes</strong> optionnelles. Gravités acceptées : low, medium, high, critical.'))
                ->form([
                    Forms\Components\FileUpload::make('csv')
                        ->label('Fichier CSV')
                        ->disk('local')
                        ->directory('imports/forbidden-words')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $result = $this->importCsv((string) $data['csv']);

                    Notification::make()
                        ->title('Import terminé')
                        ->body($result['created'].' créé(s), '.$result['updated'].' mis à jour, '.$result['skipped'].' ignoré(s).')
                        ->success()
                        ->send();
                }),
            Actions\CreateAction::make()->label('Ajouter un mot'),
        ];
    }

    private function importCsv(string $path): array
    {
        $absolutePath = Storage::disk('local')->path($path);
        $handle = fopen($absolutePath, 'r');

        if ($handle === false) {
            return ['created' => 0, 'updated' => 0, 'skipped' => 1];
        }

        $header = fgetcsv($handle);
        $columns = $this->csvColumns($header ?: []);
        $created = 0;
        $updated = 0;
        $skipped = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $data = $this->csvRow($columns, $row);
            $word = trim((string) ($data['word'] ?? ''));

            if ($word === '') {
                $skipped++;
                continue;
            }

            $normalized = ForbiddenWord::normalize($word);
            $existing = ForbiddenWord::query()->where('normalized_word', $normalized)->first();
            $severity = $this->severity((string) ($data['severity'] ?? 'medium'));

            ForbiddenWord::query()->updateOrCreate(
                ['normalized_word' => $normalized],
                [
                    'word' => $word,
                    'severity' => $severity,
                    'is_active' => $this->boolean($data['is_active'] ?? true),
                    'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
                ],
            );

            $existing ? $updated++ : $created++;
        }

        fclose($handle);

        return compact('created', 'updated', 'skipped');
    }

    private function csvColumns(array $header): array
    {
        return collect($header)
            ->map(fn ($column): string => match (Str::of((string) $column)->replace("\xEF\xBB\xBF", '')->lower()->trim()->ascii()->replace(' ', '_')->toString()) {
                'mot', 'expression' => 'word',
                'gravite' => 'severity',
                'actif', 'active' => 'is_active',
                'note' => 'notes',
                default => Str::of((string) $column)->replace("\xEF\xBB\xBF", '')->lower()->trim()->ascii()->replace(' ', '_')->toString(),
            })
            ->all();
    }

    private function csvRow(array $columns, array $row): array
    {
        $data = [];

        foreach ($row as $index => $value) {
            $column = $columns[$index] ?? null;
            if (! $column) continue;
            $data[$column] = $value;
        }

        return $data;
    }

    private function severity(string $severity): string
    {
        $severity = Str::of($severity)->lower()->trim()->toString();

        return in_array($severity, ['low', 'medium', 'high', 'critical'], true) ? $severity : 'medium';
    }

    private function boolean(mixed $value): bool
    {
        if (is_bool($value)) return $value;

        return in_array(Str::of((string) $value)->lower()->trim()->toString(), ['1', 'true', 'yes', 'oui', 'actif', 'active'], true);
    }
}
