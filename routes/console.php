<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('flights:fetch')
    ->cron(config('gates.fetch_cron'))
    ->withoutOverlapping();

Schedule::command('allocations:validate')
    ->cron(config('gates.validate_cron'))
    ->withoutOverlapping();
