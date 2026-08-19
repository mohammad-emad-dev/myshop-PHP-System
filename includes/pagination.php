<?php

/**
 * Normalizes user-facing list pagination without allowing unbounded limits.
 */
function normalize_page_number($page, $default = 1)
{
    $default = max(1, (int)$default);
    $value = is_scalar($page) ? filter_var($page, FILTER_VALIDATE_INT) : false;

    return $value === false || $value < 1 ? $default : (int)$value;
}

function normalize_page_size($page_size, $default = 25, $allowed_sizes = [10, 25, 50, 100])
{
    $default = (int)$default;
    $allowed_sizes = array_values(array_unique(array_map('intval', (array)$allowed_sizes)));
    if (empty($allowed_sizes)) {
        $allowed_sizes = [25];
    }
    if (!in_array($default, $allowed_sizes, true)) {
        $default = $allowed_sizes[0];
    }

    $value = is_scalar($page_size) ? filter_var($page_size, FILTER_VALIDATE_INT) : false;
    return $value !== false && in_array((int)$value, $allowed_sizes, true) ? (int)$value : $default;
}

function truncate_list_search($search, $max_length = 100)
{
    $search = is_scalar($search) ? trim((string)$search) : '';
    return function_exists('mb_substr')
        ? mb_substr($search, 0, $max_length, 'UTF-8')
        : substr($search, 0, $max_length);
}
