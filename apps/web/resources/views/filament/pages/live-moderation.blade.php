<x-filament-panels::page>
    <div wire:poll.5s class="vg-live-moderation">
        <div class="vg-live-header">
            <div>
                <p class="vg-live-kicker">Supervision temps réel</p>
                <h2>Modération vocale active</h2>
            </div>
            <div class="vg-live-refresh">
                Dernière mise à jour {{ $generatedAt->timezone('Europe/Paris')->format('H:i:s') }}
            </div>
        </div>

        <div class="vg-live-stats">
            @foreach ($stats as $stat)
                <section class="vg-live-stat">
                    <span>{{ $stat['label'] }}</span>
                    <strong>{{ $stat['value'] }}</strong>
                </section>
            @endforeach
        </div>

        <section class="vg-live-panel">
            <div class="vg-live-panel__head">
                <div>
                    <h3>Bots Discord</h3>
                    <p>Salons actuellement occupés par chaque bot.</p>
                </div>
            </div>

            <div class="vg-live-bots">
                @foreach ($bots as $bot)
                    <article class="vg-live-bot">
                        <div class="vg-live-bot__main">
                            <span @class([
                                'vg-live-dot',
                                'vg-live-dot--online' => $bot['status'] === 'online',
                                'vg-live-dot--error' => $bot['status'] === 'error',
                            ])></span>
                            <div>
                                <h4>{{ $bot['name'] }}</h4>
                                <p>{{ $bot['worker'] ?: 'Aucun worker assigné' }}</p>
                            </div>
                        </div>

                        <div class="vg-live-tags">
                            <span>{{ $bot['is_active'] ? 'Actif' : 'Désactivé' }}</span>
                            <span>{{ match ($bot['status']) { 'online' => 'En ligne', 'connecting' => 'Connexion', 'error' => 'Erreur', 'offline' => 'Hors ligne', default => $bot['status'] } }}</span>
                        </div>

                        @if ($bot['sessions']->isNotEmpty())
                            <div class="vg-live-bot__channels">
                                @foreach ($bot['sessions'] as $session)
                                    <div>
                                        <strong>{{ $session->channel?->name ?? 'Salon inconnu' }}</strong>
                                        <span>{{ $session->member_count }} membre(s) - {{ $session->last_activity_at?->timezone('Europe/Paris')->diffForHumans() }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @elseif ($bot['error_message'])
                            <p class="vg-live-error">{{ $bot['error_message'] }}</p>
                        @else
                            <p class="vg-live-muted">Aucun salon vocal rejoint pour le moment.</p>
                        @endif
                    </article>
                @endforeach
            </div>
        </section>

        <section class="vg-live-panel">
            <div class="vg-live-panel__head">
                <div>
                    <h3>Salons surveillés</h3>
                    <p>Présence, bot connecté et priorité de détection.</p>
                </div>
            </div>

            <div class="vg-live-channels">
                @foreach ($channels as $row)
                    @php($channel = $row['channel'])
                    @php($session = $row['session'])
                    <article @class(['vg-live-channel', 'vg-live-channel--active' => $row['is_live']])>
                        <div class="vg-live-channel__top">
                            <div>
                                <h4>{{ $channel->name }}</h4>
                                <p>{{ $channel->guild?->name ?? 'Serveur inconnu' }}</p>
                            </div>
                            <div class="vg-live-tags">
                                <span>{{ $row['is_live'] ? 'Écouté' : 'En attente' }}</span>
                                <span>{{ $row['auto_detection'] ? 'Détection active' : 'Détection coupée' }}</span>
                                <span>Priorité {{ $row['priority'] }}</span>
                            </div>
                        </div>

                        @if ($session)
                            <div class="vg-live-session">
                                <span>Bot : {{ $session->bot?->name ?? 'Inconnu' }}</span>
                                <span>{{ $session->member_count }} membre(s)</span>
                                <span>Heartbeat {{ $session->last_activity_at?->timezone('Europe/Paris')->diffForHumans() }}</span>
                            </div>

                            <div class="vg-live-members">
                                @forelse ($row['members'] as $member)
                                    <span>{{ $member->display_name ?: $member->discord_user_id }}</span>
                                @empty
                                    <span>Aucun membre synchronisé</span>
                                @endforelse
                            </div>
                        @else
                            <p class="vg-live-muted">Le bot rejoindra ce salon dès qu’il y aura au moins 2 personnes et qu’un bot sera disponible.</p>
                        @endif
                    </article>
                @endforeach
            </div>
        </section>

        <div class="vg-live-grid">
            <section class="vg-live-panel">
                <div class="vg-live-panel__head">
                    <div>
                        <h3>Activité vocale récente</h3>
                        <p>Événements des 30 dernières minutes.</p>
                    </div>
                </div>

                <div class="vg-live-feed">
                    @forelse ($events as $event)
                        <div class="vg-live-feed__row">
                            <time>{{ $event->occurred_at?->timezone('Europe/Paris')->format('H:i:s') }}</time>
                            <div>
                                <strong>{{ $event->actorName() }}</strong>
                                <span>{{ $event->label() }} - {{ $event->channel?->name ?? 'Salon inconnu' }}</span>
                            </div>
                        </div>
                    @empty
                        <p class="vg-live-muted">Aucune activité récente.</p>
                    @endforelse
                </div>
            </section>

            <section class="vg-live-panel">
                <div class="vg-live-panel__head">
                    <div>
                        <h3>Détections et transcriptions</h3>
                        <p>Signalements automatiques et derniers résultats du moteur.</p>
                    </div>
                </div>

                <div class="vg-live-feed">
                    @forelse ($reports as $report)
                        <a class="vg-live-feed__row vg-live-feed__row--danger" href="{{ route('filament.admin.resources.voice-reports.edit', $report) }}">
                            <time>{{ $report->reported_at?->timezone('Europe/Paris')->format('H:i:s') }}</time>
                            <div>
                                <strong>{{ $report->reportedUserName() }}</strong>
                                <span>{{ data_get($report->detection_metadata, 'matched_word', 'Mot détecté') }} - {{ $report->channel?->name ?? 'Salon inconnu' }}</span>
                            </div>
                        </a>
                    @empty
                        <p class="vg-live-muted">Aucune détection automatique récente.</p>
                    @endforelse

                    @foreach ($transcripts as $transcript)
                        <div class="vg-live-feed__row">
                            <time>{{ $transcript->created_at?->timezone('Europe/Paris')->format('H:i:s') }}</time>
                            <div>
                                <strong>{{ match ($transcript->status) { 'completed' => 'Transcription terminée', 'processing' => 'Transcription en cours', 'failed' => 'Transcription échouée', default => ucfirst($transcript->status) } }}</strong>
                                <span>{{ str($transcript->text ?: $transcript->error_message ?: 'En attente de résultat')->limit(120) }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        </div>
    </div>

    <style>
        .vg-live-moderation { display: grid; gap: 1rem; color: rgb(15 23 42); }
        .dark .vg-live-moderation { color: rgb(226 232 240); }
        .vg-live-header { display: flex; align-items: flex-end; justify-content: space-between; gap: 1rem; }
        .vg-live-kicker { margin: 0 0 .25rem; color: rgb(220 38 38); font-size: .78rem; font-weight: 700; text-transform: uppercase; }
        .vg-live-header h2 { margin: 0; font-size: clamp(1.5rem, 2vw, 2.25rem); font-weight: 800; letter-spacing: 0; }
        .vg-live-refresh, .vg-live-muted { color: rgb(100 116 139); font-size: .875rem; }
        .dark .vg-live-refresh, .dark .vg-live-muted { color: rgb(148 163 184); }
        .vg-live-stats { display: grid; gap: .75rem; grid-template-columns: repeat(6, minmax(0, 1fr)); }
        .vg-live-stat, .vg-live-panel, .vg-live-bot, .vg-live-channel { border: 1px solid rgb(226 232 240); background: rgb(255 255 255); border-radius: 8px; }
        .dark .vg-live-stat, .dark .vg-live-panel, .dark .vg-live-bot, .dark .vg-live-channel { border-color: rgb(51 65 85); background: rgb(15 23 42); }
        .vg-live-stat { padding: .9rem; }
        .vg-live-stat span { display: block; color: rgb(100 116 139); font-size: .78rem; font-weight: 700; text-transform: uppercase; }
        .dark .vg-live-stat span { color: rgb(148 163 184); }
        .vg-live-stat strong { display: block; margin-top: .35rem; font-size: 1.6rem; line-height: 1; }
        .vg-live-panel { padding: 1rem; }
        .vg-live-panel__head { display: flex; justify-content: space-between; gap: 1rem; margin-bottom: .9rem; }
        .vg-live-panel h3, .vg-live-bot h4, .vg-live-channel h4 { margin: 0; font-weight: 750; }
        .vg-live-panel p, .vg-live-bot p, .vg-live-channel p { margin: .2rem 0 0; color: rgb(100 116 139); font-size: .875rem; }
        .dark .vg-live-panel p, .dark .vg-live-bot p, .dark .vg-live-channel p { color: rgb(148 163 184); }
        .vg-live-bots, .vg-live-channels { display: grid; gap: .75rem; }
        .vg-live-bot, .vg-live-channel { padding: .9rem; }
        .vg-live-bot__main, .vg-live-channel__top { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
        .vg-live-bot__main { justify-content: flex-start; }
        .vg-live-dot { width: .75rem; height: .75rem; border-radius: 999px; background: rgb(148 163 184); box-shadow: 0 0 0 4px rgb(148 163 184 / .15); flex: 0 0 auto; }
        .vg-live-dot--online { background: rgb(34 197 94); box-shadow: 0 0 0 4px rgb(34 197 94 / .16); }
        .vg-live-dot--error { background: rgb(239 68 68); box-shadow: 0 0 0 4px rgb(239 68 68 / .16); }
        .vg-live-tags { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: .4rem; }
        .vg-live-tags span { border: 1px solid rgb(226 232 240); border-radius: 999px; padding: .22rem .55rem; color: rgb(71 85 105); font-size: .75rem; font-weight: 700; }
        .dark .vg-live-tags span { border-color: rgb(51 65 85); color: rgb(203 213 225); background: rgb(2 6 23); }
        .vg-live-bot__channels, .vg-live-session, .vg-live-members { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: .8rem; }
        .vg-live-bot__channels div, .vg-live-session span, .vg-live-members span { background: rgb(248 250 252); border-radius: 8px; padding: .45rem .65rem; font-size: .82rem; }
        .dark .vg-live-bot__channels div, .dark .vg-live-session span, .dark .vg-live-members span { background: rgb(2 6 23); }
        .vg-live-bot__channels strong { display: block; }
        .vg-live-bot__channels span { color: rgb(100 116 139); }
        .vg-live-channel--active { border-color: rgb(34 197 94); box-shadow: inset 4px 0 0 rgb(34 197 94); }
        .dark .vg-live-channel--active { border-color: rgb(22 163 74); }
        .vg-live-error { color: rgb(220 38 38) !important; }
        .vg-live-grid { display: grid; gap: 1rem; grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .vg-live-feed { display: grid; gap: .55rem; }
        .vg-live-feed__row { display: grid; grid-template-columns: 4.25rem minmax(0, 1fr); gap: .75rem; align-items: start; color: inherit; text-decoration: none; border-radius: 8px; padding: .55rem; background: rgb(248 250 252); }
        .dark .vg-live-feed__row { background: rgb(2 6 23); }
        .vg-live-feed__row time { color: rgb(100 116 139); font-size: .78rem; font-weight: 700; }
        .dark .vg-live-feed__row time { color: rgb(148 163 184); }
        .vg-live-feed__row strong, .vg-live-feed__row span { display: block; min-width: 0; overflow-wrap: anywhere; }
        .vg-live-feed__row span { color: rgb(71 85 105); font-size: .86rem; }
        .dark .vg-live-feed__row span { color: rgb(203 213 225); }
        .vg-live-feed__row--danger { background: rgb(254 242 242); border: 1px solid rgb(254 202 202); }
        .dark .vg-live-feed__row--danger { background: rgb(69 10 10 / .35); border-color: rgb(127 29 29); }
        @media (max-width: 1100px) { .vg-live-stats { grid-template-columns: repeat(3, minmax(0, 1fr)); } .vg-live-grid { grid-template-columns: 1fr; } }
        @media (max-width: 700px) { .vg-live-header, .vg-live-bot__main, .vg-live-channel__top { align-items: flex-start; flex-direction: column; } .vg-live-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } .vg-live-tags { justify-content: flex-start; } }
    </style>
</x-filament-panels::page>
