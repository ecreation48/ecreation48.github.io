<?php
return ['default'=>env('CACHE_STORE','redis'),'stores'=>['array'=>['driver'=>'array','serialize'=>false],'redis'=>['driver'=>'redis','connection'=>'default']],'prefix'=>'voice_guardian_cache'];
