<?php

namespace Tests\Feature;

use App\Models\CallRecording;
use App\Models\Lead;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CallRecordingsTest extends TestCase
{
    use RefreshDatabase;

    private const UPLOAD_SESSION = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&upload_id=abc';

    private User $regina;

    private User $lhea;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-23 14:00', 'Asia/Manila'));
        $this->regina = $this->cra('Regina');
        $this->lhea = $this->cra('Lhea');
        Setting::put('google_drive.refresh_token', Crypt::encryptString('refresh-token'));
        Setting::put('google_drive.folder_id', 'folder-1');
    }

    private function cra(string $name): User
    {
        return User::create([
            'email' => strtolower($name).'@example.com', 'display_name' => $name,
            'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true,
        ]);
    }

    private function lead(string $phone, ?User $cra, string $customer, string $day = '2026-10-20'): Lead
    {
        $this->seq++;

        return Lead::create([
            'order_id' => "ORD-{$this->seq}", 'customer_name' => $customer, 'phone_number' => $phone, 'product_name' => 'CanPro', 'qty' => 1,
            'delivered_date' => '2026-09-20', 'consumption_days' => 30, 'est_out_of_stock_date' => $day, 'lead_type' => Lead::TYPE_CRD,
            'assigned_to' => $cra?->id,
        ]);
    }

    /**
     * A mono 16-bit 8 kHz WAV of $seconds silence.
     */
    private function wav(int $seconds): string
    {
        $data = str_repeat("\0", 16000 * $seconds);

        return 'RIFF'.pack('V', 36 + strlen($data)).'WAVE'
            .'fmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16)
            .'data'.pack('V', strlen($data)).$data;
    }

    private function fakeDrive(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access', 'expires_in' => 3600]),
            'www.googleapis.com/upload/drive/v3/files?uploadType=resumable&upload_id=abc' => Http::response(['id' => 'drive-file-1']),
            'www.googleapis.com/upload/drive/v3/files?uploadType=resumable' => Http::response([], 200, ['Location' => self::UPLOAD_SESSION]),
        ]);
    }

    /**
     * @param  array<string, string|int>  $query
     */
    private function upload(?string $token, array $query, string $body): TestResponse
    {
        return $this->call('POST', route('api.call-recordings.store', $query), server: array_filter([
            'HTTP_AUTHORIZATION' => $token ? "Bearer {$token}" : null,
            'CONTENT_TYPE' => 'application/octet-stream',
            'HTTP_ACCEPT' => 'application/json',
        ]), content: $body);
    }

    public function test_an_upload_goes_to_drive_under_the_tokens_cra_and_matches_their_lead(): void
    {
        $this->fakeDrive();
        $token = $this->regina->issueRecordingToken();
        // The same number on Lhea's older lead and Regina's: Regina's (the uploader's) wins.
        $this->lead('09171234567', $this->lhea, 'Ana Cruz', '2026-10-21');
        $reginas = $this->lead('+63 9171234567', $this->regina, 'Ana Cruz', '2026-10-10');

        $this->upload($token, ['number' => '+639171234567', 'direction' => 'out', 'duration' => 185, 'called_at' => '2026-10-23 13:55', 'filename' => 'Call_Ana.m4a'], $this->wav(1))
            ->assertCreated()->assertJson(['matched' => true]);

        $this->assertDatabaseHas('call_recordings', [
            'user_id' => $this->regina->id, 'lead_id' => $reginas->id, 'phone_key' => '9171234567', 'direction' => 'out',
            'duration_seconds' => 185, 'drive_file_id' => 'drive-file-1', 'original_filename' => 'Call_Ana.m4a',
            'called_at' => '2026-10-23 05:55:00',
        ]);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable'
            && $request['parents'] === ['folder-1'] && $request['name'] === '2026-10-23 1355 regina 9171234567.m4a');
    }

    public function test_without_a_duration_the_length_is_read_from_the_recording_and_an_unknown_number_is_kept_unmatched(): void
    {
        $this->fakeDrive();

        $this->upload($this->lhea->issueRecordingToken(), ['number' => '0999 000 1111'], $this->wav(3))
            ->assertCreated()->assertJson(['matched' => false]);

        $recording = CallRecording::sole();
        $this->assertSame([3, null, $this->lhea->id], [$recording->duration_seconds, $recording->lead_id, $recording->user_id]);
    }

    public function test_a_file_already_uploaded_is_not_sent_to_drive_again(): void
    {
        $this->fakeDrive();
        $token = $this->regina->issueRecordingToken();
        $this->upload($token, ['number' => '09171234567', 'filename' => 'a.wav'], $this->wav(1))->assertCreated();

        $this->upload($token, ['number' => '09171234567', 'filename' => 'a.wav'], $this->wav(1))->assertOk()->assertJson(['message' => 'Already uploaded.']);

        $this->assertSame(1, CallRecording::count());
        Http::assertSentCount(3);
    }

    public function test_uploads_need_a_current_token_and_an_audio_body(): void
    {
        $this->fakeDrive();
        $old = $this->regina->issueRecordingToken();
        $token = $this->regina->issueRecordingToken();

        $this->upload(null, ['number' => '09171234567'], $this->wav(1))->assertUnauthorized();
        $this->upload($old, ['number' => '09171234567'], $this->wav(1))->assertUnauthorized();
        $this->upload($token, ['number' => '09171234567'], '<?php echo 1;')->assertUnprocessable();
        $this->upload($token, [], $this->wav(1))->assertUnprocessable()->assertJsonValidationErrors('number');

        $this->assertSame(0, CallRecording::count());
        Http::assertNothingSent();
    }

    public function test_a_drive_failure_answers_503_so_the_phone_keeps_the_file(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access', 'expires_in' => 3600]),
            'www.googleapis.com/upload/drive/v3/files?uploadType=resumable' => Http::response([], 500),
        ]);

        $this->upload($this->regina->issueRecordingToken(), ['number' => '09171234567'], $this->wav(1))->assertStatus(503);

        $this->assertSame(0, CallRecording::count());
    }

    private function recording(User $cra, ?Lead $lead, ?int $seconds, string $at = '2026-10-23 10:00', string $direction = 'out'): CallRecording
    {
        $this->seq++;

        return CallRecording::create([
            'user_id' => $cra->id, 'lead_id' => $lead?->id, 'phone_number' => '09171234567', 'phone_key' => '9171234567', 'direction' => $direction,
            'called_at' => CarbonImmutable::parse($at, 'Asia/Manila')->utc(), 'duration_seconds' => $seconds, 'drive_file_id' => "file-{$this->seq}",
            'mime_type' => 'audio/mp4', 'size_bytes' => 1000, 'original_filename' => "call-{$this->seq}.m4a",
        ]);
    }

    public function test_supervisors_see_each_cras_aht_for_the_dates_picked(): void
    {
        $lead = $this->lead('09171234567', $this->regina, 'Ana Cruz');
        $this->recording($this->regina, $lead, 120);
        $this->recording($this->regina, null, 240, direction: 'in');
        // Unknown length: counted as a call, not in AHT.
        $this->recording($this->regina, $lead, null);
        $this->recording($this->lhea, $lead, 60);
        // Before today (Manila): not in today's numbers.
        $this->recording($this->lhea, $lead, 600, '2026-10-22 23:30');
        $supervisor = User::create(['email' => 'sup@example.com', 'role_id' => Role::firstWhere('slug', Role::CRA_SUPERVISOR)->id, 'is_active' => true]);

        $this->actingAs($supervisor)->get(route('call-recordings.index'))->assertOk()
            ->assertViewHas('team', ['calls' => 4, 'aht' => 140.0, 'talk' => 420.0, 'unmatched' => 1])
            ->assertViewHas('rows', fn ($rows) => $rows->map(fn (array $row) => [$row['cra']->id, $row['calls'], $row['incoming'], $row['unmatched'], $row['aht']])->all() === [
                [$this->regina->id, 3, 1, 1, 180.0],
                [$this->lhea->id, 1, 0, 0, 60.0],
            ]);

        $this->actingAs($supervisor)->get(route('call-recordings.index', ['from' => '2026-10-22', 'to' => '2026-10-22']))
            ->assertViewHas('team', ['calls' => 1, 'aht' => 600.0, 'talk' => 600.0, 'unmatched' => 0]);
    }

    public function test_a_cra_sees_only_their_own_calls_even_when_asking_for_another_cra(): void
    {
        $this->recording($this->regina, null, 120);
        $this->recording($this->lhea, null, 60);

        $this->actingAs($this->lhea)->get(route('call-recordings.index', ['cra' => $this->regina->id]))->assertOk()
            ->assertViewHas('calls', fn ($calls) => $calls->pluck('user_id')->all() === [$this->lhea->id])
            ->assertViewHas('team', fn (array $team) => $team['calls'] === 1);
    }

    public function test_a_recording_plays_from_drive_with_the_players_range_for_its_cra_only(): void
    {
        $recording = $this->recording($this->regina, null, 120);
        Http::preventStrayRequests();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access', 'expires_in' => 3600]),
            "www.googleapis.com/drive/v3/files/{$recording->drive_file_id}?alt=media" => Http::response('AUDIO', 206, ['Content-Range' => 'bytes 0-4/5000', 'Content-Length' => '5']),
        ]);

        $response = $this->actingAs($this->regina)->get(route('call-recordings.audio', $recording), ['Range' => 'bytes=0-4']);

        $response->assertStatus(206)->assertHeader('Content-Type', 'audio/mp4')->assertHeader('Content-Range', 'bytes 0-4/5000');
        $this->assertSame('AUDIO', $response->streamedContent());
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/drive/v3/files/') && $request->header('Range') === ['bytes=0-4']);

        $this->actingAs($this->lhea)->get(route('call-recordings.audio', $recording))->assertForbidden();
    }

    public function test_the_trackers_last_column_plays_the_leads_calls_for_their_cra_only(): void
    {
        $this->withoutDefer();
        $lead = $this->lead('09171234567', $this->regina, 'Ana Cruz', '2026-10-23');
        $older = $this->recording($this->regina, $lead, 65, '2026-10-22 09:00');
        $latest = $this->recording($this->regina, $lead, 185, '2026-10-23 10:00');

        $this->actingAs($this->regina)->get(route('segmentation.index'))->assertOk()
            ->assertSeeInOrder(['Call Recording', 'Oct 23, 10:00 AM · 3:05', route('call-recordings.audio', $latest), '+ 1 more', route('call-recordings.audio', $older)]);

        // Lhea may open Regina's tracker, but not hear her calls.
        $this->actingAs($this->lhea)->get(route('segmentation.index', ['cra' => $this->regina->id]))->assertOk()
            ->assertSee('Oct 23, 10:00 AM · 3:05')->assertDontSee(route('call-recordings.audio', $latest));
    }

    public function test_the_call_button_sends_the_leads_number_to_the_cras_own_phone(): void
    {
        $webhook = 'https://trigger.macrodroid.com/regina-phone/call';
        $this->regina->forceFill(['call_webhook_url' => $webhook])->save();
        $mine = $this->lead('09171234567', $this->regina, 'Ana Cruz');
        $theirs = $this->lead('09181234567', $this->lhea, 'Ben Reyes');
        Http::preventStrayRequests();
        Http::fake(['trigger.macrodroid.com/*' => Http::response('ok')]);

        $this->actingAs($this->regina)->postJson(route('segmentation.call', $mine))->assertOk();
        $this->actingAs($this->regina)->postJson(route('segmentation.call', $theirs))->assertForbidden();
        // Lhea has no phone link.
        $this->actingAs($this->lhea)->postJson(route('segmentation.call', $theirs))->assertUnprocessable();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === "{$webhook}?number=09171234567&lead={$mine->id}");
    }

    public function test_the_call_button_says_so_when_the_phone_does_not_answer(): void
    {
        $this->regina->forceFill(['call_webhook_url' => 'https://trigger.macrodroid.com/regina-phone/call'])->save();
        Http::preventStrayRequests();
        Http::fake(['trigger.macrodroid.com/*' => Http::response('offline', 500)]);

        $this->actingAs($this->regina)->postJson(route('segmentation.call', $this->lead('09171234567', $this->regina, 'Ana Cruz')))->assertStatus(502);
    }

    public function test_the_call_button_shows_only_once_the_cras_phone_is_linked(): void
    {
        $this->withoutDefer();
        $lead = $this->lead('09171234567', $this->regina, 'Ana Cruz', '2026-10-23');

        $this->actingAs($this->regina)->get(route('segmentation.index'))->assertDontSee(route('segmentation.call', $lead));

        $this->regina->forceFill(['call_webhook_url' => 'https://trigger.macrodroid.com/regina-phone/call'])->save();
        $this->actingAs($this->regina)->get(route('segmentation.index'))->assertSee(route('segmentation.call', $lead));
    }

    public function test_an_upload_goes_to_the_lead_the_call_button_dialled_when_the_number_is_its_own(): void
    {
        $this->fakeDrive();
        $token = $this->regina->issueRecordingToken();
        // Two leads with the number: the newer would win a number match, the dialled one is used instead.
        $dialled = $this->lead('09171234567', $this->regina, 'Ana Cruz', '2026-10-01');
        $newer = $this->lead('09171234567', $this->regina, 'Ana Cruz', '2026-10-20');
        $other = $this->lead('09181234567', $this->regina, 'Ben Reyes');

        $this->upload($token, ['number' => '09171234567', 'lead' => $dialled->id, 'filename' => 'a.wav'], $this->wav(1))->assertCreated();
        // A leftover lead from an earlier call (another number): matched by number instead.
        $this->upload($token, ['number' => '09171234567', 'lead' => $other->id, 'filename' => 'b.wav'], $this->wav(1))->assertCreated();

        $this->assertSame([$dialled->id, $newer->id], CallRecording::orderBy('id')->pluck('lead_id')->all());
    }

    public function test_a_phone_link_must_be_a_macrodroid_webhook(): void
    {
        $admin = User::create(['email' => 'admin@example.com', 'role_id' => Role::superAdmin()->id, 'is_active' => true]);

        $this->actingAs($admin)->put(route('settings.call-recordings.phone', $this->regina), ['call_webhook_url' => 'https://evil.example.com/hook'])
            ->assertSessionHasErrors('call_webhook_url');
        $this->actingAs($admin)->put(route('settings.call-recordings.phone', $this->regina), ['call_webhook_url' => 'https://trigger.macrodroid.com/abc/call'])
            ->assertSessionHasNoErrors();
        $this->assertSame('https://trigger.macrodroid.com/abc/call', $this->regina->fresh()->call_webhook_url);

        $this->actingAs($this->regina)->put(route('settings.call-recordings.phone', $this->regina), ['call_webhook_url' => ''])->assertForbidden();
    }

    public function test_only_super_admins_make_upload_tokens_and_a_new_one_is_shown_once(): void
    {
        $admin = User::create(['email' => 'admin@example.com', 'role_id' => Role::superAdmin()->id, 'is_active' => true]);

        $this->actingAs($this->regina)->post(route('settings.call-recordings.token', $this->regina))->assertForbidden();

        $this->actingAs($admin)->post(route('settings.call-recordings.token', $this->regina))
            ->assertRedirect(route('settings.call-recordings.index'))
            ->assertSessionHas('new_token', fn (array $new) => $new['cra'] === 'Regina'
                && User::findByRecordingToken($new['token'])?->is($this->regina));

        // Not a CRA: no token.
        $this->actingAs($admin)->post(route('settings.call-recordings.token', $admin))->assertNotFound();
        $this->actingAs($admin)->get(route('settings.call-recordings.index'))->assertOk()->assertSee('Connect again')->assertSee(route('api.call-recordings.store'));
    }
}
