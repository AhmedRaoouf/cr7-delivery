<?php

namespace App\Models;

use App\Enums\Gender;
use App\Enums\Theme;
use App\Enums\UserType;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
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
        'default_address_id', 'notify_orders', 'notify_captain', 'notify_promos',
        'blocked_at', 'blocked_reason', 'blocked_by',
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
            'blocked_at' => 'datetime',
            'must_change_password' => 'boolean',
            'notify_orders' => 'boolean',
            'notify_captain' => 'boolean',
            'notify_promos' => 'boolean',
            'phone_verified_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'cash_only_until' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * D-71 — one answer to «can this account still act?», read on every request.
     *
     * `is_active` has existed since the first migration and was read in exactly two
     * places, both of them logins. An operator who suspended an account therefore
     * suspended nothing until that account's token happened to expire: the phone in
     * the blocked captain's hand kept accepting orders. `EnsureAccountIsActive` is
     * the middleware; this is the question it asks.
     */
    public function isBlocked(): bool
    {
        return ! $this->is_active;
    }

    public function blockedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'blocked_by');
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

    /**
     * Apple / Google identities attached to this account — at most one per provider,
     * enforced by the unique index rather than by anything that reads this.
     */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
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

    /**
     * The basket the customer is shopping in.
     *
     * Scoped past scratch carts on purpose: releasing a scheduled order rebuilds its
     * basket in a throwaway cart row belonging to the same customer, and a `hasOne` with
     * no ordering would sometimes hand that one back — a customer's live basket
     * replaced, at random, by an order that was placed for them.
     */
    public function cart(): HasOne
    {
        return $this->hasOne(Cart::class)->where('is_scratch', false);
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

    /**
     * The one way to find a user from something they typed into a `login` box.
     *
     * Sign-in, forgot-password and reset each rebuilt this by hand and each compared the
     * raw string, so a phone written `+20 109 000 9599` matched no row and the account
     * answered «بيانات الدخول غير صحيحة» to its own correct password. Normalising here
     * means a lookup cannot be written without it.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWhereLogin(Builder $query, string $login): void
    {
        $query->where(
            PhoneNumber::isEmail($login) ? 'email' : 'phone',
            PhoneNumber::normaliseLogin($login),
        );
    }
}
