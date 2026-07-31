<?php
use App\Models\DiscordBot; use Illuminate\Foundation\Testing\RefreshDatabase;
uses(RefreshDatabase::class);
it('rejects requests without a service token',fn()=>$this->getJson('/api/v1/internal/bots')->assertUnauthorized());
it('never exposes tokens in the bot listing',function(){config(['services.worker.token'=>'secret']);DiscordBot::query()->create(['name'=>'Guardian','client_id'=>'123','token'=>'discord-secret','is_active'=>true]);$this->withToken('secret')->getJson('/api/v1/internal/bots')->assertOk()->assertJsonMissing(['token'=>'discord-secret']);});
