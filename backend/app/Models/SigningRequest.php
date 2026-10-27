<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SigningRequest extends Model
{
    public const KINDS = ['document', 'article', 'text'];
    protected $fillable = ['user_id', 'requested_by', 'kind', 'staff_document_id', 'article_id', 'title', 'body', 'due_at', 'status', 'signed_at', 'signature_path', 'signed_ip', 'source_hash', 'signed_document_id', 'note'];
    protected $casts = ['due_at' => 'datetime', 'signed_at' => 'datetime'];
    public function user()           { return $this->belongsTo(User::class); }
    public function requester()      { return $this->belongsTo(User::class, 'requested_by'); }
    public function document()       { return $this->belongsTo(StaffDocument::class, 'staff_document_id'); }
    public function article()        { return $this->belongsTo(Article::class); }
    public function signedDocument() { return $this->belongsTo(StaffDocument::class, 'signed_document_id'); }
}
