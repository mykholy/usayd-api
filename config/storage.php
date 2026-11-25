<?php

return [
    // Existing recitation namespace has an explicit owner segment.
    // Register additional namespaces only when their writer enforces the same ownership invariant.
    'owned_namespaces' => ['recitations'],
    'signed_disks' => ['private', 's3_private'],
];
