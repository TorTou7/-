<?php
/**
 * PHP compatibility shims for older runtimes.
 *
 * This file provides lightweight polyfills for select PHP functions that were
 * introduced after PHP 7.2. They are conditionally defined to avoid conflicts
 * with newer PHP versions or WordPress core polyfills.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// PHP 7.3: is_countable
if (!function_exists('is_countable')) {
    function is_countable($var) {
        return is_array($var) || $var instanceof Countable;
    }
}

// PHP 7.3: array_key_first
if (!function_exists('array_key_first')) {
    function array_key_first(array $array) {
        foreach ($array as $key => $unused) {
            return $key;
        }
        return null;
    }
}

// PHP 7.3: array_key_last
if (!function_exists('array_key_last')) {
    function array_key_last(array $array) {
        $key = null;
        foreach ($array as $k => $unused) {
            $key = $k;
        }
        return $key;
    }
}

// PHP 8.0: str_contains
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) {
        if ($needle === '') {
            return true;
        }
        return strpos($haystack, $needle) !== false;
    }
}

// PHP 8.0: str_starts_with
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

// PHP 8.0: str_ends_with
if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle) {
        if ($needle === '') {
            return true;
        }
        $needle_len = strlen($needle);
        return substr($haystack, -$needle_len) === $needle;
    }
}

