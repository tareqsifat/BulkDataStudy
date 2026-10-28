<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    /**
     * @return HasMany<Salary, $this>
     */
    public function salaries(): HasMany
    {
        return $this->hasMany(Salary::class);
    }
}
