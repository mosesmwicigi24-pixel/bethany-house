<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FormSubmission extends Model
{
    protected $fillable = ['form_template_id', 'outlet_id', 'user_id', 'for_date', 'status', 'due_at', 'answers', 'photos', 'signature_path', 'notes', 'skipped_required', 'flagged', 'submitted_at', 'managers_told_at'];
    protected $casts = ['for_date' => 'date:Y-m-d', 'due_at' => 'datetime', 'answers' => 'array', 'photos' => 'array', 'flagged' => 'boolean', 'submitted_at' => 'datetime', 'managers_told_at' => 'datetime'];

    public function template() { return $this->belongsTo(FormTemplate::class, 'form_template_id')->withTrashed(); } // a retired form still names its submissions
    public function outlet()   { return $this->belongsTo(Outlet::class); }
    public function user()     { return $this->belongsTo(User::class); }
}
