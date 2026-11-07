<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_renewals', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->string('note');
        });

        Schema::create('workspace_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('package_key');
            $table->unsignedInteger('token_limit')->nullable();
        });

        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->nullable();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('token_limit')->nullable();
            $table->timestamps();
        });

        Schema::create('suites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id');
            $table->string('name');
        });

        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id');
            $table->string('name');
            $table->unsignedInteger('logins')->default(0);
        });

        Schema::create('member_group_member', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id');
            $table->foreignId('member_group_id');
        });

        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        Schema::create('admin_workspace', function (Blueprint $table) {
            $table->foreignId('admin_id');
            $table->foreignId('workspace_id');
        });

        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id');
            $table->string('name');
            $table->integer('sort')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('template_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id');
            $table->foreignId('member_id')->nullable();
            $table->foreignId('member_group_id')->nullable();
        });

        Schema::create('texts', function (Blueprint $table) {
            $table->string('checksum')->primary();
            $table->string('content');
        });

        Schema::create('text_acceptances', function (Blueprint $table) {
            $table->id();
            $table->string('checksum');
            $table->foreignId('member_id');
        });

        Schema::create('elements', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        Schema::create('text_translations', function (Blueprint $table) {
            $table->id();
            $table->string('checksum');
            $table->string('locale');
            $table->string('translatable_type')->nullable();
            $table->unsignedBigInteger('translatable_id')->nullable();
            $table->string('content');
        });

        Schema::create('provider_settings', function (Blueprint $table) {
            $table->uuid()->primary();
            $table->foreignId('workspace_id');
            $table->string('provider');
            $table->timestamp('quota_exhausted_at')->nullable();
        });

        Schema::create('automations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id');
            $table->string('name');
        });

        Schema::create('automation_trigger_events', function (Blueprint $table) {
            $table->foreignId('automation_id')->constrained('automations')->cascadeOnDelete();
            $table->string('event');
            $table->primary(['automation_id', 'event']);
        });

        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_id')->constrained('automations')->cascadeOnDelete();
            $table->string('trigger');
        });

        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('code');
        });

        Schema::create('workspace_languages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id');
            $table->foreignId('language_id');
            $table->boolean('is_default')->default(false);
        });

        Schema::create('feature_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id');
            $table->foreignId('member_id');
            $table->string('feature');
            $table->unsignedInteger('count')->default(0);
            $table->unique(['workspace_id', 'member_id', 'feature']);
        });

        Schema::create('prompts', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('body');
        });
    }

    public function down(): void
    {
        foreach ([
            'prompts', 'feature_metrics', 'workspace_languages', 'languages', 'automation_runs',
            'automation_trigger_events', 'automations', 'provider_settings', 'text_translations', 'elements', 'text_acceptances',
            'texts', 'template_permissions', 'templates', 'admin_workspace', 'admins', 'member_group_member',
            'members', 'suites', 'workspaces', 'workspace_groups', 'license_renewals',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
