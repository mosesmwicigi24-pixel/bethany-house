<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourseQuestion extends Model
{
    protected $fillable = ['course_id', 'position', 'question', 'options', 'answer'];
    protected $casts = ['options' => 'array', 'answer' => 'integer'];
}
