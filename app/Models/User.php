<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'phone', 'locale', 'country', 'timezone', 'status', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

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

    /**
     * Determine if the user holds any of the given roles.
     *
     * @param  string|list<string>  $roles
     */
    public function hasRole(string|array $roles): bool
    {
        $roleList = is_array($roles) ? $roles : [$roles];

        return in_array($this->role, $roleList, true);
    }

    public function isLearner(): bool
    {
        return $this->role === 'learner';
    }

    public function isTeacher(): bool
    {
        return $this->role === 'teacher';
    }

    public function isAcademic(): bool
    {
        return $this->role === 'academic';
    }

    public function isSupport(): bool
    {
        return $this->role === 'support';
    }

    public function isFinance(): bool
    {
        return $this->role === 'finance';
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }
}
