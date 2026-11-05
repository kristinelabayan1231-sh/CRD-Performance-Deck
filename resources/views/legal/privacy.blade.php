@php($contact = config('access.super_admin_email'))

<x-layouts.legal title="Privacy Policy">
    <p>CRD Performance Deck is the internal tool of the Customer Retention Department (CRD). Only people a super admin has added can sign in. This page explains what it collects and how it is used.</p>

    <h2>Google sign-in</h2>
    <p>When you sign in with Google, the app receives your name, email address and profile photo. They identify your account, show your name across the app and decide what you may open. Nothing else in your Google account is read.</p>

    <h2>Google Drive (call recordings)</h2>
    <p>A super admin may connect one Google Drive to keep the CRAs' call recordings. The app asks only for access to the files it creates itself (the "drive.file" permission): it makes one folder, saves recordings there and plays them back to signed-in users who are allowed to hear them. It cannot see, change or delete any other file in that Drive.</p>

    <h2>Call recordings and customer data</h2>
    <ul>
        <li>Recordings are uploaded from the CRAs' work phones together with the customer's phone number, the call time, its direction and its length.</li>
        <li>The number is matched to the customer's order so supervisors can review calls and measure average handle time (AHT).</li>
        <li>Customer names, contact numbers and orders come from the company's order and logistics systems and are used only for customer retention work.</li>
    </ul>

    <h2>Where data is kept</h2>
    <p>Account, order and call details are stored in the app's database; the recordings themselves are stored in the connected Google Drive. Access is limited by role, and every page needs a signed-in, active account.</p>

    <h2>Sharing</h2>
    <p>Data is not sold and not shared with anyone outside the company, except the services the app runs on (hosting, Google sign-in and Google Drive) as needed to provide it. Data received from Google APIs is used only for the features above.</p>

    <h2>Keeping and removing data</h2>
    <p>Data is kept while it is needed for the department's work. A super admin can remove a user's access at any time, and the Google Drive connection can be removed from your Google Account's security settings (Third-party apps).</p>

    <h2>Contact</h2>
    <p>Questions or requests about your data: <a href="mailto:{{ $contact }}" class="font-semibold text-brand-600 hover:underline">{{ $contact }}</a>.</p>
</x-layouts.legal>
