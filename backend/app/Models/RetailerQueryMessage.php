<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One message in a retailer query thread (from the shop or from staff). */
class RetailerQueryMessage extends Model
{
    protected $fillable = ['retailer_query_id', 'author_type', 'author_id', 'author_name', 'body'];
}
