<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('links', function (Blueprint $table) {
            $table->id();

            // as_cs porque o gerador emite base 62: com a collation insensível
            // à caixa por omissão, `Ab3` e `ab3` colidiriam no índice único.
            $table->string('slug', 10)
                ->charset('utf8mb4')
                ->collation('utf8mb4_0900_as_cs')
                ->unique();

            $table->text('original_url');

            // Nulo para links anónimos; nullOnDelete para que apagar o utilizador
            // preserve o link.
            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('links');
    }
};
