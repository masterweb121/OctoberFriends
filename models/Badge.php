<?php namespace DMA\Friends\Models;

use Model;
use DMA\Friends\Models\Step;

/**
 * Badge Model
 */
class Badge extends Model
{

    /**
     * @var string The database table used by the model.
     */
    public $table = 'dma_friends_badges';

    /**
     * @var array Guarded fields
     */
    protected $guarded = ['*'];

    protected $dates = ['date_begin', 'date_end'];

    /**
     * @var array Fillable fields
     */
    protected $fillable = [];

    /**
     * @var array Relations
     */
    public $hasOne = [];
    public $hasMany = [
        'DMA\Friends\Models\Step'
    ];
    public $belongsTo = [];
    public $belongsToMany = [];
    public $morphTo = [];
    public $morphOne = [];
    public $morphMany = [];
    public $attachOne = [
        'image' => ['System\Models\File']
    ];
    public $attachMany = [];

    public function scopefindWordpress($query, $id)
    {   
        $query->where('wordpress_id', $id);
    }  

    public function steps()
    {
         return $this->hasMany('DMA\Friends\Models\Step');
    }
}
