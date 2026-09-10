<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make a leaked database stop being a leaked conversation.
 *
 * Two things happen here and the order matters. `conversations.name` is
 * varchar(255) and a ten-character group name encrypts to 228 characters, so
 * a sixty-character one — the length the UI allows — would overflow. The
 * column is widened before a single row is touched.
 *
 * Everything reads and writes through `DB::table()`, never Eloquent: the
 * models already carry the `encrypted` cast by the time this runs, and asking
 * Eloquent to read a plaintext row would throw on the way out.
 *
 * What this does NOT hide is worth writing down. `direct_key` is a unique
 * index built from two user ids and has to stay searchable, so who spoke to
 * whom, and when, survives a leak. And losing APP_KEY destroys every message
 * permanently — it needs a backup that lives somewhere the database backup
 * does not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->text('name')->nullable()->change();
        });

        $this->convert('messages', 'body', encrypt: true);
        $this->convert('conversations', 'name', encrypt: true);
    }

    public function down(): void
    {
        $this->convert('messages', 'body', encrypt: false);
        $this->convert('conversations', 'name', encrypt: false);

        Schema::table('conversations', function (Blueprint $table) {
            $table->string('name', 255)->nullable()->change();
        });
    }

    /**
     * Each value is probed before it is touched, so running this twice cannot
     * double-encrypt and rolling back twice cannot mangle plaintext.
     */
    private function convert(string $table, string $column, bool $encrypt): void
    {
        DB::table($table)
            ->whereNotNull($column)
            ->orderBy('id')
            ->each(function (object $row) use ($table, $column, $encrypt) {
                $value = $row->{$column};

                if ($this->isEncrypted($value) === $encrypt) {
                    return;
                }

                DB::table($table)->where('id', $row->id)->update([
                    $column => $encrypt ? Crypt::encryptString($value) : Crypt::decryptString($value),
                ]);
            });
    }

    private function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
};
