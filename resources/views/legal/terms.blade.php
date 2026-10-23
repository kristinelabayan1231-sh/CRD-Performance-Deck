@php($contact = config('access.super_admin_email'))

<x-layouts.legal title="Terms of Service">
    <p>CRD Performance Deck is an internal tool for the Customer Retention Department. By signing in you agree to these terms.</p>

    <h2>Who may use it</h2>
    <p>Only people a super admin has added in User Access, for their work in the department. Access can be changed or removed at any time.</p>

    <h2>Using it properly</h2>
    <ul>
        <li>Keep customer names, contact numbers, orders and call recordings confidential and use them only for retention work.</li>
        <li>Tell customers when a call is recorded.</li>
        <li>Don't share your account, a phone's upload token or recordings with anyone outside the department.</li>
    </ul>

    <h2>No warranty</h2>
    <p>The app is provided as is. Its numbers come from other systems and may be delayed or incomplete; check them before acting on them.</p>

    <h2>Changes</h2>
    <p>These terms may be updated; the date at the top shows the latest version.</p>

    <h2>Contact</h2>
    <p><a href="mailto:{{ $contact }}" class="font-semibold text-brand-600 hover:underline">{{ $contact }}</a></p>
</x-layouts.legal>
