@props(['user'])

@if ($user->avatar)
    <img src="{{ $user->avatar }}" alt="" referrerpolicy="no-referrer" {{ $attributes->merge(['class' => 'shrink-0 rounded-full object-cover']) }}>
@else
    <span aria-hidden="true" {{ $attributes->merge(['class' => 'grid shrink-0 place-items-center rounded-full bg-brand-100 text-xs font-semibold text-brand-700']) }}>
        {{ $user->initials() }}
    </span>
@endif
