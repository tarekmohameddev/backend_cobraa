<?php
declare(strict_types=1);

namespace App\Traits;

use App\Helpers\ResponseError;
use App\Models\Blog;
use App\Models\BlogTranslation;
use App\Models\Language;
use App\Models\NotificationUser;
use App\Models\Order;
use App\Models\ParcelOrder;
use App\Models\PushNotification;
use App\Models\Settings;
use App\Models\Translation;
use App\Models\User;
use App\Services\PushNotificationService\PushNotificationService;
use Cache;
use Google\Client;
use Google\Exception;
use Illuminate\Support\Facades\Http;
use Throwable;

trait Notification
{

    /**
     * @return string
     * @throws Exception
     */
    private function updateToken(): string
    {
        $googleClient = new Client;
        $googleClient->setAuthConfig(storage_path('app/google-service-account.json'));
        $googleClient->addScope('https://www.googleapis.com/auth/firebase.messaging');

        $token = $googleClient->fetchAccessTokenWithAssertion()['access_token'];

        return Cache::remember('firebase_auth_token', 300, fn() => $token);
    }

    private function projectId()
    {
        return Settings::where('key', 'project_id')->value('value');
    }

    public function sendNotification(
        mixed $model,
        array|null $receivers = [],
        string|int|null $message = '',
        string|int|null $title = null,
        mixed $data = [],
        array $userIds = [],
    ): void
    {

        dispatch(function () use ($model, $receivers, $message, $title, $data, $userIds) {

            if (is_array($userIds) && count($userIds) > 0) {
                (new PushNotificationService)->storeMany([
                    'type'  => (string)($data['type'] ?? @$data['order']['type']) ?? PushNotification::NEW_ORDER,
                    'title' => $title,
                    'body'  => $message,
                    'data'  => $data,
                ], $userIds, $model);
            }

            if (empty($receivers)) {
                return;
            }

            $url = "https://fcm.googleapis.com/v1/projects/{$this->projectId()}/messages:send";

            try {
                $token = $this->updateToken();
            } catch (Throwable) {
                return;
            }

            $headers = [
                'Authorization' => "Bearer $token",
                'Content-Type'  => 'application/json'
            ];

            foreach ($receivers as $receiver) {

                Http::withHeaders($headers)->post($url, [
                    'message' => [
                        'token' => $receiver,
                        'notification' => [
                            'title' => $title,
                            'body' 	=> $message,
                        ],
                        'data' => [
                            'id'     => (string)$data['id'] 	?? '',
                            'status' => (string)$data['status'] ?? '',
                            'type'   => (string)$data['type'] 	?? ''
                        ],
                        'android' => [
                            'notification' => [
                                'sound' => 'default',
                            ]
                        ],
                        'apns' => [
                            'payload' => [
                                'aps' => [
                                    'sound' => 'default'
                                ]
                            ]
                        ]
                    ]
                ]);

            }

        })->afterResponse();

    }

    public function sendAllNotification(
        Blog $model,
        mixed $data = [],
    ): void
    {
        User::select([
            'id',
            'active',
            'email_verified_at',
            'phone_verified_at',
            'firebase_token',
            'lang',
        ])
            ->where('active', 1)
            ->where(function ($query) {
                $query
                    ->whereNotNull('email_verified_at')
                    ->orWhereNotNull('phone_verified_at');
            })
            ->whereNotNull('firebase_token')
            ->orderBy('id')
            ->chunk(100, function ($users) use ($model, $data) {

                foreach ($users as $user) {
                    /** @var User $user */

                    if (!isset($this->language)) {
                        $locale = Language::where('default', 1)->first()?->locale;

                        $this->language = $locale;
                    }

                    $translation = $model->translations
                        ?->where('locale', $user->lang ?? $this->language)
                        ?->first();

                    if (empty($translation)) {
                        $translation = $model->translations?->first();
                    }

                    /** @var BlogTranslation $translation */
                    $this->sendNotification(
                        $model,
                        $user->firebase_token,
                        $translation?->short_desc,
                        $translation?->title,
                        $data,
                        [$user->id]
                    );
                }

            });
    }

