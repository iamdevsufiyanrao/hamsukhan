<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactMessage extends Model
{
    public const TOPICS = ['sales' => 'Sales question', 'support' => 'Help with my account', 'partnership' => 'Partnership', 'other' => 'Something else'];

    protected $fillable = ['name', 'email', 'business', 'topic', 'message', 'status', 'ip'];

    public function topicLabel(): string
    {
        return self::TOPICS[$this->topic] ?? ucfirst($this->topic);
    }
}
