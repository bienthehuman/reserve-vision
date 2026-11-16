<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 4.2. `alt` is the owner's description of the photo (null: the pages say
 * "Photo N of <property>"). `width` and `height` are the full-size WebP's, set by
 * the resize job, so listing pages can reserve the space before the image loads.
 * `path` is now a stem without an extension: "<path>-thumb.webp" and "<path>-full.webp".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_photos', function (Blueprint $table) {
            $table->string('alt', 160)->nullable()->after('path');
            $table->unsignedSmallInteger('width')->nullable()->after('alt');
            $table->unsignedSmallInteger('height')->nullable()->after('width');
        });
    }

    public function down(): void
    {
        Schema::table('property_photos', function (Blueprint $table) {
            $table->dropColumn(['alt', 'width', 'height']);
        });
    }
};
