<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chat Settings — Pin Chat, Alias and Clear Chat History are all per person per thread, so
 * they live on the participant row beside `is_muted`.
 *
 * `cleared_before_message_id` is a high-water mark rather than a delete: clearing history
 * hides everything up to that message for the caller only, and the other side keeps theirs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->boolean('is_pinned')->default(false)->after('is_muted');
            $table->timestamp('pinned_at')->nullable()->after('is_pinned');
            $table->string('alias', 50)->nullable()->after('pinned_at');
            $table->unsignedBigInteger('cleared_before_message_id')->nullable()->after('alias');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->dropColumn(['is_pinned', 'pinned_at', 'alias', 'cleared_before_message_id']);
        });
    }
};
