<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourseProgress extends Model
{
    protected $table = 'course_progress';
    protected $fillable = ['course_id', 'user_id', 'lessons_done', 'started_at', 'attempts', 'best_score', 'passed', 'completed_at'];
    protected $casts = ['lessons_done' => 'array', 'started_at' => 'datetime', 'passed' => 'boolean', 'completed_at' => 'datetime'];
    public function course() { return $this->belongsTo(Course::class); }
    public function user()   { return $this->belongsTo(User::class); }
}
