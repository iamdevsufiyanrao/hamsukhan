<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;

/**
 * A listen-only sample voice for the landing page. The file lives on the private disk and is only
 * served scrambled through a short-lived signed URL (see VoiceSampleController::play).
 */
class VoiceSample extends Model
{
    protected $fillable = ['name', 'label', 'language', 'path', 'sort', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** XOR key for this sample (hex). Not a secret worth protecting, just enough that the raw response isn't a playable file. */
    public function scrambleKey(): string
    {
        return substr(hash_hmac('sha256', $this->id.'|'.$this->path, (string) config('app.key')), 0, 32);
    }

    /** Shape sent to the browser: a signed URL that expires, plus the key to unscramble it. */
    public function forPlayer(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'label' => $this->label,
            'language' => $this->language,
            'src' => URL::temporarySignedRoute('voice-samples.play', now()->addMinutes(30), ['sample' => $this->id]),
            'key' => $this->scrambleKey(),
        ];
    }
}
