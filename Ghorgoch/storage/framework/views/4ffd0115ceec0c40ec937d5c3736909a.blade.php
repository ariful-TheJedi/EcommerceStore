@php
/** @var \Laravel\Boost\Install\GuidelineAssist $assist */
@endphp
# Events and Notifications Best Practices

## Rely on Event Discovery

Laravel discovers listeners in the configured listener directories by inspecting type-hinted event arguments on ___SINGLE_BACKTICK___handle()___SINGLE_BACKTICK___ or ___SINGLE_BACKTICK_____invoke()___SINGLE_BACKTICK___ methods. Register listeners manually only when discovery is disabled, the listener is outside those directories, or explicit registration is clearer.

## Cache Event Discovery During Production Deployment

Cache discovered listeners during production deployment with ___SINGLE_BACKTICK___{{ $assist->artisanCommand('optimize') }}___SINGLE_BACKTICK___ or ___SINGLE_BACKTICK___{{ $assist->artisanCommand('event:cache') }}___SINGLE_BACKTICK___. Rebuild the cache whenever listener definitions change.

## Use ___SINGLE_BACKTICK___ShouldDispatchAfterCommit___SINGLE_BACKTICK___ Inside Transactions

When an event is dispatched inside a database transaction, ___SINGLE_BACKTICK___ShouldDispatchAfterCommit___SINGLE_BACKTICK___ delays dispatch until all open database transactions commit. If a transaction rolls back, Laravel discards the event. This affects synchronous and queued listeners; it is not limited to queue timing.

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___php
class OrderShipped implements ShouldDispatchAfterCommit {}
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

## Queue Slow Notifications

Queue notifications that call external services, such as email, text messaging, or Slack, when they do not need to complete before the response. Keep a notification synchronous when immediate completion or failure feedback is part of the operation.

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___php
class InvoicePaid extends Notification implements ShouldQueue
{
    use Queueable;
}
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

## Dispatch Queued Notifications After Commit

A queued notification sent inside a database transaction can run before the transaction commits. Call ___SINGLE_BACKTICK___afterCommit()___SINGLE_BACKTICK___ on the queued notification, or enable the queue connection's ___SINGLE_BACKTICK___after_commit___SINGLE_BACKTICK___ option, when its delivery depends on committed data. This setting has no scheduling effect on a synchronous notification.

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___php
$user->notify((new InvoicePaid($invoice))->afterCommit());
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

## Route Notification Channels to Dedicated Queues

Different notification channels can have different latency and priority requirements. Implement ___SINGLE_BACKTICK___viaQueues()___SINGLE_BACKTICK___ when channels should use separate queues.

## Use On-Demand Notifications for Non-User Recipients

Avoid creating dummy models to send notifications to arbitrary addresses.

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___php
Notification::route('mail', 'admin@example.com')->notify(new SystemAlert());
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

## Implement ___SINGLE_BACKTICK___HasLocalePreference___SINGLE_BACKTICK___ on Notifiable Models

Implement ___SINGLE_BACKTICK___HasLocalePreference::preferredLocale()___SINGLE_BACKTICK___ on a notifiable model when notifications and mailables should use the recipient's locale. Laravel also preserves that locale for queued delivery. An explicit ___SINGLE_BACKTICK___locale()___SINGLE_BACKTICK___ call can still override the preference for an individual notification.
