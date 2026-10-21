<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Passkeys (owner, 2026-10-21; App\Services\Auth\Passkeys).
 *
 * A passkey is a WebAuthn credential the person's device holds — Touch ID on
 * a Mac, Face ID on an iPhone, a security key — and the browser proves with
 * a signature. The hub uses it for STEP-UP ("Confirm it's you" before a
 * privileged action) ahead of the authenticator code. One row per credential;
 * `public_key` is the library's serialized credential source (public key,
 * aaguid, trust path), `sign_count` the authenticator's counter, checked on
 * every use against cloning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passkeys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 80);                       // "MacBook Touch ID", "iPhone"
            $table->string('credential_id', 512)->unique();  // base64url
            $table->text('public_key');                       // serialized PublicKeyCredentialSource
            $table->json('transports')->nullable();
            $table->unsignedBigInteger('sign_count')->default(0);
            $table->string('aaguid', 36)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passkeys');
    }
};
