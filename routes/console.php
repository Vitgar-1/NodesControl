<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('sessions:clean-expired')->everyMinute();