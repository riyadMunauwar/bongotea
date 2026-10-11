<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The catalogue: Categories, Brands, Tags and the Products sold under
     * them.
     *
     * A Product's price and MRP are in minor units. A soft delete takes a
     * Product out of the catalogue while its slug stays claimed — the unique
     * index covers trashed rows — and every Order Line that sold it keeps its own
     * snapshot. Categories and Brands are restricted rather than cascading:
     * neither can be removed from under a Product that names it.
     */
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('note')->nullable();
            $table->string('quality_headline')->nullable();
            $table->text('about')->nullable();
            $table->json('why_points')->nullable();
            $table->string('theme_key');
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->string('seo_image')->nullable();
            $table->timestamps();
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('logo')->nullable();
            $table->timestamps();
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->foreignId('brand_id')->constrained()->restrictOnDelete();
            $table->text('short_description')->nullable();
            $table->longText('description')->nullable();
            $table->unsignedBigInteger('price');
            $table->unsignedBigInteger('mrp');
            $table->unsignedTinyInteger('strength')->nullable();
            $table->string('liquor_colour')->nullable();
            $table->string('aroma')->nullable();
            $table->string('cups_per_kilogram')->nullable();
            $table->json('best_for')->nullable();
            $table->string('not_best_for')->nullable();
            $table->text('brewing_guide')->nullable();
            $table->json('business_tips')->nullable();
            $table->string('badge')->nullable();
            $table->text('search_keywords')->nullable();
            $table->string('status')->default('draft');
            $table->timestamp('first_published_at')->nullable();
            $table->boolean('is_available')->default(true);
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->string('seo_image')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('product_tag', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['product_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_tag');
        Schema::dropIfExists('products');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('categories');
    }
};
