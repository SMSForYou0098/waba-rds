<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // `chatbots` table is created earlier (2024_03_02_054750_create_chatbots_table.php).
        // This migration should only add missing columns without trying to create
        // the table again (which causes `Duplicate table: chatbots`).
        Schema::table('chatbots', function (Blueprint $table) {
            if (!Schema::hasColumn('chatbots', 'user_id')) {
                // Nullable to avoid failing when adding to an existing table with data.
                $table->string('user_id')->nullable();
            }
            if (!Schema::hasColumn('chatbots', 'sr_no')) {
                $table->string('sr_no')->nullable();
            }
            if (!Schema::hasColumn('chatbots', 'ref_no')) {
                $table->string('ref_no')->nullable();
            }
            if (!Schema::hasColumn('chatbots', 'keyword')) {
                $table->string('keyword')->nullable();
            }
            if (!Schema::hasColumn('chatbots', 'chatbot_type')) {
                $table->string('chatbot_type')->nullable();
            }
            if (!Schema::hasColumn('chatbots', 'reply_template')) {
                $table->string('reply_template')->nullable();
            }
            if (!Schema::hasColumn('chatbots', 'custom_type')) {
                $table->string('custom_type')->nullable();
            }
            if (!Schema::hasColumn('chatbots', 'reply_text')) {
                $table->string('reply_text')->nullable();
            }
            if (!Schema::hasColumn('chatbots', 'external_url')) {
                $table->string('external_url')->nullable();
            }
            if (!Schema::hasColumn('chatbots', 'url_action_type')) {
                $table->string('url_action_type')->nullable();
            }
            if (!Schema::hasColumn('chatbots', 'url_text')) {
                $table->string('url_text')->nullable();
            }
            if (!Schema::hasColumn('chatbots', 'url_xml')) {
                $table->string('url_xml')->nullable();
            }
            if (!Schema::hasColumn('chatbots', 'url_res')) {
                $table->string('url_res')->nullable();
            }
            if (!Schema::hasColumn('chatbots', 'url_json_key')) {
                $table->string('url_json_key')->nullable();
            }

            if (!Schema::hasColumn('chatbots', 'json_true_key')) {
                $table->string('json_true_key')->nullable();
            }
            if (!Schema::hasColumn('chatbots', 'json_true_value')) {
                $table->string('json_true_value')->nullable();
            }
            if (!Schema::hasColumn('chatbots', 'json_true_outgoing_res')) {
                $table->string('json_true_outgoing_res')->nullable();
            }
            if (!Schema::hasColumn('chatbots', 'json_true_chatbot')) {
                $table->string('json_true_chatbot')->nullable();
            }
            if (!Schema::hasColumn('chatbots', 'json_true_template')) {
                $table->string('json_true_template')->nullable();
            }
            if (!Schema::hasColumn('chatbots', 'json_true_json_res')) {
                $table->string('json_true_json_res')->nullable();
            }
            if (!Schema::hasColumn('chatbots', 'json_true_custom_text')) {
                $table->string('json_true_custom_text')->nullable();
            }

            if (!Schema::hasColumn('chatbots', 'json_false_key')) {
                $table->string('json_false_key')->nullable();
            }
            if (!Schema::hasColumn('chatbots', 'json_false_value')) {
                $table->string('json_false_value')->nullable();
            }
            if (!Schema::hasColumn('chatbots', 'json_false_outgoing_res')) {
                $table->string('json_false_outgoing_res')->nullable();
            }
            if (!Schema::hasColumn('chatbots', 'json_false_chatbot')) {
                $table->string('json_false_chatbot')->nullable();
            }
            if (!Schema::hasColumn('chatbots', 'json_false_template')) {
                $table->string('json_false_template')->nullable();
            }
            if (!Schema::hasColumn('chatbots', 'json_false_json_res')) {
                $table->string('json_false_json_res')->nullable();
            }
            if (!Schema::hasColumn('chatbots', 'json_false_custom_text')) {
                $table->string('json_false_custom_text')->nullable();
            }

            if (!Schema::hasColumn('chatbots', 'status')) {
                $table->string('status')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chatbots', function (Blueprint $table) {
            $dropColumns = [
                'user_id',
                'sr_no',
                'ref_no',
                'keyword',
                'chatbot_type',
                'reply_template',
                'custom_type',
                'reply_text',
                'external_url',
                'url_action_type',
                'url_text',
                'url_xml',
                'url_res',
                'url_json_key',
                'json_true_key',
                'json_true_value',
                'json_true_outgoing_res',
                'json_true_chatbot',
                'json_true_template',
                'json_true_json_res',
                'json_true_custom_text',
                'json_false_key',
                'json_false_value',
                'json_false_outgoing_res',
                'json_false_chatbot',
                'json_false_template',
                'json_false_json_res',
                'json_false_custom_text',
                'status',
            ];

            foreach ($dropColumns as $column) {
                if (Schema::hasColumn('chatbots', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
