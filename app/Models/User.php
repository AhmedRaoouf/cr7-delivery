<?php

namespace App\Models;

use App\Enums\Gender;
use App\Enums\Theme;
use App\Enums\UserType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected $fillable = [
        'name', 'phone', 'email', 'password', 'type', 'avatar', 'gender',
        'birth_date', 'locale', 'theme', 'is_active', 'must_change_password',
        'default_address_id',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'type' => UserType::class,
            'gender' => Gender::class,
            'theme' => Theme::class,
            'birth_date' => 'date',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'phone_verified_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'cash_only_until' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function defaultAddress(): BelongsTo
    {
        return $this->belongsTo(UserAddress::class, 'default_address_id');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(UserAddress::class);
    }

    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

    public function restaurant(): HasOne
    {
        return $this->hasOne(Restaurant::class);
    }

    public function driver(): HasOne
    {
        return $this->hasOne(Driver::class);
    }

    public function adminProfile(): HasOne
    {
        return $this->hasOne(AdminProfile::class);
    }

    public function adminScopes(): HasMany
    {
        return $this->hasMany(AdminScope::class);
    }

    public function cart(): HasOne
    {
        return $this->hasOne(Cart::class);
    }

    public function orderGroups(): HasMany
    {
        return $this->hasMany(OrderGroup::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(Rating::class);
    }

    public function complaints(): HasMany
    {
        return $this->hasMany(Complaint::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function isCustomer(): bool
    {
        return $this->type === UserType::Customer;
    }

    public function isDriver(): bool
    {
        return $this->type === UserType::Driver;
    }

    public function isRestaurant(): bool
    {
        return $this->type === UserType::Restaurant;
    }

    public function isAdmin(): bool
    {
        return $this->type === UserType::Admin;
    }
}
