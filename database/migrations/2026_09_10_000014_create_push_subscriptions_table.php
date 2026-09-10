<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per browser that agreed to be interrupted.
 *
 * The sixth table, and the reason it has to be a table rather than a column on
 * `users`: a person signs in from a laptop and a phone, and each of those is a
 * separate push endpoint with its own key pair. A JSON column would work right
 * up until someone revokes on one device.
 *
 * `endpoint` is unique, and that is what makes re-subscribing idempotent. A
 * browser hands back the same endpoint every time until the user clears site
 * data, so an upsert on it keeps one row per device instead of a new row per
 * page load.
 *
 * `text`, not `varchar` — endpoints run to a few hundred characters and no
 * useful limit exists to guess at. Unlike `messages.body` this is **not**
 * encrypted: it is not conversation content, and the prune path has to find a
 * row by endpoint in SQL when a push service reports it gone. Same reasoning
 * that keeps `direct_key` readable.
 *
 * `user_agent` is only there so a person can tell their own devices apart if
 * a management screen is ever built. Nothing reads it today.
 *
 * When a native app arrives this grows additively — a nullable `platform` and
 * `device_token`, and `endpoint` relaxed to nullable. Adding a `platform`
 * column now, with exactly one possible value in it, would be structure
 * invented for a caller that does not exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint')->unique();
            $table->text('public_key');
            $table->text('auth_token');
            $table->text('user_agent')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
