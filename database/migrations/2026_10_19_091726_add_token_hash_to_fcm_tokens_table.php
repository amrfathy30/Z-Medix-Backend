<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('fcm_tokens', function (Blueprint $table): void {
            $table->char('token_hash', 64)->nullable()->after('token');
            $table->index('device_id');
        });

        $this->backfillHashes();

        Schema::table('fcm_tokens', function (Blueprint $table): void {
            $table->char('token_hash', 64)->nullable(false)->change();
            $table->unique('token_hash', 'fcm_tokens_token_hash_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fcm_tokens', function (Blueprint $table): void {
            $table->dropUnique('fcm_tokens_token_hash_unique');
            $table->dropIndex(['device_id']);
            $table->dropColumn('token_hash');
        });
    }

    private function backfillHashes(): void
    {
        $retired = DB::table('fcm_tokens')
            ->select('token')
            ->groupBy('token')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('token')
            ->flatMap(function (string $token): array {
                $keepId = DB::table('fcm_tokens')->where('token', $token)->max('id');

                return DB::table('fcm_tokens')
                    ->where('token', $token)
                    ->where('id', '!=', $keepId)
                    ->pluck('id')
                    ->all();
            })
            ->flip();

        if ($retired->isNotEmpty()) {
            DB::table('fcm_tokens')
                ->whereIn('id', $retired->keys())
                ->update([
                    'is_active' => false,
                    'deleted_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        DB::table('fcm_tokens')
            ->select('id', 'token')
            ->orderBy('id')
            ->chunk(500, function ($rows) use ($retired): void {
                foreach ($rows as $row) {
                    $subject = $retired->has($row->id)
                        ? 'legacy-duplicate:'.$row->id.':'.$row->token
                        : (string) $row->token;

                    DB::table('fcm_tokens')
                        ->where('id', $row->id)
                        ->update(['token_hash' => hash('sha256', $subject)]);
                }
            });
    }
};
