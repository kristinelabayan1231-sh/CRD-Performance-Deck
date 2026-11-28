{{-- One customer's conversation on its own, shown in the tracker's customer panel, Conversation tab (segmentation._spiels-panel). --}}
<x-layouts.bare title="SMS Conversation">
    @if (session('status'))
        <div role="status" class="m-3 rounded-lg border border-teal/50 bg-teal/10 px-4 py-3 text-sm text-teal-700">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div role="alert" class="m-3 rounded-lg border border-coral/60 bg-coral/10 px-4 py-3 text-sm text-coral-700">{{ $errors->first() }}</div>
    @endif
    @include('sms-reminders._chat', ['compact' => true, 'earlierUrl' => route('sms-reminders.conversations.chat', ['phoneKey' => $phone, 'all' => 1])])
</x-layouts.bare>
