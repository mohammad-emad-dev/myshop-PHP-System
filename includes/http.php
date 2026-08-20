<?php

/**
 * Send a redirect response and terminate the current request.
 */
function http_redirect($url)
{
    header("Location: $url");
    exit();
}
