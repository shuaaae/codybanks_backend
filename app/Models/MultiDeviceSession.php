<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MultiDeviceSession extends Model
{
    use HasFactory;

    protected $table = 'active_team_sessions';
    
    protected $primaryKey = 'id';
    
    public $incrementing = true;
    
    public $timestamps = true;

    protected $fillable = [
        'user_id',
        'team_id',
        'session_id',
        'device_id',
        'device_name',
        'device_type',
        'browser_fingerprint',
        'ip_address',
        'user_agent',
        'last_activity'
    ];

    protected $casts = [
        'last_activity' => 'datetime',
    ];

    /**
     * Generate a unique device ID for the current device
     */
    public static function generateDeviceId(): string
    {
        return Str::random(32);
    }

    /**
     * Get or create a session for the current device
     */
    public static function getOrCreateSession($userId, $teamId, $sessionId, $deviceInfo = [])
    {
        $deviceId = $deviceInfo['device_id'] ?? self::generateDeviceId();
        
        return self::firstOrCreate(
            [
                'session_id' => $sessionId,
                'device_id' => $deviceId,
            ],
            [
                'user_id' => $userId,
                'team_id' => $teamId,
                'device_name' => $deviceInfo['device_name'] ?? 'Unknown Device',
                'device_type' => $deviceInfo['device_type'] ?? 'desktop',
                'browser_fingerprint' => $deviceInfo['browser_fingerprint'] ?? null,
                'ip_address' => $deviceInfo['ip_address'] ?? request()->ip(),
                'user_agent' => $deviceInfo['user_agent'] ?? request()->userAgent(),
                'last_activity' => now(),
            ]
        );
    }

    /**
     * Update the active team for a specific device session
     */
    public static function updateActiveTeam($userId, $teamId, $sessionId, $deviceInfo = [])
    {
        $deviceId = $deviceInfo['device_id'] ?? self::generateDeviceId();
        
        $session = self::where('session_id', $sessionId)
            ->where('device_id', $deviceId)
            ->where('user_id', $userId)
            ->first();

        if ($session) {
            $session->update([
                'team_id' => $teamId,
                'last_activity' => now(),
            ]);
        } else {
            self::getOrCreateSession($userId, $teamId, $sessionId, $deviceInfo);
        }

        return $session;
    }

    /**
     * Get active team for a specific device session
     */
    public static function getActiveTeamForDevice($userId, $sessionId, $deviceId)
    {
        $session = self::where('user_id', $userId)
            ->where('session_id', $sessionId)
            ->where('device_id', $deviceId)
            ->first();

        return $session ? $session->team_id : null;
    }

    /**
     * Get all active sessions for a user
     */
    public static function getUserActiveSessions($userId)
    {
        return self::where('user_id', $userId)
            ->where('last_activity', '>', now()->subHours(24)) // Only active sessions from last 24 hours
            ->orderBy('last_activity', 'desc')
            ->get();
    }

    /**
     * Clean up old sessions
     */
    public static function cleanupOldSessions($hours = 24)
    {
        return self::where('last_activity', '<', now()->subHours($hours))->delete();
    }

    /**
     * Get device type from user agent
     */
    public static function getDeviceTypeFromUserAgent($userAgent)
    {
        if (preg_match('/Mobile|Android|iPhone|iPad/', $userAgent)) {
            return 'mobile';
        } elseif (preg_match('/Tablet|iPad/', $userAgent)) {
            return 'tablet';
        }
        return 'desktop';
    }

    /**
     * Get device name from user agent
     */
    public static function getDeviceNameFromUserAgent($userAgent)
    {
        if (preg_match('/Windows NT/', $userAgent)) {
            return 'Windows PC';
        } elseif (preg_match('/Macintosh/', $userAgent)) {
            return 'Mac';
        } elseif (preg_match('/iPhone/', $userAgent)) {
            return 'iPhone';
        } elseif (preg_match('/Android/', $userAgent)) {
            return 'Android Device';
        } elseif (preg_match('/iPad/', $userAgent)) {
            return 'iPad';
        }
        return 'Unknown Device';
    }

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}