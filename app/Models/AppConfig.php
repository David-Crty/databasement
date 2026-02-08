<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class AppConfig extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'value', 'type', 'is_sensitive'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_sensitive' => 'boolean',
        ];
    }

    /**
     * Get the value with proper type casting and decryption.
     */
    public function getCastedValue(): mixed
    {
        $value = $this->value;

        if ($value === null || $value === '') {
            return null;
        }

        if ($this->is_sensitive) {
            $value = Crypt::decryptString($value);
        }

        return match ($this->type) {
            'integer' => (int) $value,
            'boolean' => (bool) $value,
            default => $value,
        };
    }

    /**
     * Prepare a value for storage, encrypting if sensitive.
     */
    public static function prepareValue(mixed $value, bool $isSensitive): ?string
    {
        if ($value === null) {
            return null;
        }

        $stringValue = is_bool($value) ? ($value ? '1' : '0') : (string) $value;

        if ($isSensitive && $stringValue !== '') {
            return Crypt::encryptString($stringValue);
        }

        return $stringValue;
    }
}
