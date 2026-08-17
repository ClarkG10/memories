<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DerivativePruneTest extends TestCase
{
    public function test_it_leaves_a_cache_that_is_under_its_ceiling_alone(): void
    {
        Storage::disk('derivatives')->put('one/w640.jpg', str_repeat('x', 1000));

        $this->artisan('derivatives:prune', ['--max-bytes' => 100_000])->assertSuccessful();

        $this->assertTrue(Storage::disk('derivatives')->exists('one/w640.jpg'));
    }

    public function test_it_trims_the_least_recently_touched_first(): void
    {
        $disk = Storage::disk('derivatives');

        foreach (['oldest', 'middle', 'newest'] as $name) {
            $disk->put("{$name}/w640.jpg", str_repeat('x', 1000));
        }

        // Age them apart so "least recently touched" is unambiguous.
        touch($disk->path('oldest/w640.jpg'), now()->subYear()->getTimestamp());
        touch($disk->path('middle/w640.jpg'), now()->subMonth()->getTimestamp());

        // Room for roughly one item.
        $this->artisan('derivatives:prune', ['--max-bytes' => 1200])->assertSuccessful();

        $this->assertFalse($disk->exists('oldest/w640.jpg'), 'The oldest should go first.');
        $this->assertFalse($disk->exists('middle/w640.jpg'));
        $this->assertTrue($disk->exists('newest/w640.jpg'), 'The most recent should survive.');
    }

    public function test_a_dry_run_removes_nothing(): void
    {
        Storage::disk('derivatives')->put('one/w640.jpg', str_repeat('x', 5000));

        $this->artisan('derivatives:prune', ['--max-bytes' => 10, '--dry-run' => true])
            ->assertSuccessful();

        $this->assertTrue(Storage::disk('derivatives')->exists('one/w640.jpg'));
    }

    public function test_originals_are_never_at_risk_because_only_the_cache_is_touched(): void
    {
        // Whatever this command does, it only ever reaches the derivatives
        // disk — the uploads staging area is a different disk entirely.
        Storage::disk('uploads')->put('session/original', 'irreplaceable');
        Storage::disk('derivatives')->put('one/w640.jpg', str_repeat('x', 5000));

        $this->artisan('derivatives:prune', ['--max-bytes' => 1])->assertSuccessful();

        $this->assertTrue(Storage::disk('uploads')->exists('session/original'));
    }
}
