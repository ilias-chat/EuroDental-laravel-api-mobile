<?php

namespace Tests\Unit;

use App\Models\Permission;
use App\Models\Profile;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskAccess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TaskAccessTest extends TestCase
{
    public static function permissionCases(): array
    {
        return [
            'read own only' => [[], false, false],
            'read all only' => [['tasks_view_all'], true, false],
            'write own only' => [['tasks_write'], false, true],
            'read and write all' => [['tasks_view_all', 'tasks_write'], true, true],
        ];
    }

    #[DataProvider('permissionCases')]
    public function test_view_scope_and_edit_rights_are_independent(
        array $codes,
        bool $canViewOther,
        bool $canEditOwn
    ): void {
        $user = new User();
        $user->id = 10;
        $profile = new Profile();
        $profile->setRelation('permissions', collect(array_map(
            fn (string $code) => new Permission(['code' => $code]),
            $codes
        )));
        $user->setRelation('profile', $profile);

        $own = new Task(['technician_id' => 10]);
        $other = new Task(['technician_id' => 20]);
        $helping = new Task(['technician_id' => 20, 'helping_user_ids' => [10]]);

        self::assertTrue(TaskAccess::canView($user, $own));
        self::assertTrue(TaskAccess::canView($user, $helping));
        self::assertSame($canViewOther, TaskAccess::canView($user, $other));
        self::assertSame($canEditOwn, TaskAccess::canEdit($user, $own));
        self::assertSame($canEditOwn && $canViewOther, TaskAccess::canEdit($user, $other));
        self::assertSame($canEditOwn && $canViewOther, TaskAccess::canEdit($user, $helping));
        self::assertSame($canEditOwn, TaskAccess::canWrite($user));
    }
}
