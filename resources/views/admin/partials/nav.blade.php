<div class="mb-8 flex gap-1 border-b border-slate-200">
    <a href="{{ route('admin.emails.index') }}"
        class="border-b-2 px-4 py-2 text-sm font-medium transition {{ request()->routeIs('admin.emails.*') ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-700' }}">
        Access Control
    </a>
    <a href="{{ route('admin.facebook-pages.index') }}"
        class="border-b-2 px-4 py-2 text-sm font-medium transition {{ request()->routeIs('admin.facebook-pages.*') ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-700' }}">
        Facebook Pages
    </a>
    <a href="{{ route('admin.cras.index') }}"
        class="border-b-2 px-4 py-2 text-sm font-medium transition {{ request()->routeIs('admin.cras.*') ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-700' }}">
        CRAs
    </a>
</div>