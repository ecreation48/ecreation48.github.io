<x-filament-panels::page
    @class([
        'fi-resource-edit-record-page',
        'fi-resource-' . str_replace('/', '-', $this->getResource()::getSlug()),
        'fi-resource-record-' . $record->getKey(),
    ])
>
    <style>
        .fi-resource-voice-reports .fi-header {
            align-items: flex-end;
            gap: 16px;
        }

        .fi-resource-voice-reports .fi-header > div:first-child {
            min-width: min(100%, 280px);
        }

        .fi-resource-voice-reports .fi-header > div:last-child {
            margin-top: 0 !important;
            flex: 1 1 520px;
            justify-content: flex-end;
            min-width: 0;
        }

        .fi-resource-voice-reports .fi-header .fi-ac {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 8px;
        }

        .fi-resource-voice-reports .fi-header .fi-ac .fi-btn {
            min-height: 36px;
        }

        @media (max-width: 720px) {
            .fi-resource-voice-reports .fi-header {
                align-items: stretch;
            }

            .fi-resource-voice-reports .fi-header > div:last-child,
            .fi-resource-voice-reports .fi-header .fi-ac {
                justify-content: flex-start;
            }
        }
    </style>

    <div class="space-y-6">
        @include('filament.resources.voice-report-resource.components.audio-review', ['record' => $record])

        @include('filament.resources.voice-report-resource.components.transcription-review', ['record' => $record])

        <x-filament-panels::form
            id="form"
            :wire:key="$this->getId() . '.forms.' . $this->getFormStatePath()"
            wire:submit="save"
        >
            {{ $this->form }}

            <x-filament-panels::form.actions
                :actions="$this->getCachedFormActions()"
                :full-width="$this->hasFullWidthFormActions()"
            />
        </x-filament-panels::form>

        @php($relationManagers = $this->getRelationManagers())

        @if (count($relationManagers))
            <x-filament-panels::resources.relation-managers
                :active-locale="isset($activeLocale) ? $activeLocale : null"
                :active-manager="$this->activeRelationManager ?? array_key_first($relationManagers)"
                :content-tab-label="$this->getContentTabLabel()"
                :content-tab-icon="$this->getContentTabIcon()"
                :content-tab-position="$this->getContentTabPosition()"
                :managers="$relationManagers"
                :owner-record="$record"
                :page-class="static::class"
            />
        @endif
    </div>

    <x-filament-panels::page.unsaved-data-changes-alert />
</x-filament-panels::page>
