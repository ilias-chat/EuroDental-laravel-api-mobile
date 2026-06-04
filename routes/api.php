<?php

use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\NotificationController;
use App\Http\Controllers\API\LeaveRequestController;
use App\Http\Controllers\API\ProposedTaskController;
use App\Http\Controllers\API\ProductController;
use App\Http\Controllers\API\ServiceController;
use App\Http\Controllers\API\TaskController;
use App\Http\Controllers\API\TasksPastController;
use App\Http\Controllers\API\UsersListController;
use App\Http\Controllers\API\TasksRangeController;
use App\Http\Controllers\API\TasksTodayController;
use App\Http\Controllers\Mobile\ServicePropositionController;
use App\Http\Controllers\Mobile\TaskController as MobileTaskController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);

    // Notifications (Ionic app + API clients)
    Route::get('/notifications', [NotificationController::class, 'list']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'getUnreadCount']);
    Route::put('/notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);
    Route::get('/notifications/{id}', [NotificationController::class, 'show'])->whereNumber('id');
    Route::put('/notifications/{id}/read', [NotificationController::class, 'markAsRead'])->whereNumber('id');
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy'])->whereNumber('id');

    Route::get('/services', [ServiceController::class, 'getAll']);
    Route::get('/users', UsersListController::class);
    Route::get('/tasks/today', TasksTodayController::class);
    Route::get('/tasks/range', TasksRangeController::class);
    Route::get('/tasks/past', TasksPastController::class);
    Route::get('/catalog/products', [ProductController::class, 'index']);
    Route::get('/leave-requests', [LeaveRequestController::class, 'index']);
    Route::post('/leave-requests', [LeaveRequestController::class, 'store']);
    Route::put('/leave-requests/{id}', [LeaveRequestController::class, 'update'])->whereNumber('id');
    Route::post('/leave-requests/{id}/cancel', [LeaveRequestController::class, 'cancel'])->whereNumber('id');
    Route::delete('/leave-requests/{id}', [LeaveRequestController::class, 'destroy'])->whereNumber('id');
    Route::get('/proposed-tasks', [ProposedTaskController::class, 'index']);
    Route::post('/proposed-tasks', [ProposedTaskController::class, 'store']);
    Route::put('/proposed-tasks/{id}', [ProposedTaskController::class, 'update'])->whereNumber('id');
    Route::delete('/proposed-tasks/{id}', [ProposedTaskController::class, 'destroy'])->whereNumber('id');
    Route::get('/tasks/{id}', [TaskController::class, 'show'])->whereNumber('id');
    Route::get('/tasks/{task}/events', [MobileTaskController::class, 'getTaskEvents'])->whereNumber('task');
    Route::get('/tasks/{task}/user-last-event', [MobileTaskController::class, 'getUserLastEventForTask'])->whereNumber('task');
    Route::post('/tasks/{id}/update-description', [TaskController::class, 'updateDescription'])->whereNumber('id');

    Route::post('/tasks/{task}/start-route', [MobileTaskController::class, 'startRoute'])->whereNumber('task');
    Route::post('/tasks/{task}/end-route', [MobileTaskController::class, 'endRoute'])->whereNumber('task');
    Route::post('/tasks/{task}/start-visit', [MobileTaskController::class, 'startVisit'])->whereNumber('task');
    Route::post('/tasks/{task}/pause-visit', [MobileTaskController::class, 'pauseVisit'])->whereNumber('task');
    Route::post('/tasks/{task}/resume-visit', [MobileTaskController::class, 'resumeVisit'])->whereNumber('task');
    Route::post('/tasks/{task}/finish-visit', [MobileTaskController::class, 'finishVisit'])->whereNumber('task');
    Route::post('/tasks/{task}/finish', [MobileTaskController::class, 'finishTask'])->whereNumber('task');
    Route::post('/tasks/{task}/cancel', [MobileTaskController::class, 'cancelTask'])->whereNumber('task');
    Route::post('/tasks/{task}/payment', [MobileTaskController::class, 'updatePayment'])->whereNumber('task');
    Route::post('/tasks/{task}/admin-delivery-payment', [MobileTaskController::class, 'storeAdminDeliveryPayment'])->whereNumber('task');
    Route::post('/tasks/{task}/services', [MobileTaskController::class, 'updateServices'])->whereNumber('task');
    Route::post('/tasks/{task}/propose-service', [ServicePropositionController::class, 'store'])->whereNumber('task');
});
