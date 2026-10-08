<?php

namespace Tests\Feature;

use App\Http\Controllers\API\TasksPastController;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TasksPastScopeTest extends TestCase
{
    public static function permissionCases(): array
    {
        return [
            'personal access' => [[]],
            'global access' => [['tasks_view_all', 'tasks_write']],
        ];
    }

    #[DataProvider('permissionCases')]
    public function test_overdue_query_always_requires_user_participation(array $codes): void
    {
        $user = new User();
        $user->id = 10;
        $profile = new Profile();
        $profile->setRelation('permissions', collect(array_map(
            fn (string $code) => new Permission(['code' => $code]),
            $codes
        )));
        $user->setRelation('profile', $profile);
        $this->actingAs($user);

        // Capture the real Eloquent query without accessing application data.
        $queries = DB::pretend(function () {
            $response = app(TasksPastController::class)();
            self::assertSame(0, $response->getData(true)['count']);
        });

        self::assertCount(1, $queries);
        $sql = $queries[0]['query'];
        self::assertStringContainsString('("technician_id" = 10 or exists', $sql);
        self::assertStringContainsString('json_each("helping_user_ids")', $sql);
        self::assertStringContainsString('"json_each"."value" is 10', $sql);
        self::assertSame([10, 10], array_slice($queries[0]['bindings'], 0, 2));
        self::assertStringContainsString('"deployment_id" is null', $sql);
        self::assertStringContainsString('"task_date" <', $sql);
        self::assertStringContainsString('"status" not in', $sql);
        self::assertSame(["termin\u{00e9}e", "annul\u{00e9}e"], array_slice($queries[0]['bindings'], -2));
    }
}
