<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Chapter 51, Topic 9: the category and the routing decision for one message, and nothing the customer wrote.
class SensitiveRoutingRecord extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'sensitive_routings';

    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'message_id',
        'category',
        'subcategory',
        'decision',
        'source',
        'ticket_id',
    ];
}
