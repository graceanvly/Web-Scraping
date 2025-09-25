<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Bid extends Model
{
	use HasFactory;

	protected $fillable = [
		'url',
		'title',
		'end_date',
		'naics_code',
		'other_data',
		'raw_html',
		'extracted_json',
	];

	protected $casts = [
		'end_date' => 'datetime',
		'other_data' => 'array',
		'extracted_json' => 'array',
	];
}
