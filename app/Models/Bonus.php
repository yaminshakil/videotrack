<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Extra money the admin adds on top of what an employee earned from topics for a
 * month. It raises what they are owed rather than recording money handed over, so
 * it still has to be paid like any other earning.
 */
class Bonus extends Model
{
    protected $fillable = ['employee_id', 'period', 'amount', 'note'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
