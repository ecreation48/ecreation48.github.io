<?php

return [
    'temporary_file_upload' => [
        'disk' => 'local',
        'directory' => 'livewire-tmp',
        'rules' => 'file|mimetypes:audio/mpeg,audio/mp3,audio/wav,audio/x-wav,audio/wave,audio/vnd.wave,audio/ogg,audio/opus,audio/flac,audio/x-flac,audio/mp4,audio/aac,audio/x-m4a,video/mp4,application/octet-stream|max:51200',
        'middleware' => null,
        'preview_mimes' => [
            'png',
            'gif',
            'bmp',
            'svg',
            'wav',
            'mp3',
            'm4a',
            'aac',
            'ogg',
            'opus',
            'flac',
        ],
        'max_upload_time' => 30,
        'cleanup' => true,
    ],
];
