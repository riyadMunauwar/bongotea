<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            /**
             * The public handle: quoted on the confirmation call and the only
             * thing addressing the confirmation page. Random rather than
             * sequential so it neither leaks order volume nor invites walking
             * the range.
             */
            $table->string('order_number')->unique();

            $table->foreignId('customer_id')->constrained();

            /**
             * Attribution. `page_id` is the live link; `page_slug` is the
             * snapshot, so renaming a Page never rewrites which campaign past
             * orders belong to. Nullable because a force-deleted Page must not
             * take its orders down with it — the slug survives either way.
             */
            $table->foreignId('page_id')->nullable()->constrained()->nullOnDelete();
            $table->string('page_slug');

            /**
             * Agreed at Capture and fixed from then on. Held here rather than
             * read back through `customer_id` for the same reason the lines
             * snapshot their product: what was agreed is what gets collected.
             */
            $table->string('customer_name');
            $table->string('customer_phone');
            $table->text('customer_address');
            $table->string('customer_district');

            /**
             * Integer minor units, like every other money column. `total` is
             * `subtotal` + `delivery_charge` — the figure the courier collects
             * at the door — so no one does mental arithmetic per order.
             */
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('delivery_charge')->default(0);
            $table->unsignedBigInteger('total')->default(0);

            $table->string('status')->default('new');

            $table->timestamps();
            $table->softDeletes();

            /**
             * The fulfilment queue is "today's orders, newest first", and the
             * admin's only listed filter is a date range, so this is the one
             * access pattern worth an index beyond the keys above.
             */
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
