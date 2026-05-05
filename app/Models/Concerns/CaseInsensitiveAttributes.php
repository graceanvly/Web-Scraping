<?php

namespace App\Models\Concerns;

/**
 * Makes Eloquent attribute access case-insensitive so models work
 * on both MySQL (case-insensitive columns) and Oracle (uppercase columns
 * that become case-sensitive when quoted by the OCI8 driver).
 *
 * Attributes are stored internally using the casing returned by the
 * database. Reads fall back to lowercase then uppercase if a key
 * isn't found. Writes target the existing attribute key regardless
 * of the casing used in code.
 */
trait CaseInsensitiveAttributes
{
    public function getAttribute($key)
    {
        $value = parent::getAttribute($key);

        if ($value === null && !array_key_exists($key, $this->attributes)) {
            $lower = strtolower($key);
            if ($lower !== $key && array_key_exists($lower, $this->attributes)) {
                return parent::getAttribute($lower);
            }
            $upper = strtoupper($key);
            if ($upper !== $key && array_key_exists($upper, $this->attributes)) {
                return parent::getAttribute($upper);
            }
        }

        return $value;
    }

    public function setAttribute($key, $value)
    {
        $lower = strtolower($key);
        $upper = strtoupper($key);

        if ($lower !== $key && array_key_exists($lower, $this->attributes)) {
            return parent::setAttribute($lower, $value);
        }
        if ($upper !== $key && array_key_exists($upper, $this->attributes)) {
            return parent::setAttribute($upper, $value);
        }

        return parent::setAttribute($key, $value);
    }
}
