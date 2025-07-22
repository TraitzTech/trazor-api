<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, HasUuids, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'location',
        'phone',
        'avatar',
        'bio',
        'is_active',
        'last_login',
        'password',
        'device_token', // Added for device token management
    ];

    public function admin()
    {
        return $this->hasOne(Admin::class);
    }

    public function intern()
    {
        return $this->hasOne(Intern::class);
    }

    public function supervisor()
    {
        return $this->hasOne(Supervisor::class);
    }

    public function settings()
    {
        return $this->hasOne(UserSetting::class);
    }

    public function activities()
    {
        return $this->hasMany(UserActivity::class);
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected static function booted()
    {
        static::created(function ($user) {
            $user->settings()->create([
                'email_notifications' => true,
                'profile_public' => true,
                'two_factor_auth' => false,
            ]);
        });
    }
}
