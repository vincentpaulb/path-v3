<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PublicProjectProfile extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['approved' => 'boolean'];
    }

    public function proposal() { return $this->belongsTo(Proposal::class); }

    public static function coordinates(Proposal $proposal): string
    {
        return $proposal->latitude.','.$proposal->longitude;
    }
}