    /**
     * @param array $result
     * @param string $class
     * @return void
     */
    public function adminNotify(array $result, string $class = Order::class): void
    {
        $admins = User::whereHas('roles', fn($q) => $q->where('name', 'admin') )
            ->whereNotNull('firebase_token')
            ->select(['id', 'lang', 'firebase_token'])
            ->get();

        if ($class === Order::class) {

            foreach (data_get($result, 'data', []) as $order) {
                $this->sendUsers($order, $admins, $class);
            }

            return;
        }

        $this->sendUsers(data_get($result, 'data'), $admins, $class);
    }

    /**
     * @param Order|ParcelOrder $order
     * @param $users
     * @param string $class
     * @return void
     */
    private function sendUsers(Order|ParcelOrder $order, $users, string $class = Order::class): void
    {
        $type       = PushNotification::NEW_ORDER;
        $messageKey = ResponseError::NEW_ORDER;

        if ($class === ParcelOrder::class) {
            $type       = PushNotification::NEW_PARCEL_ORDER;
            $messageKey = ResponseError::NEW_PARCEL_ORDER;
        }

        if (!isset($this->language)) {
            $locale = Language::where('default', 1)->first()?->locale;

            $this->language = $locale;
        }

        foreach ($users as $user) {

            if (empty($user)) {
                continue;
            }

            /** @var User $user */
            $this->sendNotification(
                $order,
                $user->firebase_token ?? [],
                __("errors.$messageKey", ['id' => $order->id], $user->lang ?? $this->language),
                __("errors.$messageKey", ['id' => $order->id], $user->lang ?? $this->language),
                [
                    'id'     => $order->id,
                    'status' => $order->status,
                    'type'   => $type
                ],
                [$user->id]
            );

        }

    }

    /**
     * @param Order|ParcelOrder $order
     * @param bool $isDelivery
     * @return void
     */
    public function statusUpdateNotify(Order|ParcelOrder $order, bool $isDelivery): void
    {
        $order->loadMissing([
            'user.notifications.notification',
            'deliveryman',
        ]);

        $notification = $order->user?->notifications->where('type', \App\Models\Notification::PUSH)?->first();

        $default = Language::where('default', 1)->first()?->locale;

        if (!isset($this->language)) {
            $this->language = $default;
        }

        /** @var NotificationUser $notification */
        if ($order->user?->id && $notification?->notification?->active) {

            $tStatus = Translation::where(function ($q) use ($order, $default) {
                $q->where('locale', $order->user->lang ?? $this->language)->orWhere('locale', $default);
            })
                ->where('key', $order->status)
                ->first()
                ?->value;

            $title = __(
                'errors.' . ResponseError::STATUS_CHANGED,
                ['status' => !empty($tStatus) ? $tStatus : $order->status, 'id' => $order->id],
                $order->user->lang ?? $this->language
            );

            $this->sendNotification(
                $order,
                $order->user->firebase_token ?? [],
                $title,
                $title,
                [
                    'id'     => $order->id,
                    'status' => $order->status,
                    'type'   => PushNotification::STATUS_CHANGED
                ],
                [$order->user->id]
            );
        }

        if (!$isDelivery && $order->deliveryman?->id) {

            $tStatus = Translation::where(function ($q) use ($order, $default) {
                $q->where('locale', $order->deliveryman->lang ?? $this->language)->orWhere('locale', $default);
            })
                ->where('key', $order->status)
                ->first()
                ?->value;

            $title = __(
                'errors.' . ResponseError::STATUS_CHANGED,
                ['status' => !empty($tStatus) ? $tStatus : $order->status, 'id' => $order->id],
                $order->deliveryman->lang ?? $this->language
            );

            $this->sendNotification(
                $order,
                $order->deliveryman->firebase_token ?? [],
                $title,
                $title,
                [
                    'id'     => $order->id,
                    'status' => $order->status,
                    'type'   => PushNotification::STATUS_CHANGED
                ],
                [$order->deliveryman->id]
            );

        }

    }
}
