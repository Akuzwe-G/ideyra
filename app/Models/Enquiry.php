<?php

namespace App\Models;

use Database\Factories\EnquiryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'email', 'category', 'subject', 'message', 'consented_at'])]
#[Hidden(['name', 'email', 'subject', 'message', 'mail_recipient'])]
class Enquiry extends Model
{
    /** @use HasFactory<EnquiryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'name' => 'encrypted', 'email' => 'encrypted', 'subject' => 'encrypted',
            'message' => 'encrypted', 'consented_at' => 'datetime',
            'mail_recipient' => 'encrypted',
            'mail_attempted_at' => 'datetime', 'mail_accepted_at' => 'datetime',
        ];
    }
}
