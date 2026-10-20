<?php

namespace App\Services\Notifications;

use App\Models\FcmToken;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Owns the one invariant that keeps push notifications private:
 *
 *   A user may hold MANY active devices, but a DEVICE may hold at most one
 *   active registration, and it belongs to whoever registered last.
 */
class DeviceTokenRegistrar
{
    /**
     * Registers (or re-registers) one device token for one owner and hands
     * that owner sole possession of the device.
     */
    public function register(
        Model $owner,
        string $token,
        ?string $deviceType = null,
        ?string $deviceId = null,
        ?string $appType = null,
    ): FcmToken {
        return DB::transaction(function () use ($owner, $token, $deviceType, $deviceId, $appType): FcmToken {
            $record = $this->claimToken($owner, $token, $deviceType, $deviceId, $appType);

            $this->retireOtherRegistrationsForDevice($deviceId, $record);

            return $record;
        });
    }

    /**
     * Stands a token row up under the current owner, whatever state it was in.
     */
    private function claimToken(
        Model $owner,
        string $token,
        ?string $deviceType,
        ?string $deviceId,
        ?string $appType,
    ): FcmToken {
        $hash = FcmToken::hashFor($token);

        $record = FcmToken::withTrashed()
            ->where('token_hash', $hash)
            ->lockForUpdate()
            ->first();

        if ($record === null) {
            try {
                return FcmToken::create($this->attributes($owner, $token, $deviceType, $deviceId, $appType));
            } catch (QueryException $e) {
                $record = FcmToken::withTrashed()
                    ->where('token_hash', $hash)
                    ->lockForUpdate()
                    ->first();

                if ($record === null) {
                    throw $e;
                }
            }
        }

        if ($record->trashed()) {
            $record->restore();
        }

        $record->fill($this->attributes($owner, $token, $deviceType, $deviceId, $appType));
        $record->save();

        return $record;
    }

    /**
     * Enforces one active registration per device.
     */
    private function retireOtherRegistrationsForDevice(?string $deviceId, FcmToken $keep): void
    {
        if ($deviceId === null || $deviceId === '') {
            return;
        }

        FcmToken::query()
            ->where('device_id', $deviceId)
            ->whereKeyNot($keep->getKey())
            ->where('is_active', true)
            ->update(['is_active' => false]);
    }

    /**
     * Deactivates only the given owner's OWN registrations, matched by device
     * and/or token. Used by logout.
     */
    public function release(Model $owner, ?string $deviceId = null, ?string $token = null): int
    {
        $deviceId = $deviceId === '' ? null : $deviceId;
        $token = $token === '' ? null : $token;

        if ($deviceId === null && $token === null) {
            return 0;
        }

        return FcmToken::query()
            ->where('tokenable_type', $owner->getMorphClass())
            ->where('tokenable_id', $owner->getKey())
            ->where('is_active', true)
            ->where(function ($query) use ($deviceId, $token): void {
                if ($deviceId !== null) {
                    $query->orWhere('device_id', $deviceId);
                }

                if ($token !== null) {
                    $query->orWhere('token_hash', FcmToken::hashFor($token));
                }
            })
            ->update(['is_active' => false]);
    }

    /** @return array<string, mixed> */
    private function attributes(
        Model $owner,
        string $token,
        ?string $deviceType,
        ?string $deviceId,
        ?string $appType,
    ): array {
        return [
            'tokenable_type' => $owner->getMorphClass(),
            'tokenable_id' => $owner->getKey(),
            'token' => $token,
            'device_type' => $deviceType,
            'device_id' => $deviceId,
            'app_type' => $appType,
            'is_active' => true,
            'last_used_at' => now(),
        ];
    }
}
