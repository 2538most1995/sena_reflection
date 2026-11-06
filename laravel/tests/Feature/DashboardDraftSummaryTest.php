<?php

namespace Tests\Feature;

use App\Models\Tambon;
use App\Models\TeachingDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DashboardDraftSummaryTest extends TestCase
{
    use RefreshDatabase;

    private function teacher(): User
    {
        Http::preventStrayRequests();
        $this->withoutVite();
        Tambon::create(['id' => 'summary-own', 'name' => 'Summary Own']);

        return User::create(['id' => 'summary-teacher', 'username' => 'summary-teacher', 'name' => 'Summary Teacher', 'password' => 'fixture-only', 'role' => 'tambon', 'tambon' => 'summary-own']);
    }

    private function payload(array $rows): array
    {
        return ['scope' => ['tambon' => 'summary-own', 'year' => '2569', 'semester' => '2', 'week' => 1, 'date' => '2026-11-06'], 'enabled_level_codes' => ['1'], 'active_level_code' => '1', 'levels' => ['1' => [
            'subject' => 'Fixture Subject', 'class_group_code' => 'group', 'result' => 'Fixture Result', 'attendance_mode' => 'roster', 'attendance' => $rows,
        ]]];
    }

    private function draft(User $owner, array $payload, array $attributes = []): TeachingDraft
    {
        return TeachingDraft::create(['user_id' => $owner->id, 'tambon' => 'summary-own', 'year' => '2569', 'semester' => '2', 'week' => 1, 'payload' => $payload, 'last_saved_at' => '2026-11-06 00:00:00', ...$attributes]);
    }

    public function test_dashboard_filters_private_drafts_before_limiting_and_sends_compact_fresh_summaries(): void
    {
        $teacher = $this->teacher();
        $payload = $this->payload([['student_id' => 'S1', 'full_name' => 'private-attendance-name', 'group_code' => 'group', 'present' => false]]);
        $first = $this->draft($teacher, $payload);
        $second = $this->draft($teacher, $payload, ['last_saved_at' => '2026-11-06 00:01:00']);
        foreach (range(1, 8) as $index) {
            $this->draft($teacher, $payload, ['semester' => '1', 'last_saved_at' => '2026-11-06 01:00:00']);
        }
        $this->draft($teacher, $payload, ['status' => 'paused']);
        $other = User::create(['id' => 'summary-other', 'username' => 'summary-other', 'name' => 'Summary Other', 'password' => 'fixture-only', 'role' => 'tambon', 'tambon' => 'summary-own']);
        $foreign = $this->draft($other, $payload);

        $this->actingAs($teacher)->get(route('dashboard', ['year' => '2569', 'semester' => '2']))
            ->assertOk()->assertDontSee('private-attendance-name')->assertDontSee($foreign->id)
            ->assertInertia(fn (Assert $page) => $page->has('drafts', 2)->where('drafts.0.id', $second->id)->where('drafts.1.id', $first->id)
                ->where('drafts.0.scope.semester', '2')->where('drafts.0.progress.completed_levels', 1)->where('drafts.0.progress.total_levels', 1)->missing('drafts.0.payload'));
        $payload['levels']['1']['subject'] = '';
        $second->update(['payload' => $payload, 'lock_version' => 2]);
        $this->get(route('dashboard', ['year' => '2569', 'semester' => '2']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('drafts.0.progress.completed_levels', 0));
        $this->get(route('dashboard', ['scope' => 'all']))->assertOk()->assertInertia(fn (Assert $page) => $page->has('drafts', 6));
        Http::assertNothingSent();
    }

    #[DataProvider('rosterReadiness')]
    public function test_summary_readiness_keeps_normalization_validation_and_duplicate_identity_rules(array $rows, int $completed): void
    {
        $teacher = $this->teacher();
        $this->draft($teacher, $this->payload($rows));

        $this->actingAs($teacher)->getJson(route('teaching-drafts.index'))->assertOk()
            ->assertJsonPath('data.drafts.0.progress.completed_levels', $completed);
    }

    public static function rosterReadiness(): array
    {
        $valid = ['student_id' => 'S1', 'full_name' => 'Fixture Student', 'group_code' => 'group', 'present' => true, 'learning_method' => 'พบกลุ่ม'];

        return [
            'valid' => [[$valid], 1],
            'normalized legacy' => [[[...$valid, 'student_id' => ' S1 ', 'full_name' => ' Fixture Student ', 'group_code' => ' group ', 'learning_method' => ' ']], 1],
            'absent ignores obsolete method' => [[[...$valid, 'present' => false, 'learning_method' => 'obsolete']], 1],
            'duplicate trimmed identity' => [[$valid, [...$valid, 'student_id' => ' S1 ']], 0],
            'duplicate numeric identity keeps loose distinct rule' => [[[...$valid, 'student_id' => '01'], [...$valid, 'student_id' => '1']], 0],
            'blank identity' => [[[...$valid, 'student_id' => ' ']], 0],
            'identity too long' => [[[...$valid, 'student_id' => str_repeat('x', 51)]], 0],
            'name too long' => [[[...$valid, 'full_name' => str_repeat('ก', 256)]], 0],
            'blank name' => [[[...$valid, 'full_name' => ' ']], 0],
            'malformed scalar row' => [['broken'], 0],
            'array identity' => [[[...$valid, 'student_id' => ['S1']]], 0],
            'wrong group' => [[[...$valid, 'group_code' => 'other']], 0],
            'invalid method' => [[[...$valid, 'learning_method' => 'invalid']], 0],
            'empty roster' => [[], 0],
            'oversized roster' => [array_fill(0, 2001, $valid), 0],
        ];
    }
}
