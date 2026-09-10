<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A timestamp, deliberately not a boolean.
 *
 * This app has no administrator — `ConversationRole` is per-group only, and
 * `users` carries no role at all. A permanent flag would have nobody able to
 * lift it, and the only way back would be SSH plus `tinker` at whatever hour
 * it happened. An expiry removes that problem rather than demanding a panel
 * that does not exist, and it makes a false positive an annoyance instead of
 * a lockout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('suspended_until')->nullable()->after('email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('suspended_until');
        });
    }
};
