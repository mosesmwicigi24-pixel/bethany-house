<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'value',
        'data_type',
        'description',
        'is_public',
    ];

    protected $casts = [
        'is_public' => 'boolean',
    ];

    public function getValueAttribute($value)
    {
        switch ($this->data_type) {
            case 'boolean':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);
            case 'integer':
                return (int) $value;
            case 'float':
                return (float) $value;
            case 'array':
            case 'json':
                return json_decode($value, true);
            default:
                return $value;
        }
    }

    public function setValueAttribute($value)
    {
        if (is_array($value)) {
            $this->attributes['value'] = json_encode($value);
            $this->attributes['data_type'] = 'json';
        } elseif (is_bool($value)) {
            $this->attributes['value'] = $value ? '1' : '0';
            $this->attributes['data_type'] = 'boolean';
        } else {
            $this->attributes['value'] = $value;
        }
    }

    public static function get($key, $default = null)
    {
        $setting = static::where('key', $key)->first();
        return $setting ? $setting->value : $default;
    }

    public static function set($key, $value, $description = null)
    {
        return static::updateOrCreate(
            ['key' => $key],
            [
                'value' => $value,
                'description' => $description,
            ]
        );
    }

    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }
}
