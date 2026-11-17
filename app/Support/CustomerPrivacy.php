<?php

namespace App\Support;

use App\Models\CustomerLink;
use App\Models\Setting;
use App\Services\HighValueCustomers;

/**
 * Customer Database "hide personal details": customers show as VIP 1, High AOV 1… (numbered by total spent, highest
 * first) or a masked name (Kr**** L***), and numbers are masked. Hidden for everyone until a Super Admin or CRA
 * Supervisor (customers.details.manage) shows them to all in Settings → Customer Database; those managers can also
 * see them for themselves with the eye button, and anyone can hide them for their session (e.g. to present).
 */
class CustomerPrivacy
{
    /** The user's own eye-button choice for their session (true = hidden). */
    public const SESSION_KEY = 'customers.hide_details';

    /** Settings → Customer Database: '1' shows names and numbers to every role. */
    public const SHOWN_TO_ALL_KEY = 'customers.details_shown_to_all';

    private ?bool $shownToAll = null;

    /** @var array<string, string>|null any number of a VIP / High AOV customer => their alias */
    private ?array $aliases = null;

    public function __construct(private HighValueCustomers $highValue) {}

    public function isHidden(): bool
    {
        $choice = session(self::SESSION_KEY);

        if (! $this->canReveal()) {
            return true;
        }

        return $choice === null ? ! $this->shownToAll() : (bool) $choice;
    }

    /**
     * Whether the eye button can show details to this user: always for a manager, else only once shown to all.
     */
    public function canReveal(): bool
    {
        return $this->canManage() || $this->shownToAll();
    }

    public function canManage(): bool
    {
        return (bool) auth()->user()?->can('customers.details.manage');
    }

    public function shownToAll(): bool
    {
        return $this->shownToAll ??= Setting::value(self::SHOWN_TO_ALL_KEY) === '1';
    }

    /**
     * The customer's name as shown: their alias or masked name while hidden.
     */
    public function name(?string $name, ?string $phoneKey = null): string
    {
        $name = trim((string) $name) ?: 'Unknown';

        if (! $this->isHidden()) {
            return $name;
        }

        return ($phoneKey !== null ? $this->aliases()[$phoneKey] ?? null : null) ?? self::maskName($name);
    }

    /**
     * A contact number (or "number / number") as shown: masked while hidden.
     */
    public function phone(?string $phone): string
    {
        return $this->isHidden() ? self::maskPhone((string) $phone) : (string) $phone;
    }

    /**
     * "Kristine Labayan" → "Kr**** L***": the first word keeps 2 letters, the others 1, and the rest is a fixed run of
     * stars so the length doesn't give the name away.
     */
    public static function maskName(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: ['?'];

        return collect($words)
            ->map(fn (string $word, int $index) => $index === 0 ? mb_substr($word, 0, 2).'****' : mb_substr($word, 0, 1).'***')
            ->implode(' ');
    }

    /**
     * "09171234567" → "09*******67": each number keeps its first and last 2 digits.
     */
    public static function maskPhone(string $phone): string
    {
        return (string) preg_replace_callback('/\d[\d\s-]*\d/', function (array $match) {
            $digits = preg_replace('/\D/', '', $match[0]);

            return strlen($digits) <= 4 ? str_repeat('*', strlen($digits)) : substr($digits, 0, 2).str_repeat('*', strlen($digits) - 4).substr($digits, -2);
        }, $phone);
    }

    /**
     * VIP n / High AOV n for every number of a listed customer, numbered by total spent (highest first). Empty when the
     * list can't be had right now, so names fall back to masked.
     *
     * @return array<string, string>
     */
    private function aliases(): array
    {
        if ($this->aliases !== null) {
            return $this->aliases;
        }

        $listed = rescue(fn () => $this->highValue->all(), [], report: false);
        $counters = ['vip' => 0, 'high_aov' => 0];
        $aliases = [];

        foreach ($listed as $key => $customer) {
            $group = $customer['vip'] ? 'vip' : 'high_aov';
            $aliases[(string) $key] = ($group === 'vip' ? 'VIP ' : 'High AOV ').++$counters[$group];
        }

        // A merged customer's other numbers show the same alias.
        CustomerLink::whereIn('primary_phone_key', array_keys($aliases))->pluck('primary_phone_key', 'phone_key')
            ->each(function (string $primary, string $phoneKey) use (&$aliases) {
                $aliases[$phoneKey] = $aliases[$primary];
            });

        return $this->aliases = $aliases;
    }
}
