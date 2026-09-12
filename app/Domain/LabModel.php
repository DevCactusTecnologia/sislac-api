<?php

namespace App\Domain;

use Illuminate\Database\Eloquent\Model;

abstract class LabModel extends Model
{
    protected $connection = 'lab';
}
