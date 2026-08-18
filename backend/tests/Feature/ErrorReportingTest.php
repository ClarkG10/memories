<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\AttachRequestId;
use App\Services\TimelineQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * A failure nobody can look up is a failure nobody can fix.
 *
 * Every response carries an id; the ones that went wrong on this side hand it
 * back in the body as well, and the same id is on the log line. Those three
 * facts are what turn "it broke" into something answerable.
 */
class ErrorReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_response_carries_an_id(): void
    {
        $response = $this->getJson('/api/archive')->assertOk();

        $id = $response->headers->get(AttachRequestId::HEADER);

        $this->assertIsString($id);
        $this->assertNotSame('', $id);
    }

    public function test_two_requests_are_told_apart(): void
    {
        $first = $this->getJson('/api/archive')->headers->get(AttachRequestId::HEADER);
        $second = $this->getJson('/api/archive')->headers->get(AttachRequestId::HEADER);

        $this->assertNotSame($first, $second);
    }

    public function test_a_failure_hands_back_the_reference_that_is_in_the_log(): void
    {
        // Something unforeseen, deep in a read path: exactly the shape of
        // failure that otherwise reaches the browser as a blank "went wrong".
        $this->app->bind(TimelineQuery::class, function (): TimelineQuery {
            throw new RuntimeException('the database fell over');
        });

        $logged = [];

        Log::listen(function ($message) use (&$logged): void {
            $logged[] = $message->context;
        });

        // The handler must stay in place: it is the thing under test.
        $response = $this->getJson('/api/timeline/years')->assertStatus(500);

        $header = $response->headers->get(AttachRequestId::HEADER);
        $reference = $response->json('reference');

        $this->assertIsString($reference);
        $this->assertSame($header, $reference, 'The reference shown must be the one on the header.');

        $references = array_column($logged, 'request_id');

        $this->assertContains(
            $reference,
            $references,
            'The reference the person was given does not appear on any log line.',
        );
    }

    public function test_a_validation_message_is_not_cluttered_with_a_reference(): void
    {
        $this->signedInOwner();

        // Nothing to look up: the message already says what to do about it.
        $this->postJson('/api/memories', [], ['Idempotency-Key' => 'k-1'])
            ->assertStatus(422)
            ->assertJsonMissingPath('reference');
    }
}
