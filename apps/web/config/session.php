<?php
return ['driver'=>env('SESSION_DRIVER','database'),'lifetime'=>120,'expire_on_close'=>false,'encrypt'=>true,'files'=>storage_path('framework/sessions'),'connection'=>null,'table'=>'sessions','store'=>null,'lottery'=>[2,100],'cookie'=>env('SESSION_COOKIE','voice_guardian_session'),'path'=>'/','domain'=>null,'secure'=>env('SESSION_SECURE_COOKIE',false),'http_only'=>true,'same_site'=>'lax'];
