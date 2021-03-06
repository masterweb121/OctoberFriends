<?php namespace DMA\Friends\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

class CreateLocationsTable extends Migration
{

    public function up()
    {
        Schema::create('dma_friends_locations', function($table)
        {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->timestamps();
            $table->integer('wordpress_id')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('printer_reward')->nullable();
            $table->string('printer_membership')->nullable();
        });
    }

    public function down()
    {
        Schema::dropIfExists('dma_friends_locations');
    }

}
