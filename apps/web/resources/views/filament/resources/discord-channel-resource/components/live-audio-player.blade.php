@php
    $proxyUrl = route('admin.voice-channels.live', $channel);
    $bot = $channel->bot;
    $worker = $bot?->workerInstance;
    $isBotOnline = $bot?->connection_status === 'online';
@endphp

<style>
    .vg-live-player {
        overflow: hidden;
        border: 1px solid rgb(229 231 235);
        border-radius: 14px;
        background: rgb(255 255 255);
        box-shadow: 0 10px 25px rgb(15 23 42 / 0.08);
    }

    .dark .vg-live-player {
        border-color: rgb(31 41 55);
        background: rgb(3 7 18);
    }

    .vg-live-header {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        border-bottom: 1px solid rgb(229 231 235);
        background: rgb(249 250 251);
        padding: 18px 20px;
    }

    .dark .vg-live-header {
        border-color: rgb(31 41 55);
        background: rgb(17 24 39 / 0.72);
    }

    .vg-live-title {
        color: rgb(17 24 39);
        font-size: 15px;
        font-weight: 700;
        line-height: 1.3;
    }

    .dark .vg-live-title {
        color: rgb(255 255 255);
    }

    .vg-live-subtitle {
        margin-top: 4px;
        color: rgb(107 114 128);
        font-size: 12px;
    }

    .dark .vg-live-subtitle {
        color: rgb(156 163 175);
    }

    .vg-live-badges {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }

    .vg-live-badge {
        display: inline-flex;
        align-items: center;
        border-radius: 7px;
        padding: 5px 8px;
        font-size: 12px;
        font-weight: 650;
        box-shadow: inset 0 0 0 1px rgb(148 163 184 / 0.25);
    }

    .vg-live-badge-online {
        background: rgb(236 253 245);
        color: rgb(4 120 87);
    }

    .dark .vg-live-badge-online {
        background: rgb(16 185 129 / 0.12);
        color: rgb(110 231 183);
    }

    .vg-live-badge-waiting {
        background: rgb(255 251 235);
        color: rgb(180 83 9);
    }

    .dark .vg-live-badge-waiting {
        background: rgb(245 158 11 / 0.12);
        color: rgb(252 211 77);
    }

    .vg-live-badge-neutral {
        background: rgb(248 250 252);
        color: rgb(71 85 105);
    }

    .dark .vg-live-badge-neutral {
        background: rgb(148 163 184 / 0.12);
        color: rgb(203 213 225);
    }

    .vg-live-body {
        display: grid;
        gap: 18px;
        padding: 20px;
    }

    .vg-live-deck {
        border: 1px solid rgb(31 41 55);
        border-radius: 12px;
        background:
            radial-gradient(circle at 18% 0%, rgb(16 185 129 / 0.22), transparent 26%),
            linear-gradient(135deg, rgb(2 6 23), rgb(15 23 42));
        color: white;
        padding: 20px;
    }

    .vg-live-deck-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
    }

    .vg-live-kicker {
        color: rgb(110 231 183);
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0;
        text-transform: uppercase;
    }

    .vg-live-heading {
        margin-top: 5px;
        font-size: 19px;
        font-weight: 750;
        line-height: 1.25;
    }

    .vg-live-button {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        min-height: 44px;
        border: 0;
        border-radius: 10px;
        background: rgb(52 211 153);
        color: rgb(2 6 23);
        cursor: pointer;
        font-size: 14px;
        font-weight: 750;
        padding: 0 16px;
        transition: background-color 160ms ease, transform 160ms ease;
    }

    .vg-live-button:hover {
        background: rgb(110 231 183);
        transform: translateY(-1px);
    }

    .vg-live-audio {
        width: 100%;
        margin-top: 18px;
        accent-color: rgb(52 211 153);
    }

    .vg-live-empty {
        display: flex;
        min-height: 72px;
        align-items: center;
        justify-content: center;
        margin-top: 18px;
        border: 1px dashed rgb(75 85 99);
        border-radius: 10px;
        background: rgb(15 23 42 / 0.72);
        color: rgb(209 213 219);
        font-size: 13px;
        text-align: center;
    }

    .vg-live-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 12px;
    }

    .vg-live-info {
        border: 1px solid rgb(229 231 235);
        border-radius: 10px;
        background: rgb(249 250 251);
        padding: 12px;
    }

    .dark .vg-live-info {
        border-color: rgb(31 41 55);
        background: rgb(17 24 39 / 0.6);
    }

    .vg-live-info-label {
        color: rgb(107 114 128);
        font-size: 12px;
    }

    .dark .vg-live-info-label {
        color: rgb(156 163 175);
    }

    .vg-live-info-value {
        overflow: hidden;
        margin-top: 5px;
        color: rgb(17 24 39);
        font-size: 13px;
        font-weight: 700;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .dark .vg-live-info-value {
        color: rgb(255 255 255);
    }

    .vg-live-footer {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        color: rgb(107 114 128);
        font-size: 12px;
    }

    .dark .vg-live-footer {
        color: rgb(156 163 175);
    }

    .vg-live-link {
        color: rgb(5 150 105);
        font-weight: 700;
        text-decoration: none;
    }

    .vg-live-link:hover {
        color: rgb(4 120 87);
        text-decoration: underline;
    }

    @media (max-width: 640px) {
        .vg-live-deck-top,
        .vg-live-header {
            align-items: stretch;
            flex-direction: column;
        }

        .vg-live-button {
            justify-content: center;
            width: 100%;
        }

        .vg-live-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div
    x-data="{
        started: false,
        loadKey: Date.now(),
        get source() { return '{{ $proxyUrl }}?t=' + this.loadKey },
        play() {
            this.started = true
            this.$nextTick(() => this.$refs.audio?.play().catch(() => {}))
        },
        reload() {
            this.loadKey = Date.now()
            this.started = true
            this.$nextTick(() => {
                this.$refs.audio?.load()
                this.$refs.audio?.play().catch(() => {})
            })
        },
    }"
    class="vg-live-player"
>
    <div class="vg-live-header">
        <div>
            <div class="vg-live-title">{{ $channel->name }}</div>
            <div class="vg-live-subtitle">
                {{ $channel->guild?->name ?? 'Serveur inconnu' }}
                @if ($bot)
                    · {{ $bot->name }}
                @endif
            </div>
        </div>

        <div class="vg-live-badges">
            <span class="vg-live-badge {{ $isBotOnline ? 'vg-live-badge-online' : 'vg-live-badge-waiting' }}">
                {{ $isBotOnline ? 'Bot en ligne' : 'Bot non connecté' }}
            </span>

            <span class="vg-live-badge vg-live-badge-neutral">
                {{ $worker?->last_heartbeat_at ? 'Heartbeat '.$worker->last_heartbeat_at->diffForHumans() : 'Heartbeat inconnu' }}
            </span>
        </div>
    </div>

    <div class="vg-live-body">
        <div class="vg-live-deck">
            <div class="vg-live-deck-top">
                <div>
                    <div class="vg-live-kicker">Direct vocal</div>
                    <div class="vg-live-heading">Flux du salon surveillé</div>
                </div>

                <button type="button" x-on:click="started ? reload() : play()" class="vg-live-button">
                    <x-heroicon-o-play style="width: 20px; height: 20px;" />
                    <span x-text="started ? 'Relancer' : 'Écouter'"></span>
                </button>
            </div>

            <template x-if="started">
                <audio
                    x-ref="audio"
                    class="vg-live-audio"
                    controls
                    autoplay
                    preload="none"
                    x-bind:src="source"
                ></audio>
            </template>

            <div x-show="! started" class="vg-live-empty">
                Lance l’écoute pour ouvrir le flux audio sans quitter l’admin.
            </div>
        </div>

        <div class="vg-live-grid">
            <div class="vg-live-info">
                <div class="vg-live-info-label">Salon</div>
                <div class="vg-live-info-value">{{ $channel->discord_id }}</div>
            </div>
            <div class="vg-live-info">
                <div class="vg-live-info-label">Buffer audio</div>
                <div class="vg-live-info-value">{{ $channel->buffer_seconds }} s</div>
            </div>
            <div class="vg-live-info">
                <div class="vg-live-info-label">Worker</div>
                <div class="vg-live-info-value">{{ $worker?->name ?? 'Non assigné' }}</div>
            </div>
        </div>

        <div class="vg-live-footer">
            <span>Si le lecteur reste silencieux, vérifie que le bot est actuellement dans ce salon vocal.</span>
            <a href="{{ $proxyUrl }}" target="_blank" class="vg-live-link">Ouvrir le proxy Laravel</a>
        </div>
    </div>
</div>
