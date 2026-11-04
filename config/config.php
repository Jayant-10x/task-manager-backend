<?php

$environment = env('CODING_ENVIRONMENT');

$environmentFile = base_path("coding-environment/{$environment}.php");

if (!file_exists($environmentFile)) {
    throw new RuntimeException(
        "Coding environment file not found: {$environmentFile}"
    );
}

require_once $environmentFile;

return [];
