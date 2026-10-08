<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackTemporaryMobileUsage;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TemporaryMobileUsageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('temporary_mobile_usage', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->dateTime('first_seen_at');
            $table->dateTime('last_seen_at');
        });
    }

    public function test_records_authenticated_user_and_throttles_updates(): void
    {
        $this->freezeTime();
        $firstSeen = now()->format('Y-m-d H:i:s');
        $this->trackUser(10);
        $this->assertDatabaseHas('temporary_mobile_usage', [
            'user_id' => 10,
            'first_seen_at' => $firstSeen,
            'last_seen_at' => $firstSeen,
        ]);

        $this->travel(4)->minutes();
        $this->trackUser(10);
        self::assertSame($firstSeen, DB::table('temporary_mobile_usage')->value('last_seen_at'));

        $this->travel(2)->minutes();
        $this->trackUser(10);
        $this->assertDatabaseCount('temporary_mobile_usage', 1);
        $this->assertDatabaseHas('temporary_mobile_usage', [
            'user_id' => 10,
            'first_seen_at' => $firstSeen,
            'last_seen_at' => now()->format('Y-m-d H:i:s'),
        ]);
    }

    public function test_guests_are_not_recorded(): void
    {
        $this->trackUser(null);
        $this->assertDatabaseCount('temporary_mobile_usage', 0);
    }

    public function test_missing_table_does_not_interrupt_request(): void
    {
        Schema::drop('temporary_mobile_usage');
        $this->trackUser(10);
    }

    public function test_tracking_runs_after_api_authentication(): void
    {
        $route = app('router')->getRoutes()->match(Request::create('/api/me', 'GET'));
        $middleware = $route->gatherMiddleware();
        self::assertContains('temporary.mobile.usage', $middleware);
        self::assertLessThan(
            array_search('temporary.mobile.usage', $middleware, true),
            array_search('auth:sanctum', $middleware, true)
        );
    }

    private function trackUser(?int $id): void
    {
        $request = Request::create('/api/me');
        $user = $id === null ? null : new User();
        if ($user) {
            $user->id = $id;
        }
        $request->setUserResolver(fn () => $user);

        $response = app(TrackTemporaryMobileUsage::class)->handle(
            $request,
            fn () => response()->json(['success' => true])
        );
        self::assertSame(200, $response->getStatusCode());
    }
}
