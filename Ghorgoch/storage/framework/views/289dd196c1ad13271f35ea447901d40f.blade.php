@php
/** @var \Laravel\Boost\Install\GuidelineAssist $assist */
@endphp
# Task Scheduling Best Practices

## Prevent Unwanted Overlap

Use ___SINGLE_BACKTICK___withoutOverlapping()___SINGLE_BACKTICK___ when a second run must not begin while the previous run holds the lock. This is appropriate for variable-duration tasks that are not safe to run concurrently.

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___php
Schedule::command('reports:generate')
    ->everyFifteenMinutes()
    ->withoutOverlapping(30);
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

The optional value is the lock expiration time in minutes, not the task timeout. Choose it carefully: the default is 24 hours, stale locks can be cleared with ___SINGLE_BACKTICK___{{ $assist->artisanCommand('schedule:clear-cache') }}___SINGLE_BACKTICK___, and an expiration that is too short can permit overlap while the first task still runs. The task itself should still tolerate retries and partial execution where practical.

## Run a Task on One Server

Use ___SINGLE_BACKTICK___onOneServer()___SINGLE_BACKTICK___ when only one scheduler node should run an eligible task. Scheduler nodes must use the same default cache store, and that store must support atomic locks. Supported stores include ___SINGLE_BACKTICK___database___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___memcached___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___dynamodb___SINGLE_BACKTICK___, and ___SINGLE_BACKTICK___redis___SINGLE_BACKTICK___.

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___php
Schedule::command('billing:charge')->daily()->onOneServer();
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

Name scheduled closures before applying ___SINGLE_BACKTICK___onOneServer()___SINGLE_BACKTICK___, especially when scheduling the same closure with different parameters, so each task has a distinct lock identity.

## Run Eligible Commands in the Background

Tasks due at the same time run sequentially by default. Use ___SINGLE_BACKTICK___runInBackground()___SINGLE_BACKTICK___ when an independent, long-running scheduled command should not delay later tasks.

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___php
Schedule::command('analytics:process')->hourly()->runInBackground();
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

Laravel restricts ___SINGLE_BACKTICK___runInBackground()___SINGLE_BACKTICK___ to tasks scheduled with ___SINGLE_BACKTICK___command()___SINGLE_BACKTICK___ and ___SINGLE_BACKTICK___exec()___SINGLE_BACKTICK___; it is not available for scheduled closures. Ensure background processes have appropriate logging and failure monitoring.

## Restrict Tasks by Environment

Use ___SINGLE_BACKTICK___environments()___SINGLE_BACKTICK___ when a task should run only in named application environments. Treat this as an operational safeguard, not an authorization control.

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___php
Schedule::command('billing:charge')
    ->monthly()
    ->environments(['production']);
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

## Group Shared Configuration

Use schedule groups when several tasks genuinely share frequency or constraints.

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___php
Schedule::daily()
    ->onOneServer()
    ->timezone('America/New_York')
    ->group(function () {
        Schedule::command('emails:send --force');
        Schedule::command('emails:prune');
    });
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

## Bound Work Inside the Task

The scheduler does not provide a ___SINGLE_BACKTICK___takeUntilTimeout()___SINGLE_BACKTICK___ event method or terminate arbitrary tasks at a deadline. Bound work in the command or job itself by processing finite chunks, checking a deadline, or dispatching queue jobs with suitable timeouts. Use operating-system or process controls when hard termination is required.
