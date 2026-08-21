<?php

declare(strict_types=1);

/**
 * Basic sanitization for general text inputs.
 */
function sanitize_input($data)
{
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

/**
 * Strict sanitization for email addresses.
 */
function sanitize_email($email)
{
    $email = trim($email);
    return filter_var($email, FILTER_SANITIZE_EMAIL);
}

/**
 * Strict sanitization for phone numbers (keeps only digits, +, and spaces).
 */
function sanitize_phone($phone)
{
    return preg_replace('/[^0-9+\s-]/', '', trim($phone));
}

/**
 * Strict sanitization for numeric IDs.
 */
function sanitize_id($id)
{
    return filter_var($id, FILTER_VALIDATE_INT) !== false ? (int)$id : 0;
}
