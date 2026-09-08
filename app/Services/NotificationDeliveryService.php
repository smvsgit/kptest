<?php

namespace App\Services;

use App\Jobs\DeliverNotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\PortalNotification;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class NotificationDeliveryService
{
    private const CATEGORY_BY_EVENT = [
        'access_request_created' => 'access',
        'access_request_decided' => 'access',
        'access_request_escalated' => 'access',
        'file_updated' => 'file',
        'broken_link_detected' => 'file',
        'storage_warning' => 'storage',
        'security_alert' => 'security',
        'backup_failed' => 'security',
        'backup_rpo_warning' => 'storage',
    ];

    public function sendEvent(User $user, string $event, string $title, string $message, array $data = []): array
    {
        $settings = SystemSetting::valueFor('notification.channels', []);
        $category = self::CATEGORY_BY_EVENT[$event] ?? 'file';
        $preferences = NotificationPreference::firstOrNew(['user_id' => $user->id]);
        $deliveries = [];

        foreach (['portal','email','whatsapp','sms'] as $channel) {
            if (!(bool)($settings[$channel]['enabled'] ?? ($channel === 'portal'))) continue;
            if (!(bool)($settings['events'][$event][$channel] ?? false)) continue;

            $delivery = NotificationDelivery::create([
                'user_id' => $user->id,
                'event' => $event,
                'category' => $category,
                'channel' => $channel,
                'provider' => $this->providerName($settings, $channel),
                'recipient' => $this->recipientFor($user, $channel),
                'status' => 'queued',
                'response_meta' => ['title'=>$title,'message'=>$message,'data'=>$data],
            ]);

            if (!$this->userAllows($preferences, $channel, $category)) {
                $delivery->update(['status'=>'skipped','error'=>'Disabled by user notification preference.']);
                $deliveries[] = $delivery;
                continue;
            }

            if ($channel === 'portal') {
                $notification = PortalNotification::create([
                    'user_id'=>$user->id,
                    'type'=>$event,
                    'title'=>$title,
                    'message'=>$message,
                    'data'=>$data ?: null,
                ]);
                $delivery->update([
                    'portal_notification_id'=>$notification->id,
                    'status'=>'sent','attempts'=>1,'last_attempt_at'=>now(),'sent_at'=>now(),
                    'recipient'=>'user:'.$user->id,
                ]);
            } elseif (!$delivery->recipient) {
                $delivery->update(['status'=>'skipped','error'=>$channel === 'email' ? 'User email is missing.' : 'User phone is missing.']);
            } else {
                DeliverNotificationChannel::dispatch($delivery->id);
            }
            $deliveries[] = $delivery->fresh();
        }

        return $deliveries;
    }

    public function queueTest(string $channel, string $recipient, User $actor): NotificationDelivery
    {
        $settings = SystemSetting::valueFor('notification.channels', []);
        if ($channel === 'portal') {
            $notification = PortalNotification::create([
                'user_id'=>$actor->id,'type'=>'test_notification','title'=>'Notification channel test',
                'message'=>'Portal notification test from Central Admin.','data'=>['test'=>true],
            ]);
            return NotificationDelivery::create([
                'portal_notification_id'=>$notification->id,'user_id'=>$actor->id,'event'=>'test_notification','category'=>'security',
                'channel'=>'portal','provider'=>'built-in','recipient'=>'user:'.$actor->id,'status'=>'sent','attempts'=>1,
                'last_attempt_at'=>now(),'sent_at'=>now(),'response_meta'=>['title'=>'Notification channel test','message'=>'Portal notification test from Central Admin.'],
            ]);
        }
        if (!(bool)($settings[$channel]['enabled'] ?? false)) throw new RuntimeException(ucfirst($channel).' channel is disabled.');
        $delivery = NotificationDelivery::create([
            'user_id'=>$actor->id,'event'=>'test_notification','category'=>'security','channel'=>$channel,
            'provider'=>$this->providerName($settings,$channel),'recipient'=>trim($recipient),'status'=>'queued',
            'response_meta'=>['title'=>'SMVS Karyalay Portal notification test','message'=>'This is a provider test from SMVS Karyalay Portal.','data'=>['test'=>true]],
        ]);
        DeliverNotificationChannel::dispatch($delivery->id);
        return $delivery;
    }

    public function deliver(NotificationDelivery $delivery): void
    {
        if ($delivery->status === 'sent' || $delivery->status === 'skipped') return;
        $settings = SystemSetting::valueFor('notification.channels', []);
        $payload = $delivery->response_meta ?? [];
        $title = (string)($payload['title'] ?? 'SMVS Karyalay Portal');
        $message = (string)($payload['message'] ?? 'Portal notification');
        $responseMeta = [];

        $delivery->forceFill([
            'status'=>'processing',
            'attempts'=>$delivery->attempts + 1,
            'last_attempt_at'=>now(),
            'error'=>null,
        ])->save();

        try {
            match ($delivery->channel) {
                'email' => $responseMeta = $this->sendEmail($settings, (string)$delivery->recipient, $title, $message),
                'whatsapp' => $responseMeta = $this->sendWhatsapp($settings, (string)$delivery->recipient, $message),
                'sms' => $responseMeta = $this->sendSms($settings, (string)$delivery->recipient, $message),
                default => throw new RuntimeException('Unsupported external notification channel.'),
            };
            $delivery->forceFill(['status'=>'sent','sent_at'=>now(),'response_meta'=>array_merge($payload,['provider_response'=>$responseMeta])])->save();
        } catch (\Throwable $e) {
            $delivery->forceFill(['status'=>'failed','error'=>mb_substr($e->getMessage(),0,4000)])->save();
            throw $e;
        }
    }

    private function sendEmail(array $settings, string $recipient, string $title, string $message): array
    {
        $email = $settings['email'] ?? [];
        if (($email['provider'] ?? 'smtp') !== 'smtp') throw new RuntimeException('Only SMTP email provider is configured by this release.');
        foreach (['host','port','from_address'] as $required) if (blank($email[$required] ?? null)) throw new RuntimeException("Email {$required} is not configured.");
        $password = !empty($email['password_encrypted']) ? Crypt::decryptString($email['password_encrypted']) : null;
        Config::set('mail.mailers.smtp.host', $email['host']);
        Config::set('mail.mailers.smtp.port', (int)$email['port']);
        Config::set('mail.mailers.smtp.encryption', ($email['encryption'] ?? 'tls') === 'none' ? null : ($email['encryption'] ?? 'tls'));
        Config::set('mail.mailers.smtp.username', $email['username'] ?: null);
        Config::set('mail.mailers.smtp.password', $password);
        Config::set('mail.from.address', $email['from_address']);
        Config::set('mail.from.name', $email['from_name'] ?? 'SMVS Karyalay Portal');
        $manager = app('mail.manager');
        if (method_exists($manager, 'forgetMailers')) $manager->forgetMailers();
        Mail::mailer('smtp')->raw($message, function ($mail) use ($recipient, $title, $email) {
            $mail->to($recipient)->subject($title)->from($email['from_address'], $email['from_name'] ?? 'SMVS Karyalay Portal');
        });
        return ['transport'=>'smtp','accepted'=>true];
    }

    private function sendWhatsapp(array $settings, string $recipient, string $message): array
    {
        $wa = $settings['whatsapp'] ?? [];
        $endpoint = trim((string)($wa['endpoint'] ?? ''));
        if ($endpoint === '') throw new RuntimeException('WhatsApp API endpoint is not configured.');
        $token = !empty($wa['token_encrypted']) ? Crypt::decryptString($wa['token_encrypted']) : null;
        if (!$token) throw new RuntimeException('WhatsApp access token is not configured.');

        if (($wa['provider'] ?? 'meta-cloud-api') === 'meta-cloud-api') {
            $response = Http::withToken($token)->acceptJson()->timeout(20)->post($endpoint, [
                'messaging_product'=>'whatsapp','to'=>$recipient,'type'=>'text','text'=>['body'=>$message],
            ]);
        } else {
            $response = Http::withToken($token)->acceptJson()->timeout(20)->post($endpoint, [
                'to'=>$recipient,'message'=>$message,'sender'=>$wa['sender'] ?? null,
            ]);
        }
        $response->throw();
        return ['status'=>$response->status(),'body'=>mb_substr($response->body(),0,1500)];
    }

    private function sendSms(array $settings, string $recipient, string $message): array
    {
        $sms = $settings['sms'] ?? [];
        $endpoint = trim((string)($sms['endpoint'] ?? ''));
        if ($endpoint === '') throw new RuntimeException('SMS API endpoint is not configured.');
        $key = !empty($sms['api_key_encrypted']) ? Crypt::decryptString($sms['api_key_encrypted']) : null;
        if (!$key) throw new RuntimeException('SMS API key is not configured.');
        $response = Http::withToken($key)->acceptJson()->timeout(20)->post($endpoint, [
            'to'=>$recipient,'message'=>$message,'sender_id'=>$sms['sender_id'] ?? null,
        ]);
        $response->throw();
        return ['status'=>$response->status(),'body'=>mb_substr($response->body(),0,1500)];
    }

    private function recipientFor(User $user, string $channel): ?string
    {
        return match ($channel) {
            'portal' => 'user:'.$user->id,
            'email' => $user->email ?: null,
            'whatsapp','sms' => $user->phone ?: null,
            default => null,
        };
    }

    private function providerName(array $settings, string $channel): string
    {
        return $channel === 'portal' ? 'built-in' : (string)($settings[$channel]['provider'] ?? 'configured-provider');
    }

    private function userAllows(NotificationPreference $preference, string $channel, string $category): bool
    {
        $channelField = $channel.'_enabled';
        $categoryField = $category.'_enabled';
        return (bool)($preference->{$channelField} ?? true) && (bool)($preference->{$categoryField} ?? true);
    }
}
