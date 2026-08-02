<?php
return ['default'=>env('QUEUE_CONNECTION','redis'),'connections'=>['sync'=>['driver'=>'sync'],'redis'=>['driver'=>'redis','connection'=>'default','queue'=>'default','retry_after'=>90]]];
