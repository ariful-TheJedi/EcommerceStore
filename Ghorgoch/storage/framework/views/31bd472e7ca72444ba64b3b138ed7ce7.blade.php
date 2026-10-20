@php
/** @var \Laravel\Boost\Install\GuidelineAssist $assist */
@endphp
# Mail Best Practices

## Queue Slow Mail Delivery

Implement ___SINGLE_BACKTICK___ShouldQueue___SINGLE_BACKTICK___ on a mailable when delivery should normally happen in the background. Laravel queues that mailable even when the call site uses ___SINGLE_BACKTICK___Mail::send()___SINGLE_BACKTICK___.

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___php
class OrderShipped extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;
}
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

Keep mail synchronous when the caller must know immediately whether delivery was accepted, or when no queue worker is available.

## Dispatch Queued Mail After Commit

A queued mailable dispatched during a database transaction can be processed before the transaction commits. Call ___SINGLE_BACKTICK___afterCommit()___SINGLE_BACKTICK___ on the mailable, or enable the queue connection's ___SINGLE_BACKTICK___after_commit___SINGLE_BACKTICK___ option, when the mail depends on committed records.

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___php
Mail::to($user)->send(
    (new OrderShipped($order))->afterCommit()
);
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

If the transaction rolls back, an after-commit mailable is not dispatched. This setting affects queued mail only; it does not defer synchronous delivery.

## Assert the Delivery Mode

Use ___SINGLE_BACKTICK___Mail::assertQueued()___SINGLE_BACKTICK___ for queued mailables and ___SINGLE_BACKTICK___Mail::assertSent()___SINGLE_BACKTICK___ for synchronously sent mailables.

Incorrect for a mailable that implements ___SINGLE_BACKTICK___ShouldQueue___SINGLE_BACKTICK___:

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___php
Mail::assertSent(OrderShipped::class);
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

Correct:

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___php
Mail::assertQueued(OrderShipped::class);
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

## Use Markdown Mailables When They Fit

Markdown mailables render HTML and plain-text versions from Laravel's mail components and support publishable themes. They are useful for conventional transactional messages, but a custom HTML and text pair may be more appropriate for a specialized design.

___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___bash
{{ $assist->artisanCommand('make:mail OrderShipped --markdown=mail.orders.shipped') }}
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

## Separate Content and Delivery Tests

Test rendered content by instantiating the mailable and using assertions such as ___SINGLE_BACKTICK___assertSeeInHtml()___SINGLE_BACKTICK___ and ___SINGLE_BACKTICK___assertSeeInText()___SINGLE_BACKTICK___. Test delivery separately with ___SINGLE_BACKTICK___Mail::fake()___SINGLE_BACKTICK___ and ___SINGLE_BACKTICK___assertSent()___SINGLE_BACKTICK___ or ___SINGLE_BACKTICK___assertQueued()___SINGLE_BACKTICK___ so failures identify the affected behavior.
